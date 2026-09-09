<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Chat_Rest_Controller {
    public function register_routes() {
        foreach ( array(
            '/chat/contacts' => 'GET', '/chat/feed' => 'GET', '/chat/threads' => 'GET,POST',
            '/chat/threads/(?P<id>\d+)' => 'GET', '/chat/threads/(?P<id>\d+)/(?P<command>messages|receipt|preferences|assign|resolve|reopen|start)' => 'POST',
            '/chat/messages/(?P<id>\d+)/(?P<command>edit|report|redact|revisions)' => 'POST',
            '/chat/inboxes' => 'GET,POST', '/chat/inboxes/(?P<id>\d+)' => 'POST',
            '/chat/reports' => 'GET', '/chat/reports/(?P<id>\d+)' => 'POST',
            '/chat/context' => 'POST', '/chat/restrictions' => 'GET,POST', '/chat/restrictions/(?P<id>\d+)/revoke' => 'POST',
        ) as $route => $methods ) {
            register_rest_route( 'olama-messages/v1', '/communications' . $route, array( 'methods' => $methods, 'permission_callback' => array( $this, 'permission' ), 'callback' => array( $this, 'dispatch' ) ) );
        }
    }

    public function permission( $request ) {
        if ( ! is_user_logged_in() || ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) { return new WP_Error( 'chat_auth', 'يرجى تسجيل الدخول مجدداً.', array( 'status' => 401 ) ); }
        try {
            if ( Olama_Messages_Communications_DB::health() ) { throw new RuntimeException( 'مخطط الاتصالات غير جاهز.' ); }
            $actor = ( new Olama_Messages_Actor_Resolver() )->resolve( (string) $request->get_header( 'X-Olama-Actor' ), true );
            Olama_Messages_Chat_Policy::require_use( $actor );
            return true;
        } catch ( Throwable $error ) { return new WP_Error( 'chat_forbidden', $error->getMessage(), array( 'status' => 403 ) ); }
    }

    public function dispatch( $request ) {
        try {
            $actor = ( new Olama_Messages_Actor_Resolver() )->resolve( (string) $request->get_header( 'X-Olama-Actor' ), true );
            Olama_Messages_Chat_Policy::require_use( $actor );
            $chat = new Olama_Messages_Chat_Service(); $inboxes = new Olama_Messages_Service_Inbox_Service(); $mod = new Olama_Messages_Moderation_Service();
            $route = $request->get_route(); $id = absint( $request['id'] ); $command = (string) $request['command'];
            $data = (array) $request->get_json_params(); $post = 'POST' === $request->get_method(); $before = absint( $request['before'] );
            if ( false !== strpos( $route, '/chat/contacts' ) ) {
                $provider = new Olama_Messages_Relationship_Provider();
                $query = mb_substr( sanitize_text_field( (string) $request['query'] ), 0, 100 );
                $group = sanitize_key( (string) $request['group'] );
                $result = 'assigned_families' === $request['directory'] ? $provider->teacher_contacts( $actor, (string) $request['after_student'], absint( $request['after_assignment'] ), $query, $group ) : $provider->contacts( $actor, (string) $request['student_uid'], absint( $request['after'] ), $query, $group );
            }
            elseif ( false !== strpos( $route, '/chat/feed' ) ) { $result = $chat->feed( $actor, absint( $request['after'] ) ); }
            elseif ( false !== strpos( $route, '/chat/inboxes' ) ) { $result = $post ? $inboxes->save( $actor, $data, $id ) : $inboxes->listing( $actor, '1' === $request['admin'], $before ); }
            elseif ( false !== strpos( $route, '/chat/reports' ) ) { $result = $post ? $mod->review( $actor, $id, $data ) : $mod->queue( $actor, $before, (string) ( $request['status'] ?: 'open' ) ); }
            elseif ( false !== strpos( $route, '/chat/restrictions' ) ) {
                $result = $id ? $mod->revoke( $actor, $id ) : ( $post ? $mod->restrict( $actor, $data ) : $mod->restrictions( $actor, $before ) );
            } elseif ( false !== strpos( $route, '/chat/context' ) ) { $result = $mod->context( $actor, absint( $data['thread_id'] ?? 0 ), absint( $data['report_id'] ?? 0 ), $data['reason'] ?? '', absint( $data['before'] ?? 0 ) ); }
            elseif ( false !== strpos( $route, '/chat/messages/' ) ) {
                if ( 'edit' === $command ) { $result = $chat->edit( $actor, $id, $data ); }
                elseif ( 'report' === $command ) { $result = $mod->report( $actor, $id, $data['reason'] ?? '' ); }
                elseif ( 'revisions' === $command ) { $result = $mod->revisions( $actor, $id, absint( $data['report_id'] ?? 0 ), $data['reason'] ?? '', absint( $data['before'] ?? 0 ) ); }
                else { $result = $mod->redact( $actor, $id, $data ); }
            } elseif ( $command ) {
                if ( 'messages' === $command ) { $result = $chat->send( $actor, $id, $data ); }
                elseif ( 'receipt' === $command ) { $result = $chat->receipt( $actor, $id, absint( $data['cursor'] ?? 0 ), $data['kind'] ?? '' ); }
                elseif ( 'preferences' === $command ) { $result = $chat->preferences( $actor, $id, $data ); }
                else { $result = $inboxes->action( $actor, $id, $command, (string) ( $data['target'] ?? '' ) ); }
            } else { $result = $post ? $chat->create( $actor, $data ) : ( $id ? $chat->conversation( $actor, $id, $before ) : $chat->listing( $actor, $before, '1' === $request['archived'], absint( $request['inbox_id'] ), (string) $request['cursor'] ) ); }
            $response = new WP_REST_Response( $result );
            $response->header( 'Cache-Control', 'private, no-store' ); $response->header( 'Vary', 'Cookie, X-Olama-Actor' ); return $response;
        } catch ( Throwable $error ) { return new WP_Error( 'chat_operation', $error->getMessage(), array( 'status' => 409 ) ); }
    }
}
