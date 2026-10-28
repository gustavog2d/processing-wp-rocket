<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only bridge to WP Rocket. Never calls WP Rocket's purge/clean APIs.
 */
class PWR_WPRocket_Adapter {
	public static function is_active() {
		return defined( 'WP_ROCKET_VERSION' );
	}

	public static function version() {
		return defined( 'WP_ROCKET_VERSION' ) ? (string) WP_ROCKET_VERSION : '';
	}

	/**
	 * Reads a WP Rocket option, through WP Rocket's own getter when available so that
	 * its `pre_get_rocket_option_*` filters (e.g. Delay JS disabling Combine JS) apply.
	 */
	public static function get_option( $key, $default = false ) {
		if ( function_exists( 'get_rocket_option' ) ) {
			return get_rocket_option( $key, $default );
		}
		$options = get_option( defined( 'WP_ROCKET_SLUG' ) ? WP_ROCKET_SLUG : 'wp_rocket_settings', [] );
		return ( is_array( $options ) && array_key_exists( $key, $options ) ) ? $options[ $key ] : $default;
	}

	/** Cache lifespan in seconds (0 = never expires), from the "Cache Lifespan" setting. */
	public static function cache_lifespan() {
		$interval = (int) self::get_option( 'purge_cron_interval', 10 );
		$unit     = (string) self::get_option( 'purge_cron_unit', 'HOUR_IN_SECONDS' );
		$seconds  = ( preg_match( '/^[A-Z_]+_IN_SECONDS$/', $unit ) && defined( $unit ) ) ? (int) constant( $unit ) : HOUR_IN_SECONDS;
		return max( 0, $interval * $seconds );
	}

	/** Root folder of WP Rocket's page cache files. */
	public static function cache_root() {
		if ( defined( 'WP_ROCKET_CACHE_PATH' ) ) { return trailingslashit( WP_ROCKET_CACHE_PATH ); }
		return trailingslashit( WP_CONTENT_DIR ) . 'cache/wp-rocket/';
	}
}
