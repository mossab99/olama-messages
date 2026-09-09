<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Attachment_Service {
    private function storage() { return new Olama_Messages_Local_Private_Storage(); }
    public function health() {
        $settings = Olama_Messages_Communication_Policy::settings();
        $result = array( 'enabled' => ! empty( $settings['attachments_enabled'] ), 'storage_ready' => false, 'scan_policy' => $settings['malware_policy'], 'legacy_office_enabled' => ! empty( $settings['legacy_office_enabled'] ), 'heic_supported' => false );
        try { $storage = $this->storage(); $result['storage_ready'] = true; $result['private_path'] = $storage->root(); }
        catch ( Throwable $error ) { $result['storage_error'] = $error->getMessage(); }
        $result['scanner'] = 'disabled' === $settings['malware_policy'] ? 'disabled' : 'checked_per_upload';
        return $result;
    }
    public function validate( $path, $name ) {
        $settings = Olama_Messages_Communication_Policy::settings();
        if ( ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) { throw new InvalidArgumentException( 'الملف غير قابل للقراءة.' ); }
        $name = sanitize_file_name( wp_basename( $name ) );
        if ( ! $name || mb_strlen( $name ) > 190 ) { throw new InvalidArgumentException( 'اسم الملف غير صالح.' ); }
        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, array( 'heic', 'heif' ), true ) ) { throw new InvalidArgumentException( 'صيغة HEIC غير مفعلة على هذا الخادم؛ استخدم JPEG أو PNG.' ); }
        $types = array( 'jpg' => array( 'image/jpeg' ), 'jpeg' => array( 'image/jpeg' ), 'png' => array( 'image/png' ), 'webp' => array( 'image/webp' ), 'pdf' => array( 'application/pdf' ), 'txt' => array( 'text/plain' ), 'csv' => array( 'text/plain', 'text/csv', 'application/csv' ), 'doc' => array( 'application/msword', 'application/x-ole-storage', 'application/CDFV2' ), 'xls' => array( 'application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2' ), 'ppt' => array( 'application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2' ), 'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip' ), 'xlsx' => array( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip' ), 'pptx' => array( 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip' ) );
        if ( ! isset( $types[$ext] ) ) { throw new InvalidArgumentException( 'نوع الملف غير مسموح. الفيديو والصوت والملفات التنفيذية والأرشيفات غير متاحة.' ); }
        $legacy = in_array( $ext, array( 'doc', 'xls', 'ppt' ), true );
        if ( $legacy && empty( $settings['legacy_office_enabled'] ) ) { throw new InvalidArgumentException( 'ملفات Office القديمة غير مفعلة؛ استخدم الصيغة الحديثة.' ); }
        $mime = ( new finfo( FILEINFO_MIME_TYPE ) )->file( $path );
        if ( ! in_array( $mime, $types[$ext], true ) ) { throw new InvalidArgumentException( 'محتوى الملف لا يطابق امتداده.' ); }
        $image = 0 === strpos( $mime, 'image/' );
        $limit = $image ? $settings['max_image_bytes'] : ( in_array( $ext, array( 'ppt', 'pptx' ), true ) ? $settings['max_presentation_bytes'] : $settings['max_document_bytes'] );
        $size = filesize( $path );
        if ( ! $size || $size > $limit ) { throw new InvalidArgumentException( 'حجم الملف يتجاوز الحد المسموح أو الملف فارغ.' ); }
        if ( $image ) {
            $dimensions = @getimagesize( $path );
            if ( ! $dimensions || $dimensions[0] * $dimensions[1] > 40000000 || $dimensions[0] > 20000 || $dimensions[1] > 20000 ) { throw new InvalidArgumentException( 'الصورة غير صالحة أو أبعادها كبيرة جداً.' ); }
        }
        if ( in_array( $ext, array( 'docx', 'xlsx', 'pptx' ), true ) ) {
            if ( ! class_exists( 'ZipArchive' ) ) { throw new RuntimeException( 'يلزم دعم ZIP للتحقق من ملفات Office الحديثة.' ); }
            $zip = new ZipArchive();
            if ( true !== $zip->open( $path ) ) { throw new InvalidArgumentException( 'ملف Office غير صالح.' ); }
            try {
                $entry = array( 'docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml' )[$ext];
                if ( false === $zip->locateName( '[Content_Types].xml' ) || false === $zip->locateName( $entry ) || $zip->numFiles > 10000 ) { throw new InvalidArgumentException( 'بنية ملف Office غير مطابقة.' ); }
                $expanded = 0;
                for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                    $item = $zip->statIndex( $i ); $expanded += $item['size'];
                    if ( $expanded > 100 * MB_IN_BYTES || preg_match( '~(?:^|/)\.\.(?:/|$)|vbaProject\.bin|\.(?:exe|dll|js|vbs|ps1|cmd|bat|com|hta)$~i', $item['name'] ) || ! empty( $item['encryption_method'] ) ) { throw new InvalidArgumentException( 'ملف Office يحتوي مكونات غير مسموحة أو بيانات مضغوطة كبيرة.' ); }
                }
            } finally { $zip->close(); }
            $mime = $types[$ext][0];
        }
        $scan = 'unavailable';
        if ( 'disabled' !== $settings['malware_policy'] || $legacy ) {
            $scanner = apply_filters( 'olama_messages_malware_scanner', new Olama_Messages_ClamAV_Scanner() );
            if ( ! $scanner instanceof Olama_Messages_Malware_Scanner_Interface ) { throw new RuntimeException( 'تعريف فاحص الملفات غير صالح.' ); }
            $scan = $scanner->scan( $path );
        }
        if ( ! in_array( $scan, array( 'clean', 'unavailable' ), true ) || ( 'clean' !== $scan && ( 'required' === $settings['malware_policy'] || $legacy ) ) ) { throw new RuntimeException( 'تعذر اعتماد سلامة الملف؛ راجع سياسة فحص الملفات أو أعد المحاولة.' ); }
        return array( 'original_name' => $name, 'extension' => $ext, 'mime_type' => $mime, 'size_bytes' => $size, 'sha256' => hash_file( 'sha256', $path ), 'scan_state' => $scan );
    }
    private function scope( array $actor, $type, $id ) {
        if ( 'thread' === $type ) {
            $chat = new Olama_Messages_Chat_Service(); $thread = $chat->get( $actor, $id ); $state = $chat->send_state( $actor, $thread );
            if ( ! $state['allowed'] ) { throw new RuntimeException( $state['reason'] ); }
            $target = $thread['requester_key'] ?: '';
            if ( 'direct' === $thread['kind'] ) { global $wpdb; $target = $wpdb->get_var( $wpdb->prepare( 'SELECT actor_key FROM ' . Olama_Messages_Communications_DB::table( 'thread_participants' ) . ' WHERE thread_id=%d AND actor_key<>%s LIMIT 1', $id, $actor['actor_key'] ) ); }
            elseif ( $target === $actor['actor_key'] ) { $target = 'service_inbox:' . $thread['inbox_id']; }
            Olama_Messages_Chat_Policy::restrictions( $actor, 'attachment', $thread, $target );
        } elseif ( 'campaign' === $type ) {
            Olama_Messages_Communication_Policy::require_manage( $actor );
            if ( $id && 'draft' !== ( new Olama_Messages_Internal_Campaign_Service() )->get( $id )['status'] ) { throw new RuntimeException( 'مرفقات الإعلان قابلة للتعديل في المسودة فقط.' ); }
        } elseif ( 'event' === $type ) { Olama_Messages_Suite_Policy::staff( $actor, 'manage_events', 'events' ); if ( $id ) { ( new Olama_Messages_Event_Service() )->get( $id ); } }
        else { throw new InvalidArgumentException( 'نطاق المرفق غير صالح.' ); }
    }
    /** Trusted PHP entry point; REST accepts only PHP-uploaded temp files, never caller paths. */
    public function stage_file( array $actor, $path, $name, $scope_type, $scope_id ) {
        Olama_Messages_Suite_Policy::feature( $actor, 'attachments' ); $this->scope( $actor, $scope_type, $scope_id );
        $storage = $this->storage(); $metadata = $this->validate( $path, $name ); $key = wp_generate_uuid4() . '.' . $metadata['extension'];
        // Commit a staging record before writing bytes, so a killed PHP process leaves a cleanup target.
        $planned = array();
        if ( 0 === strpos( $metadata['mime_type'], 'image/' ) ) { foreach ( array( 'thumb', 'preview' ) as $variant ) { $planned[$variant] = substr( $key, 0, 36 ) . '-' . $variant . '.' . $metadata['extension']; } }
        $id = Olama_Messages_Communications_DB::transaction( function () use ( $actor, $metadata, $key, $planned, $scope_type, $scope_id ) {
            Olama_Messages_Chat_Policy::rate( $actor, 'upload' );
            return Olama_Messages_Communications_DB::insert( 'attachments', $metadata + array( 'uploader_key' => $actor['actor_key'], 'authenticated_wp_user_id' => get_current_user_id(), 'scope_type' => $scope_type, 'scope_id' => $scope_id, 'storage_key' => $key, 'variants_json' => wp_json_encode( $planned ), 'status' => 'writing', 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ), 'expires_at_utc' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ) );
        } );
        try {
            $storage->store( $path, $key ); $variants = array();
            foreach ( $planned as $variant => $variant_key ) {
                $editor = wp_get_image_editor( $storage->path( $key ) ); if ( is_wp_error( $editor ) ) { continue; }
                $maximum = 'thumb' === $variant ? 320 : 1920; $dimensions = $editor->get_size();
                if ( ( $dimensions['width'] > $maximum || $dimensions['height'] > $maximum ) && is_wp_error( $editor->resize( $maximum, $maximum, false ) ) ) { continue; }
                $result = $editor->save( $storage->path( $variant_key ), $metadata['mime_type'] );
                if ( ! is_wp_error( $result ) && $storage->exists( $variant_key ) ) { @chmod( $storage->path( $variant_key ), 0640 ); $variants[$variant] = $variant_key; }
            }
            Olama_Messages_Communications_DB::transaction( function () use ( $id, $actor, $variants, $metadata ) {
                $row = $this->row( $id, true );
                if ( 'writing' !== $row['status'] || $row['expires_at_utc'] <= gmdate( 'Y-m-d H:i:s' ) ) { throw new RuntimeException( 'انتهت مهلة رفع الملف.' ); }
                Olama_Messages_Suite_Policy::update( 'attachments', array( 'variants_json' => wp_json_encode( $variants ), 'status' => 'ready' ), array( 'id' => $id ) );
                Olama_Messages_Communications_DB::audit( 'attachment_staged', $id, $actor, array( 'size' => $metadata['size_bytes'], 'scan_state' => $metadata['scan_state'] ) );
            } );
            return $this->public_row( $this->row( $id ) );
        } catch ( Throwable $error ) {
            foreach ( array_merge( array( $key ), array_values( $planned ) ) as $stored_key ) { $storage->delete( $stored_key ); }
            Olama_Messages_Suite_Policy::update( 'attachments', array( 'status' => 'failed' ), array( 'id' => $id ) ); throw $error;
        }
    }

    public function row( $id, $lock = false ) {
        global $wpdb; $table = Olama_Messages_Communications_DB::table( 'attachments' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d" . ( $lock ? ' FOR UPDATE' : '' ), $id ), ARRAY_A );
        if ( ! $row ) { throw new RuntimeException( 'المرفق غير متاح.' ); } return $row;
    }
    private function public_row( array $row ) {
        return array_intersect_key( $row, array_flip( array( 'id', 'original_name', 'mime_type', 'size_bytes', 'scan_state', 'status', 'created_at_utc' ) ) ) + array( 'variants' => array_keys( (array) json_decode( $row['variants_json'], true ) ) );
    }
    /** Must run in the owner's transaction: a failed send never claims staged files. */
    public function claim( array $actor, array $ids, $owner_type, $owner_id, $scope_type, $scope_id ) {
        if ( ! $ids ) { return; }
        Olama_Messages_Suite_Policy::feature( $actor, 'attachments' ); $this->scope( $actor, $scope_type, $scope_id );
        $settings = Olama_Messages_Communication_Policy::settings(); $ids = Olama_Messages_Suite_Policy::bounded_ids( $ids, $settings['max_attachment_count'] );
        $total = 0; $rows = array();
        foreach ( $ids as $id ) {
            $row = $this->row( $id, true ); $total += (int) $row['size_bytes'];
            $already = $row['owner_type'] === $owner_type && (int) $row['owner_id'] === (int) $owner_id;
            if ( ! $already && ( 'staged' !== $row['owner_type'] || $row['uploader_key'] !== $actor['actor_key'] || (int) $row['authenticated_wp_user_id'] !== get_current_user_id() || ! $row['expires_at_utc'] || $row['expires_at_utc'] <= gmdate( 'Y-m-d H:i:s' ) || $row['scope_type'] !== $scope_type || ( $row['scope_id'] && (int) $row['scope_id'] !== (int) $scope_id ) ) ) { throw new RuntimeException( 'المرفق لا يخص هذه العملية أو انتهت مدة الاحتفاظ المؤقت.' ); }
            if ( ! in_array( $row['status'], array( 'ready', 'linked' ), true ) || ! $this->storage()->exists( $row['storage_key'] ) ) { throw new RuntimeException( 'المرفق ليس جاهزاً.' ); }
            if ( 'clean' !== $row['scan_state'] && ( 'required' === $settings['malware_policy'] || in_array( $row['extension'], array( 'doc', 'xls', 'ppt' ), true ) ) ) { throw new RuntimeException( 'سياسة الفحص الحالية تتطلب رفع ملف مفحوص مجدداً.' ); }
            $rows[] = $row;
        }
        if ( $total > $settings['max_total_attachment_bytes'] ) { throw new RuntimeException( 'مجموع أحجام المرفقات يتجاوز الحد المسموح.' ); }
        foreach ( $rows as $row ) { Olama_Messages_Suite_Policy::update( 'attachments', array( 'owner_type' => $owner_type, 'owner_id' => $owner_id, 'status' => 'linked', 'expires_at_utc' => null, 'active' => 1 ), array( 'id' => $row['id'] ) ); }
    }
    public function replace( array $actor, array $ids, $type, $id ) {
        global $wpdb;
        Olama_Messages_Suite_Policy::feature( $actor, 'attachments' ); $this->scope( $actor, $type, $id );
        $table = Olama_Messages_Communications_DB::table( 'attachments' );
        $this->claim( $actor, $ids, $type, $id, $type, $id );
        $keep = $ids ? implode( ',', array_map( 'absint', $ids ) ) : '0';
        Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$table} SET active=0 WHERE owner_type=%s AND owner_id=%d AND id NOT IN ({$keep})", $type, $id ) );
    }
    public function authorize( array $actor, array $row, $audit_reason = '' ) {
        global $wpdb;
        Olama_Messages_Suite_Policy::feature( $actor, 'attachments' );
        if ( ! in_array( $row['status'], array( 'ready', 'linked' ), true ) ) { throw new RuntimeException( 'المرفق غير متاح.' ); }
        if ( $audit_reason ) {
            Olama_Messages_Suite_Policy::staff( $actor, 'audit' );
            Olama_Messages_Communications_DB::audit( 'attachment_privileged_access', $row['id'], $actor, array( 'reason' => Olama_Messages_Chat_Policy::plain_text( $audit_reason, 1000 ) ) ); return;
        }
        if ( ! $row['active'] ) { throw new RuntimeException( 'هذا المرفق محفوظ للتدقيق ولم يعد ضمن المحتوى الحالي.' ); }
        if ( 'staged' === $row['owner_type'] ) {
            if ( $row['uploader_key'] !== $actor['actor_key'] || (int) $row['authenticated_wp_user_id'] !== get_current_user_id() || $row['expires_at_utc'] <= gmdate( 'Y-m-d H:i:s' ) ) { throw new RuntimeException( 'المرفق المؤقت غير متاح.' ); } return;
        }
        if ( 'message' === $row['owner_type'] ) {
            $message = $wpdb->get_row( $wpdb->prepare( 'SELECT thread_id,redacted_at_utc FROM ' . Olama_Messages_Communications_DB::table( 'messages' ) . ' WHERE id=%d', $row['owner_id'] ), ARRAY_A );
            if ( ! $message || $message['redacted_at_utc'] ) { throw new RuntimeException( 'مرفقات الرسالة المحجوبة غير متاحة.' ); }
            ( new Olama_Messages_Chat_Service() )->get( $actor, $message['thread_id'] ); return;
        }
        if ( 'campaign' === $row['owner_type'] ) {
            $campaign = ( new Olama_Messages_Internal_Campaign_Service() )->get( $row['owner_id'] );
            if ( 'employee' === $actor['actor_type'] && current_user_can( 'olama_messages_manage_campaigns' ) ) { return; }
            $delivery = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Olama_Messages_Communications_DB::table( 'internal_deliveries' ) . ' WHERE campaign_id=%d AND actor_key=%s LIMIT 1', $row['owner_id'], $actor['actor_key'] ) );
            if ( ! $delivery ) { throw new RuntimeException( 'المرفق خارج جمهور الإعلان.' ); } return;
        }
        if ( 'event' === $row['owner_type'] ) { ( new Olama_Messages_Event_Service() )->visible( $actor, $row['owner_id'] ); return; }
        throw new RuntimeException( 'مالك المرفق غير متاح.' );
    }
    public function listing( array $actor, $type, $id ) {
        global $wpdb;
        if ( empty( Olama_Messages_Communication_Policy::settings()['attachments_enabled'] ) || ! current_user_can( 'olama_messages_attachments' ) ) { return array(); }
        $table = Olama_Messages_Communications_DB::table( 'attachments' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner_type=%s AND owner_id=%d AND active=1 ORDER BY id LIMIT 20", $type, $id ), ARRAY_A );
        foreach ( $rows as $row ) { $this->authorize( $actor, $row ); }
        return array_map( array( $this, 'public_row' ), $rows );
    }
    public function open( array $actor, $id, $variant = 'original', $audit_reason = '' ) {
        $row = $this->row( $id ); $this->authorize( $actor, $row, $audit_reason );
        $variants = (array) json_decode( $row['variants_json'], true );
        if ( 'original' !== $variant && ! isset( $variants[$variant] ) ) { throw new RuntimeException( 'معاينة الملف غير متاحة.' ); }
        $key = 'original' === $variant ? $row['storage_key'] : $variants[$variant]; $storage = $this->storage();
        if ( ! $storage->exists( $key ) ) { throw new RuntimeException( 'تعذر العثور على الملف الخاص.' ); }
        return array( 'stream' => fopen( $storage->path( $key ), 'rb' ), 'size' => $storage->size( $key ), 'name' => $row['original_name'], 'mime' => $row['mime_type'] );
    }
    public function expire_staged() {
        global $wpdb;
        $table = Olama_Messages_Communications_DB::table( 'attachments' );
        $rows = $wpdb->get_results( "SELECT id FROM {$table} WHERE owner_type='staged' AND status IN ('ready','writing','failed') AND expires_at_utc<UTC_TIMESTAMP() ORDER BY id LIMIT 30", ARRAY_A );
        if ( ! $rows ) { return; } $storage = $this->storage();
        foreach ( $rows as $item ) {
            Olama_Messages_Communications_DB::transaction( function () use ( $item, $storage ) {
                $row = $this->row( $item['id'], true );
                if ( 'staged' !== $row['owner_type'] || $row['expires_at_utc'] >= gmdate( 'Y-m-d H:i:s' ) ) { return; }
                foreach ( array_merge( array( $row['storage_key'] ), array_values( (array) json_decode( $row['variants_json'], true ) ) ) as $key ) { $storage->delete( $key ); }
                Olama_Messages_Suite_Policy::update( 'attachments', array( 'status' => 'expired' ), array( 'id' => $row['id'] ) );
            } );
        }
    }
}
