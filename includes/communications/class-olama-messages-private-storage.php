<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface Olama_Messages_Attachment_Storage_Interface {
    public function store( $source, $key );
    public function path( $key );
    public function exists( $key );
    public function size( $key );
    public function delete( $key );
}

class Olama_Messages_Local_Private_Storage implements Olama_Messages_Attachment_Storage_Interface {
    private $root;
    public function __construct() {
        $settings = Olama_Messages_Communication_Policy::settings();
        $configured = defined( 'OLAMA_MSG_PRIVATE_STORAGE_PATH' ) ? OLAMA_MSG_PRIVATE_STORAGE_PATH : ( $settings['private_storage_path'] ?: dirname( rtrim( ABSPATH, '/\\' ) ) . '/olama-private-communications' );
        $configured = rtrim( wp_normalize_path( $configured ), '/' );
        if ( ! preg_match( '~^(?:[a-zA-Z]:/|/)~', $configured ) || preg_match( '~(?:^|/)\.\.?(/|$)~', $configured ) ) { throw new RuntimeException( 'يجب ضبط مسار تخزين خاص مطلق وآمن.' ); }
        $parent = realpath( dirname( $configured ) );
        if ( ! $parent ) { throw new RuntimeException( 'المجلد الأب للتخزين الخاص غير موجود.' ); }
        $candidate = wp_normalize_path( $parent ) . '/' . basename( $configured );
        $this->outside_public( $candidate );
        if ( ! hash_equals( $this->canonical( $candidate ), $this->canonical( (string) ( $settings['private_storage_reviewed_path'] ?? '' ) ) ) ) { throw new RuntimeException( 'يلزم توثيق مراجعة مسار التخزين الخاص قبل إنشائه.' ); }
        if ( is_link( $configured ) ) { throw new RuntimeException( 'لا يسمح برابط رمزي لمجلد التخزين الخاص.' ); }
        if ( ! is_dir( $configured ) && ! wp_mkdir_p( $configured ) ) { throw new RuntimeException( 'تعذر إنشاء مجلد التخزين الخاص.' ); }
        $real = realpath( $configured );
        if ( ! $real ) { throw new RuntimeException( 'تعذر التحقق من التخزين الخاص.' ); }
        $this->root = rtrim( wp_normalize_path( $real ), '/' );
        $this->outside_public( $this->root );
        // A filesystem path alone cannot prove absence of nginx/Apache aliases.
        $reviewed = $settings['private_storage_reviewed_path'] ?? '';
        if ( ! hash_equals( $this->canonical( $this->root ), $this->canonical( (string) $reviewed ) ) ) { throw new RuntimeException( 'يلزم توثيق مراجعة وصول خادم الويب لهذا المسار الخاص قبل تفعيل المرفقات.' ); }
        if ( ! is_readable( $this->root ) || ! is_writable( $this->root ) ) { throw new RuntimeException( 'أذونات التخزين الخاص غير كافية.' ); }
    }
    private function canonical( $path ) { $path = rtrim( wp_normalize_path( $path ), '/' ); return '\\' === DIRECTORY_SEPARATOR ? strtolower( $path ) : $path; }
    private function outside_public( $path ) {
        $candidate = $this->canonical( $path );
        foreach ( array_filter( array( realpath( ABSPATH ), ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( $_SERVER['DOCUMENT_ROOT'] ) : false ) ) as $public ) {
            $public = $this->canonical( $public );
            if ( $candidate === $public || 0 === strpos( $candidate . '/', $public . '/' ) ) { throw new RuntimeException( 'التخزين الخاص يجب أن يكون خارج الجذر العام للموقع.' ); }
        }
    }
    public function path( $key ) {
        if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9-]{36}(?:-(?:thumb|preview))?\.[a-z0-9]{1,12}$/', $key ) ) { throw new RuntimeException( 'مفتاح تخزين غير صالح.' ); }
        $path = $this->root . '/' . $key;
        if ( is_link( $path ) ) { throw new RuntimeException( 'ملف التخزين غير آمن.' ); }
        if ( file_exists( $path ) && $this->canonical( dirname( realpath( $path ) ) ) !== $this->canonical( $this->root ) ) { throw new RuntimeException( 'ملف خارج التخزين الخاص.' ); }
        return $path;
    }
    public function store( $source, $key ) {
        $destination = $this->path( $key );
        $input = fopen( $source, 'rb' ); $output = fopen( $destination, 'xb' );
        if ( ! $input || ! $output ) { if ( is_resource( $input ) ) { fclose( $input ); } if ( is_resource( $output ) ) { fclose( $output ); } throw new RuntimeException( 'تعذر حفظ الملف الخاص.' ); }
        try {
            $written = stream_copy_to_stream( $input, $output );
            if ( false === $written || $written !== filesize( $source ) ) { throw new RuntimeException( 'لم يكتمل حفظ الملف.' ); }
        } catch ( Throwable $error ) { fclose( $input ); fclose( $output ); @unlink( $destination ); throw $error; }
        fclose( $input ); fclose( $output ); @chmod( $destination, 0640 ); return $key;
    }
    public function exists( $key ) { return is_file( $this->path( $key ) ); }
    public function size( $key ) { return $this->exists( $key ) ? filesize( $this->path( $key ) ) : 0; }
    public function delete( $key ) { $path = $this->path( $key ); if ( is_file( $path ) && ! unlink( $path ) ) { throw new RuntimeException( 'تعذر تنظيف ملف خاص.' ); } }
    public function root() { return $this->root; }
}

interface Olama_Messages_Malware_Scanner_Interface { public function scan( $path ); }

/** ClamAV INSTREAM, bounded local socket, no shell invocation or uploaded-path interpolation. */
class Olama_Messages_ClamAV_Scanner implements Olama_Messages_Malware_Scanner_Interface {
    public function scan( $path ) {
        $socket = defined( 'OLAMA_MSG_CLAMAV_SOCKET' ) ? OLAMA_MSG_CLAMAV_SOCKET : 'tcp://127.0.0.1:3310';
        if ( ! preg_match( '~^(tcp://(?:127\.0\.0\.1|localhost|\[::1\]):[0-9]{1,5}|unix:///[^\r\n]+)$~', $socket ) ) { return 'error'; }
        $stream = @stream_socket_client( $socket, $errno, $error, 0.5 );
        if ( ! $stream ) { return 'unavailable'; }
        stream_set_timeout( $stream, 15 );
        $file = fopen( $path, 'rb' );
        if ( ! $file ) { fclose( $stream ); return 'error'; }
        try {
            $this->write( $stream, "zINSTREAM\0" );
            while ( ! feof( $file ) ) { $chunk = fread( $file, 65536 ); if ( false === $chunk ) { throw new RuntimeException(); } $this->write( $stream, pack( 'N', strlen( $chunk ) ) . $chunk ); }
            $this->write( $stream, pack( 'N', 0 ) );
            $reply = stream_get_line( $stream, 4096, "\0" );
            if ( false !== strpos( (string) $reply, ' FOUND' ) ) { return 'infected'; }
            return preg_match( '/: OK$/', (string) $reply ) ? 'clean' : 'error';
        } catch ( Throwable $error ) { return 'error'; }
        finally { fclose( $file ); fclose( $stream ); }
    }
    private function write( $stream, $bytes ) {
        while ( '' !== $bytes ) { $count = fwrite( $stream, $bytes ); if ( ! $count ) { throw new RuntimeException(); } $bytes = substr( $bytes, $count ); }
    }
}
