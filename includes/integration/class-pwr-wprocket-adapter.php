<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class PWR_WPRocket_Adapter {
    public static function is_active() { return defined( 'WP_ROCKET_VERSION' ); }
    public static function supports_page_cache() { return self::is_active() && function_exists( 'rocket_clean_files' ); }
    public static function supports_rucss() { $container = apply_filters( 'rocket_container', null ); return self::is_active() && (bool)$container; }
    public static function supports_priority_elements() { $container = apply_filters( 'rocket_container', null ); return self::is_active() && (bool)$container; }
    public static function purge_page_cache( $url ) {
        if ( ! self::supports_page_cache() ) {
            return new WP_Error( 'pwr_no_support', __( 'Page cache purge is not supported on this installation.', 'processing-wp-rocket' ) );
        }
        $u = trim( (string) $url );
        // If it's a relative path (starts with '/' or lacks scheme), convert to absolute using home_url.
        if ( $u !== '' ) {
            $parts = wp_parse_url( $u );
            if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
                // Ensure leading slash for path-only values.
                if ( isset($parts['path']) && substr( $parts['path'], 0, 1 ) !== '/' ) {
                    $parts['path'] = '/' . $parts['path'];
                }
                $u = home_url( isset($parts['path']) ? $parts['path'] : '/' );
            }
        }
        rocket_clean_files( $u );
        return true;
    }
    public static function clear_used_css_for_url( $url ) {
        self::purge_page_cache( $url );
        $container = apply_filters( 'rocket_container', null );
        if ( $container ) {
            try {
                $subscriber = $container->get( 'rucss_admin_subscriber' );
                if ( $subscriber && method_exists( $subscriber, 'truncate_used_css_for_url' ) ) { $subscriber->truncate_used_css_for_url( $url ); return true; }
                if ( $subscriber && method_exists( $subscriber, 'truncate_used_css' ) ) { $subscriber->truncate_used_css(); return true; }
            } catch ( Throwable $e ) {}
        }
        return true;
    }
    public static function clear_priority_elements_data( $url ) {
        $ok = false; $container = apply_filters( 'rocket_container', null );
        if ( $container ) { try {
            $service_ids = [ 'priority_elements_admin_subscriber', 'priority_elements_subscriber' ];
            $methods     = [ 'clear_data', 'truncate', 'clear_priority_elements', 'delete_data' ];
            foreach ( $service_ids as $sid ) {
                $svc = $container->get( $sid ); if ( ! $svc ) continue;
                foreach ( $methods as $m ) { if ( method_exists( $svc, $m ) ) { $svc->$m(); $ok = true; break 2; } }
            }
        } catch ( Throwable $e ) {} }
        self::purge_page_cache( $url );
        return $ok ? true : new WP_Error( 'pwr_priority_elements_partial', __( 'Priority Elements data clear fell back to page cache purge.', 'processing-wp-rocket' ) );
    }
}
