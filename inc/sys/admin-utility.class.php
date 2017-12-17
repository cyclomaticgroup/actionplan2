<?php

class AdminUtility
{
    public static function load_woocommerce_admin_assets($list) {
        return array_unique(array_merge($list, array_values(self::$menu_page_hooks)));
    }

    // fetch the page slug for a given settings page
    public static function menu_page_slug($which='main' ) {
        return ( ! empty( $which ) && is_scalar( $which ) && isset( self::$menu_slugs[ $which ] ) ) ? self::$menu_slugs[ $which ] : self::$menu_slugs['main'];
    }

    public static function refresh_permalinks_on_save_uri( $uri, $page ) {
        if ( 'general' == $page ) {
            $uri = add_query_arg( array( 'refresh-permalinks' => wp_create_nonce( 'refresh-now/qsot' ) ) );
        }

        return $uri;
    }

    public static function refresh_permalinks_on_save_page_refresh() {
        if ( isset( $_GET['refresh-permalinks'] ) ) {
            if ( wp_verify_nonce( $_GET['refresh-permalinks'], 'refresh-now/qsot' ) ) {
                $wp_rewrite='';
                flush_rewrite_rules();
                $wp_rewrite->rewrite_rules();
            }
            wp_safe_redirect( remove_query_arg( array( 'refresh-permalinks' ) ) );
        }
    }

    // create the external links on our menu, which currently can open in a new window
    // done this way, because currently there is no mechanism to make admin menu items open a new tab!!! wth
    public static function external_links() {
        $submenu='';

        // if out opentickets menu exists
        if ( isset( $submenu['opentickets'] ) ) {
            // add a documentation link
            $submenu['opentickets'][] = array(
                sprintf( __( 'Documentation %s', 'opentickets-community-edition' ), '<span class="dashicons dashicons-external"></span>' ),
                'manage_options',
                "http://opentickets.com/documentation/' target='_blank",
                sprintf( __( 'Documentation %s', 'opentickets-community-edition' ), '' ),
                'otce-external-link otce-documentation'
            );

            // add a videos link
            $submenu['opentickets'][] = array(
                sprintf( __( 'Videos %s', 'opentickets-community-edition' ), '<span class="dashicons dashicons-external"></span>' ),
                'manage_options',
                "http://opentickets.com/videos/' target='_blank",
                sprintf( __( 'Videos %s', 'opentickets-community-edition' ), '' ),
                'otce-external-link otce-videos'
            );
        }
    }

    public static function register_post_types() {
        // generate a list of post types and post type settings to create. allow external plugins to modify this. why? because of multiple reasons. 1) this process calls a syntaxically different
        // method of defining post types, that has a slightly different set of defaults than the normal method, which may be preferred over the core method of doing so. 2) external plugins may
        // want to brand the name of the post differently. 3) external plugins may want to tweak the settings of the pos type for some other purpose. 4) sub plugins/external plugins may have
        // additional post types that need to be declared at the same time as the core post types. 5) make up your own reasons
        $core = apply_filters('qsot-events-core-post-types', array());

        // if there are post types to create, then create them
        if (is_array($core) && !empty($core))
            foreach ($core as $slug => $args) self::_register_post_type($slug, $args);
    }

    // parts of this are copied directly from woocommerce/admin/woocommerce-admin-settings.php
    // the general method is identical, save for the naming
    public static function ap_settings_page() {
        require_once 'admin-settings.php';
        qsot_admin_settings::output();
    }

    public static function ap_settings_page_head() {
        require_once 'admin-settings.php';

        // Include settings pages
        qsot_admin_settings::get_settings_pages();

        if (empty($_POST)) return;

        qsot_admin_settings::save();
    }

    protected static function _get_reports_charts() {
        $charts = array();

        return apply_filters( 'qsot_reports_charts', $charts );
    }

    protected static function _check_cron() {
        $ts = wp_next_scheduled('qsot_daily_stats');
        if ( $ts )
            wp_unschedule_event( $ts, 'qsot_daily_stats' );
    }
}