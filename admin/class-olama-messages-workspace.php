<?php
/** Shared, capability-scoped admin shell. Existing page callbacks retain their actions. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Olama_Messages_Workspace {
    public static function routes() {
        return array(
            'olama-messages' => array( 'Overview','dashboard' ),
            'olama-messages-campaigns' => array( 'Campaigns','megaphone' ),
            'olama-messages-phone-book' => array( 'Audience','groups' ),
            'olama-messages-templates' => array( 'Message library','media-text' ),
            'olama-messages-delivery' => array( 'Delivery','chart-line' ),
            'olama-messages-settings' => array( 'Settings','admin-generic' ),
        );
    }
    public static function parent( $page ) {
        $parents = array( 'olama-messages-new-campaign' => 'olama-messages-campaigns', 'olama-messages-campaign-progress' => 'olama-messages-campaigns',
            'olama-messages-direct' => 'olama-messages-campaigns', 'olama-messages-recipients' => 'olama-messages-phone-book',
            'olama-messages-reports' => 'olama-messages-phone-book', 'olama-messages-tokens' => 'olama-messages-templates',
            'olama-messages-agents' => 'olama-messages-delivery', 'olama-messages-queue' => 'olama-messages-delivery' );
        return $parents[$page] ?? $page;
    }
    public static function url( $page, array $args = array() ) { return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ); }
    public static function callback( $owner, $method, $plugin ) {
        return static function () use ( $owner, $method, $plugin ) {
            if ( ! current_user_can( 'olama_access_messages' ) ) { wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) ); }
            self::begin( $plugin );
            try { $owner->$method(); } finally { self::end(); }
        };
    }
    public static function begin( $plugin ) {
        $page = sanitize_key( $_GET['page'] ?? 'olama-messages' ); $parent = self::parent( $page ); $routes = self::routes();
        $school = get_option( 'olama_msg_school_name', '' ) ?: get_bloginfo( 'name' );
        $year = ''; try { $year = $plugin->provider()->get_current_study_year(); } catch ( Throwable $error ) { /* Navigation remains usable when Core is unavailable. */ }
        $agent = $plugin->agents()->get_ready_dispatcher_agent();
        $labels = array( 'olama-messages-new-campaign' => 'Create campaign', 'olama-messages-campaign-progress' => 'Campaign delivery', 'olama-messages-direct' => 'Quick Send', 'olama-messages-reports' => 'Reports', 'olama-messages-tokens' => 'Parent report links', 'olama-messages-agents' => 'Sending agents' );
        $label = $labels[$page] ?? ( $routes[$parent][0] ?? 'Messages' );
        ?>
        <div class="omw-shell" data-workspace-page="<?php echo esc_attr( $page ); ?>">
            <button type="button" class="omw-shade" data-workspace-close aria-label="<?php esc_attr_e( 'Close navigation', 'olama-messages' ); ?>" hidden></button>
            <aside class="omw-sidebar" id="omw-sidebar"><a class="omw-brand" href="<?php echo esc_url( self::url( 'olama-messages' ) ); ?>"><span class="omw-brand-mark"><span class="dashicons dashicons-email-alt"></span></span><span><strong>olama</strong><small><?php esc_html_e( 'MESSAGES', 'olama-messages' ); ?></small></span></a>
                <div class="omw-school"><span class="dashicons dashicons-welcome-learn-more"></span><div><strong title="<?php echo esc_attr( $school ); ?>"><?php echo esc_html( $school ); ?></strong><small><?php echo esc_html( $year ?: __( 'School workspace', 'olama-messages' ) ); ?></small></div></div>
                <nav aria-label="<?php esc_attr_e( 'Olama Messages navigation', 'olama-messages' ); ?>"><?php $i=0; foreach ( $routes as $slug => $route ) : ?>
                    <?php if ( in_array( $i, array(0,2,4), true ) ) : ?><p class="omw-nav-group"><?php echo esc_html( array( 0 => __( 'WORKSPACE', 'olama-messages' ), 2 => __( 'RESOURCES', 'olama-messages' ), 4 => __( 'OPERATIONS', 'olama-messages' ) )[$i] ); ?></p><?php endif; ?>
                    <a class="omw-nav-link <?php echo $parent === $slug ? 'is-current' : ''; ?>" href="<?php echo esc_url( self::url( $slug ) ); ?>" <?php echo $parent === $slug ? 'aria-current="page"' : ''; ?>><span class="dashicons dashicons-<?php echo esc_attr( $route[1] ); ?>"></span><?php echo esc_html( translate( $route[0], 'olama-messages' ) ); ?></a>
                <?php $i++; endforeach; ?></nav>
                <div class="omw-sidebar-bottom"><a class="omw-agent-connection" href="<?php echo esc_url( self::url( 'olama-messages-agents' ) ); ?>"><span class="omw-dot <?php echo $agent ? '' : 'is-warning'; ?>"></span><div><strong><?php echo esc_html( $agent ? __( 'Sending agent ready', 'olama-messages' ) : __( 'Check sending agent', 'olama-messages' ) ); ?></strong><small><?php echo esc_html( $agent ? $agent['agent_name'] : __( 'Open connection details', 'olama-messages' ) ); ?></small></div></a><a class="omw-wp-return" href="<?php echo esc_url( admin_url() ); ?>"><span class="dashicons dashicons-wordpress"></span><?php esc_html_e( 'WordPress dashboard', 'olama-messages' ); ?> ↗</a></div>
            </aside>
            <div class="omw-app"><header class="omw-topbar"><div class="omw-breadcrumb"><button type="button" class="omw-mobile-toggle" data-workspace-menu aria-controls="omw-sidebar" aria-expanded="false" aria-label="<?php esc_attr_e( 'Open navigation', 'olama-messages' ); ?>"><span class="dashicons dashicons-menu"></span></button><a href="<?php echo esc_url( admin_url() ); ?>">WordPress</a><span>/</span><strong><?php echo esc_html( $label ); ?></strong></div><button type="button" class="omw-search-button" data-workspace-search><span class="dashicons dashicons-search"></span><span><?php esc_html_e( 'Find a page…', 'olama-messages' ); ?></span><kbd>Ctrl K</kbd></button></header>
                <div class="omw-content">
                    <?php self::context_links( $page, $parent ); ?>
        <?php
    }
    public static function context_links( $page, $parent ) {
        $groups = array(
            'olama-messages-campaigns' => array( 'olama-messages-campaigns' => 'Campaigns', 'olama-messages-new-campaign' => 'Create campaign', 'olama-messages-direct' => 'Quick Send' ),
            'olama-messages-phone-book' => array( 'olama-messages-phone-book' => 'Contacts', 'olama-messages-reports' => 'Reports' ),
            'olama-messages-templates' => array( 'olama-messages-templates' => 'Message library', 'olama-messages-tokens' => 'Parent report links' ),
            'olama-messages-delivery' => array( 'olama-messages-delivery' => 'Delivery activity', 'olama-messages-agents' => 'Sending agents' ),
        );
        if ( ! isset( $groups[$parent] ) ) { return; }
        echo '<nav class="omw-context-nav" aria-label="' . esc_attr__( 'Related pages', 'olama-messages' ) . '">';
        foreach ( $groups[$parent] as $slug => $label ) { echo '<a class="' . ( $page === $slug ? 'is-current' : '' ) . '" href="' . esc_url( self::url( $slug ) ) . '">' . esc_html( translate( $label, 'olama-messages' ) ) . '</a>'; }
        echo '</nav>';
    }
    public static function end() {
        ?><footer class="omw-footer"><span><?php esc_html_e( 'Olama Messages · School communication, thoughtfully organized.', 'olama-messages' ); ?></span><span><?php echo esc_html( wp_timezone_string() ); ?> · v<?php echo esc_html( OLAMA_MSG_VERSION ); ?></span></footer></div></div>
        <dialog class="omw-command" id="omw-command" aria-label="<?php esc_attr_e( 'Find a workspace page', 'olama-messages' ); ?>"><form method="dialog"><input type="search" data-workspace-query placeholder="<?php esc_attr_e( 'Find a page…', 'olama-messages' ); ?>" aria-label="<?php esc_attr_e( 'Page name', 'olama-messages' ); ?>"><button class="omw-command-close" aria-label="<?php esc_attr_e( 'Close search', 'olama-messages' ); ?>">×</button></form><div class="omw-command-results"><?php foreach ( array_merge( self::routes(), array( 'olama-messages-new-campaign' => array('Create campaign','plus-alt2'), 'olama-messages-direct' => array('Quick Send','email'), 'olama-messages-agents' => array('Sending agents','desktop'), 'olama-messages-reports' => array('Reports','chart-bar'), 'olama-messages-tokens' => array('Parent report links','admin-links') ) ) as $slug => $route ) : ?><a href="<?php echo esc_url( self::url( $slug ) ); ?>" data-workspace-result><span class="dashicons dashicons-<?php echo esc_attr( $route[1] ); ?>"></span><?php echo esc_html( translate( $route[0], 'olama-messages' ) ); ?></a><?php endforeach; ?><p data-workspace-empty hidden><?php esc_html_e( 'No matching pages.', 'olama-messages' ); ?></p></div><small><?php esc_html_e( 'Search destinations · Escape to close', 'olama-messages' ); ?></small></dialog></div><?php
    }
}
