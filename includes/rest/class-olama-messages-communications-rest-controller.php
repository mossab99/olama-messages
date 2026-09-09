<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Communications_Rest_Controller {
    public function register_routes() {
        foreach ( array(
            '/communications/me' => 'GET', '/communications/notices' => 'GET', '/communications/notices/(?P<id>\d+)' => 'GET',
            '/communications/notifications' => 'GET', '/communications/counts' => 'GET', '/communications/receipts' => 'POST',
            '/communications/campaigns' => 'GET,POST', '/communications/campaigns/(?P<id>\d+)' => 'GET,POST',
            '/communications/campaigns/(?P<id>\d+)/(?P<command>prepare|publish|cancel|archive|reset|delete|retry_delivery)' => 'POST',
            '/communications/health' => 'GET', '/communications/jobs/(?P<id>\d+)/retry' => 'POST',
        ) as $route => $methods ) {
            register_rest_route( 'olama-messages/v1', $route, array( 'methods' => $methods, 'permission_callback' => array( $this, 'permission' ), 'callback' => array( $this, 'dispatch' ) ) );
        }
    }

    public function permission( $request ) {
        if ( ! is_user_logged_in() || ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) { return new WP_Error( 'communications_auth', 'يرجى تسجيل الدخول مجدداً.', array( 'status' => 401 ) ); }
        try {
            if ( Olama_Messages_Communications_DB::health() ) { throw new RuntimeException( 'خدمة الاتصالات تحتاج إلى مراجعة قاعدة البيانات.' ); }
            $actor = ( new Olama_Messages_Actor_Resolver() )->resolve( (string) $request->get_header( 'X-Olama-Actor' ), true );
            Olama_Messages_Communication_Policy::require_use( $actor );
            if ( preg_match( '~/communications/(campaigns|health|jobs)~', $request->get_route() ) ) { Olama_Messages_Communication_Policy::require_manage( $actor ); }
            return true;
        } catch ( Throwable $error ) {
            update_option( 'olama_msg_communications_identity_error_at', gmdate( 'Y-m-d H:i:s' ), false );
            return new WP_Error( 'communications_forbidden', $error->getMessage(), array( 'status' => 403 ) );
        }
    }

    public function dispatch( $request ) {
        try {
            // Resolve again in the operation; the client context is never authority.
            $resolver = new Olama_Messages_Actor_Resolver();
            $actor = $resolver->resolve( (string) $request->get_header( 'X-Olama-Actor' ), true );
            $service = new Olama_Messages_Internal_Campaign_Service();
            $notices = new Olama_Messages_Notification_Service();
            $route = $request->get_route();
            $id = absint( $request['id'] );
            $data = (array) $request->get_json_params();
            if ( substr( $route, -3 ) === '/me' ) {
                $staff = 'employee' === $actor['actor_type'];
                $teacher = $staff ? ( new Olama_Messages_Relationship_Provider() )->employee( $actor['actor_key'] ) : null;
                $result = array( 'actor' => $actor, 'available_actors' => $resolver->available( get_current_user_id(), true ), 'identity_mode' => 'single_verified', 'can_manage' => $staff && current_user_can( 'olama_messages_manage_campaigns' ),
                    'chat' => ! empty( Olama_Messages_Communication_Policy::settings()['chat_enabled'] ) && current_user_can( 'olama_messages_chat' ),
                    'is_teacher' => $teacher && $teacher['teacher'], 'can_moderate' => $staff && current_user_can( 'olama_messages_moderate' ), 'can_manage_inboxes' => $staff && current_user_can( 'olama_messages_manage_inboxes' ), 'can_audit' => $staff && current_user_can( 'olama_messages_audit' ) );
            } elseif ( false !== strpos( $route, '/campaigns' ) ) {
                if ( $request['command'] ) { $result = $service->command( $id, $request['command'], $actor, $data ); }
                elseif ( 'POST' === $request->get_method() ) { $result = array( 'id' => $service->save( $data, $actor, $id ) ); }
                elseif ( $id ) { $campaign = $service->get( $id ); $campaign['attachments'] = ( new Olama_Messages_Attachment_Service() )->listing( $actor, 'campaign', $id ); $result = array( 'campaign' => $campaign, 'statistics' => $service->statistics( $id ) ); }
                else { $result = $service->listing( absint( $request['before'] ) ); }
            } elseif ( false !== strpos( $route, '/jobs/' ) ) {
                $result = Olama_Messages_Communications::retry_job( $id, $actor );
            } elseif ( substr( $route, -7 ) === '/health' ) {
                $result = Olama_Messages_Communications::health();
            } elseif ( substr( $route, -9 ) === '/receipts' ) {
                $result = $notices->receipt( $actor, (array) ( $data['ids'] ?? array() ), $data['kind'] ?? '' );
            } elseif ( substr( $route, -7 ) === '/counts' ) {
                $result = $notices->counts( $actor );
            } elseif ( substr( $route, -14 ) === '/notifications' ) {
                $result = $notices->notifications( $actor, absint( $request['after'] ), absint( $request['before'] ), '1' === $request['unseen'] );
            } else {
                $result = $id ? $notices->notice( $actor, $id ) : $notices->notices( $actor, absint( $request['before'] ) );
            }
            $response = new WP_REST_Response( $result );
            $response->header( 'Cache-Control', 'private, no-store' );
            $response->header( 'Vary', 'Cookie, X-Olama-Actor' );
            return $response;
        } catch ( Throwable $error ) {
            return new WP_Error( 'communications_operation', $error->getMessage(), array( 'status' => 409 ) );
        }
    }
}
