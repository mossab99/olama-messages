<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Olama_Messages_Suite_Rest_Controller {
    public function register_routes() {
        foreach ( array( '/suite/me' => 'GET', '/suite/feed' => 'GET', '/suite/activity' => 'GET,POST', '/suite/preferences' => 'GET,POST', '/suite/search' => 'POST', '/suite/dashboard' => 'GET', '/suite/retention' => 'POST',
            '/suite/attachments' => 'POST', '/suite/attachments/(?P<id>\d+)' => 'GET', '/suite/attachments/(?P<id>\d+)/download' => 'GET',
            '/suite/actions' => 'GET,POST', '/suite/actions/(?P<id>\d+)' => 'GET,POST',
            '/suite/events' => 'GET,POST', '/suite/events/(?P<id>\d+)' => 'GET,POST', '/suite/events/(?P<id>\d+)/(?P<command>prepare|publish|add_audience|cancel|complete|archive|delete|reset|sync|ics)' => 'GET,POST',
            '/suite/event-targets/(?P<id>\d+)' => 'POST' ) as $route => $method ) {
            register_rest_route( 'olama-messages/v1', '/communications' . $route, array( 'methods' => $method, 'permission_callback' => array( $this, 'permission' ), 'callback' => array( $this, 'dispatch' ) ) );
        }
        add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 4 );
    }
    public function permission( $request ) {
        if ( ! is_user_logged_in() || ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) { return new WP_Error( 'suite_auth', 'يرجى تسجيل الدخول مجدداً.', array( 'status' => 401 ) ); }
        try { if ( Olama_Messages_Communications_DB::health() ) { throw new RuntimeException( 'مخطط الاتصالات غير جاهز.' ); } Olama_Messages_Suite_Policy::actor( $this->actor( $request ) ); return true; }
        catch ( Throwable $e ) { return new WP_Error( 'suite_forbidden', $e->getMessage(), array( 'status' => 403 ) ); }
    }
    private function actor( $request ) { return ( new Olama_Messages_Actor_Resolver() )->resolve( (string) $request->get_header( 'X-Olama-Actor' ), true ); }
    public function dispatch( $request ) {
        try {
            $actor = $this->actor( $request ); Olama_Messages_Suite_Policy::actor( $actor ); $path = $request->get_route(); $id = absint( $request['id'] ); $command = (string) $request['command']; $post = 'POST' === $request->get_method(); $data = (array) $request->get_json_params();
            $events = new Olama_Messages_Event_Service(); $actions = new Olama_Messages_Action_Service(); $activity = new Olama_Messages_Activity_Service(); $ops = new Olama_Messages_Suite_Operations();
            if ( preg_match( '~/suite/me$~', $path ) ) {
                $settings = Olama_Messages_Communication_Policy::settings(); $result = array( 'timezone' => wp_timezone_string() );
                foreach ( array( 'events', 'actions', 'attachments' ) as $feature ) { $result[$feature] = ! empty( $settings[$feature . '_enabled'] ) && current_user_can( 'olama_messages_' . $feature ); }
                foreach ( array( 'manage_events', 'manage_actions', 'view_dashboard', 'configure' ) as $cap ) { $result[$cap] = 'employee' === $actor['actor_type'] && current_user_can( 'olama_messages_' . $cap ); }
                $result['attachment_limits'] = array_intersect_key( $settings, array_flip( array( 'max_attachment_count', 'max_total_attachment_bytes', 'max_image_bytes', 'max_document_bytes', 'max_presentation_bytes' ) ) );
            } elseif ( false !== strpos( $path, '/suite/attachments' ) ) {
                $files = new Olama_Messages_Attachment_Service();
                if ( $post ) {
                    $params = $request->get_file_params(); $file = $params['file'] ?? null;
                    if ( ! is_array( $file ) || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) { throw new InvalidArgumentException( 'لم يكتمل رفع الملف.' ); }
                    $result = $files->stage_file( $actor, $file['tmp_name'], $file['name'], (string) $request['scope_type'], absint( $request['scope_id'] ) );
                } elseif ( preg_match( '~/download$~', $path ) ) {
                    $opened = $files->open( $actor, $id, (string) ( $request['variant'] ?: 'original' ), (string) $request['audit_reason'] ); fclose( $opened['stream'] ); $result = array( 'private_stream' => 'attachment' );
                } else { $row = $files->row( $id ); $files->authorize( $actor, $row ); $result = array_intersect_key( $row, array_flip( array( 'id', 'original_name', 'mime_type', 'size_bytes', 'status', 'scan_state' ) ) ); }
            } elseif ( false !== strpos( $path, '/suite/event-targets/' ) ) { $result = $events->respond( $actor, $id, $data ); }
            elseif ( false !== strpos( $path, '/suite/events' ) ) {
                if ( $command ) {
                    if ( 'ics' === $command && ! $post ) { $events->visible( $actor, $id ); $result = array( 'private_stream' => 'ics' ); }
                    elseif ( ! $post ) { throw new InvalidArgumentException( 'استخدم POST لتغيير الفعالية.' ); }
                    elseif ( 'sync' === $command ) { Olama_Messages_Suite_Policy::staff( $actor, 'manage_events', 'events' ); $events->sync_source( $id ); $result = array( 'ok' => true ); }
                    else { $result = $events->command( $actor, $id, $command, $data ); }
                } else { $result = $post ? $events->save( $actor, $data, $id ) : ( $id ? $events->detail( $actor, $id, absint( $request['target_after'] ) ) : $events->listing( $actor, $request->get_query_params() ) ); }
            } elseif ( false !== strpos( $path, '/suite/actions' ) ) { $result = $post ? ( $id ? $actions->change( $actor, $id, $data ) : $actions->create( $actor, $data ) ) : ( $id ? $actions->detail( $actor, $id ) : $actions->listing( $actor, absint( $request['before'] ), absint( $request['thread_id'] ) ) ); }
            elseif ( preg_match( '~/feed$~', $path ) ) { $result = $activity->feed( $actor ); }
            elseif ( preg_match( '~/activity$~', $path ) ) { $result = $post ? $activity->receipt( $actor, $data ) : $activity->listing( $actor, absint( $request['before'] ) ); }
            elseif ( preg_match( '~/preferences$~', $path ) ) { $result = $activity->preferences( $actor, $post ? $data : null ); }
            elseif ( preg_match( '~/search$~', $path ) ) { $result = $ops->search( $actor, $data ); }
            elseif ( preg_match( '~/dashboard$~', $path ) ) { $result = $ops->dashboard( $actor ); }
            elseif ( preg_match( '~/retention$~', $path ) ) { $result = $ops->retention( $actor, $data ); }
            else { throw new InvalidArgumentException( 'عملية غير معروفة.' ); }
            $response = new WP_REST_Response( $result ); $response->header( 'Cache-Control', 'private, no-store' ); $response->header( 'Vary', 'Cookie, X-Olama-Actor' ); return $response;
        } catch ( Throwable $e ) { return new WP_Error( 'suite_operation', $e->getMessage(), array( 'status' => 409 ) ); }
    }
    /** Bytes are never placed in a REST response object or a public URL. */
    public function serve( $served, $response, $request, $server ) {
        $data = $response->get_data();
        if ( $served || 0 !== strpos( $request->get_route(), '/olama-messages/v1/communications/suite/' ) || ! is_array( $data ) || empty( $data['private_stream'] ) ) { return $served; }
        try {
            $permission = $this->permission( $request ); if ( is_wp_error( $permission ) ) { throw new RuntimeException( 'انتهت صلاحية الوصول.' ); }
            $actor = $this->actor( $request ); $id = absint( $request['id'] );
            if ( 'ics' === $data['private_stream'] ) { $bytes = ( new Olama_Messages_Event_Service() )->ics( $actor, $id ); $name = 'event-' . $id . '.ics'; $mime = 'text/calendar; charset=utf-8'; $size = strlen( $bytes ); }
            else { $opened = ( new Olama_Messages_Attachment_Service() )->open( $actor, $id, (string) ( $request['variant'] ?: 'original' ), (string) $request['audit_reason'] ); $name = $opened['name']; $mime = $opened['mime']; $size = $opened['size']; }
            header( 'Cache-Control: private, no-store' ); header( 'X-Content-Type-Options: nosniff' ); header( "Content-Security-Policy: default-src 'none'; sandbox" ); header( 'Content-Type: ' . $mime ); header( 'Content-Length: ' . $size );
            header( "Content-Disposition: attachment; filename=\"download\"; filename*=UTF-8''" . rawurlencode( $name ) );
            if ( isset( $bytes ) ) { echo $bytes; } else { fpassthru( $opened['stream'] ); fclose( $opened['stream'] ); }
        } catch ( Throwable $e ) { status_header( 403 ); header( 'Content-Type: text/plain; charset=utf-8' ); echo 'الملف غير متاح.'; }
        return true;
    }
}
