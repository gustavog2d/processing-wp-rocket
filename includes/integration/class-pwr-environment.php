<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Checklist of the server-side pieces WP Rocket needs for the page cache and its queues to work.
 * Every check is read-only and based on files, constants and options WP Rocket itself uses.
 */
class PWR_Environment {
	const OK      = 'ok';
	const WARNING = 'warning';
	const ERROR   = 'error';
	const INFO    = 'info';

	/**
	 * @return array[] Each check: id, label, status (ok|warning|error|info), message.
	 */
	public static function get_checks() {
		if ( ! PWR_WPRocket_Adapter::is_active() ) {
			return [ self::check( 'wp_rocket', __( 'WP Rocket', 'processing-wp-rocket' ), self::ERROR, __( 'WP Rocket is not active.', 'processing-wp-rocket' ) ) ];
		}
		$checks   = [];
		$checks[] = self::check( 'wp_rocket', __( 'WP Rocket', 'processing-wp-rocket' ), self::INFO, sprintf( /* translators: %s: WP Rocket version */ __( 'Version %s active.', 'processing-wp-rocket' ), PWR_WPRocket_Adapter::version() ) );
		$checks[] = self::license();
		if ( PWR_WPRocket_Adapter::host_handles_page_cache() ) {
			// Managed host: WP Rocket deliberately skips WP_CACHE, advanced-cache.php, the config file,
			// cache files and .htaccess rules, so those checks would only raise false alarms.
			$host     = PWR_WPRocket_Adapter::host_name();
			$checks[] = self::check(
				'host_cache',
				__( 'Page cache', 'processing-wp-rocket' ),
				self::INFO,
				'' !== $host
					/* translators: %s: host identifier, e.g. "wpengine" */
					? sprintf( __( 'Handled by the hosting (%s). WP Rocket only optimizes the HTML here and writes no cache files, so WP_CACHE, advanced-cache.php, the cache config file and the [file] column do not apply.', 'processing-wp-rocket' ), $host )
					: __( 'Handled by the hosting. WP Rocket only optimizes the HTML here and writes no cache files, so WP_CACHE, advanced-cache.php, the cache config file and the [file] column do not apply.', 'processing-wp-rocket' )
			);
			$checks[] = self::writable_dirs();
		} else {
			$checks[] = self::wp_cache_constant();
			$checks[] = self::advanced_cache();
			$checks[] = self::config_file();
			$checks[] = self::writable_dirs();
			$checks[] = self::server_rules();
		}
		$checks[] = self::preload();
		$checks[] = self::queue_backlog();
		return array_values( array_filter( $checks ) );
	}

	/** Worst status among the checks, used to decide whether the panel opens expanded. */
	public static function worst_status( array $checks ) {
		$rank  = [ self::INFO => 0, self::OK => 0, self::WARNING => 1, self::ERROR => 2 ];
		$worst = self::OK;
		foreach ( $checks as $c ) {
			if ( $rank[ $c['status'] ] > $rank[ $worst ] ) { $worst = $c['status']; }
		}
		return $worst;
	}

	private static function check( $id, $label, $status, $message ) {
		return [ 'id' => $id, 'label' => $label, 'status' => $status, 'message' => $message ];
	}

	private static function license() {
		$label = __( 'License', 'processing-wp-rocket' );
		$valid = function_exists( 'rocket_valid_key' ) ? (bool) rocket_valid_key() : true;
		if ( ! $valid || get_option( 'wp_rocket_no_licence' ) ) {
			return self::check( 'license', $label, self::ERROR, __( 'License not validated. Without it WP Rocket does not load its front-end optimizations, and Remove Unused CSS, critical CSS and the performance hints stop working.', 'processing-wp-rocket' ) );
		}
		return self::check( 'license', $label, self::OK, __( 'License validated.', 'processing-wp-rocket' ) );
	}

	private static function wp_cache_constant() {
		$label = 'WP_CACHE';
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			return self::check( 'wp_cache', $label, self::OK, __( 'WP_CACHE is true, so WordPress loads advanced-cache.php.', 'processing-wp-rocket' ) );
		}
		return self::check( 'wp_cache', $label, self::ERROR, __( 'WP_CACHE is not true in wp-config.php: the page cache is never served by PHP and WP Rocket cannot cache pages.', 'processing-wp-rocket' ) );
	}

	private static function advanced_cache() {
		$label = 'advanced-cache.php';
		$file  = trailingslashit( WP_CONTENT_DIR ) . 'advanced-cache.php';
		if ( defined( 'WP_ROCKET_ADVANCED_CACHE_PROBLEM' ) ) {
			return self::check( 'advanced_cache', $label, self::ERROR, __( 'WP Rocket reported a problem in advanced-cache.php (plugin, config or cache folder missing, or PHP too old). Pages are not being cached.', 'processing-wp-rocket' ) );
		}
		if ( ! is_readable( $file ) ) {
			return self::check( 'advanced_cache', $label, self::ERROR, __( 'wp-content/advanced-cache.php does not exist. Save WP Rocket settings to regenerate it, and check that wp-content is writable.', 'processing-wp-rocket' ) );
		}
		$head = (string) file_get_contents( $file, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === strpos( $head, 'WP_ROCKET_ADVANCED_CACHE' ) ) {
			return self::check( 'advanced_cache', $label, self::ERROR, __( 'wp-content/advanced-cache.php belongs to another plugin or host, so WP Rocket\'s page cache is not the one serving pages.', 'processing-wp-rocket' ) );
		}
		if ( defined( 'WP_CACHE' ) && WP_CACHE && ! defined( 'WP_ROCKET_ADVANCED_CACHE' ) ) {
			return self::check( 'advanced_cache', $label, self::WARNING, __( 'The file is WP Rocket\'s, but it did not run on this request.', 'processing-wp-rocket' ) );
		}
		return self::check( 'advanced_cache', $label, self::OK, __( 'WP Rocket\'s drop-in is installed and loaded.', 'processing-wp-rocket' ) );
	}

	private static function config_file() {
		$label = __( 'Cache config file', 'processing-wp-rocket' );
		$dir   = defined( 'WP_ROCKET_CONFIG_PATH' ) ? trailingslashit( WP_ROCKET_CONFIG_PATH ) : trailingslashit( WP_CONTENT_DIR ) . 'wp-rocket-config/';
		$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$found = glob( $dir . $host . '*.php' );
		if ( empty( $found ) ) {
			return self::check( 'config_file', $label, self::ERROR, sprintf( /* translators: %s: expected file path */ __( '%s not found. advanced-cache.php needs it to decide what can be cached; saving WP Rocket settings regenerates it.', 'processing-wp-rocket' ), str_replace( WP_CONTENT_DIR, 'wp-content', $dir . $host . '.php' ) ) );
		}
		return self::check( 'config_file', $label, self::OK, sprintf( /* translators: %s: file name(s) */ __( 'Found: %s', 'processing-wp-rocket' ), implode( ', ', array_map( 'basename', $found ) ) ) );
	}

	private static function writable_dirs() {
		$label = __( 'Writable folders', 'processing-wp-rocket' );
		$root  = defined( 'WP_ROCKET_CACHE_ROOT_PATH' ) ? trailingslashit( WP_ROCKET_CACHE_ROOT_PATH ) : trailingslashit( WP_CONTENT_DIR ) . 'cache/';
		$dirs  = [
			PWR_WPRocket_Adapter::cache_root(),
			defined( 'WP_ROCKET_MINIFY_CACHE_PATH' ) ? WP_ROCKET_MINIFY_CACHE_PATH : $root . 'min/',
			defined( 'WP_ROCKET_USED_CSS_PATH' ) ? WP_ROCKET_USED_CSS_PATH : $root . 'used-css/',
			defined( 'WP_ROCKET_CRITICAL_CSS_PATH' ) ? WP_ROCKET_CRITICAL_CSS_PATH : $root . 'critical-css/',
			defined( 'WP_ROCKET_CACHE_BUSTING_PATH' ) ? WP_ROCKET_CACHE_BUSTING_PATH : $root . 'busting/',
			$root . 'fonts/',
		];
		$bad = [];
		foreach ( $dirs as $dir ) {
			// A folder that doesn't exist yet is fine as long as WP Rocket can create it.
			$probe = is_dir( $dir ) ? $dir : dirname( untrailingslashit( $dir ) );
			if ( ! wp_is_writable( $probe ) ) { $bad[] = str_replace( WP_CONTENT_DIR, 'wp-content', untrailingslashit( $dir ) ); }
		}
		if ( $bad ) {
			return self::check( 'writable_dirs', $label, self::ERROR, sprintf( /* translators: %s: list of folders */ __( 'Not writable by PHP: %s', 'processing-wp-rocket' ), implode( ', ', $bad ) ) );
		}
		return self::check( 'writable_dirs', $label, self::OK, __( 'Cache, minify, used CSS, critical CSS, busting and fonts folders are writable.', 'processing-wp-rocket' ) );
	}

	private static function server_rules() {
		global $is_apache, $is_nginx;
		$label = __( 'Server rewrite rules', 'processing-wp-rocket' );
		if ( $is_nginx ) {
			return self::check( 'server_rules', $label, self::INFO, __( 'Nginx detected. WP Rocket does not generate Nginx rules, so every cache hit goes through PHP (advanced-cache.php) unless the server has its own rules.', 'processing-wp-rocket' ) );
		}
		if ( ! $is_apache ) {
			return self::check( 'server_rules', $label, self::INFO, __( 'Server is neither Apache nor Nginx; cache files are served through PHP.', 'processing-wp-rocket' ) );
		}
		if ( apply_filters( 'rocket_disable_htaccess', false ) ) {
			return self::check( 'server_rules', $label, self::INFO, __( 'WP Rocket .htaccess rules are disabled by the rocket_disable_htaccess filter (common on managed hosts).', 'processing-wp-rocket' ) );
		}
		if ( ! function_exists( 'get_home_path' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
		$htaccess = get_home_path() . '.htaccess';
		$content  = is_readable( $htaccess ) ? (string) file_get_contents( $htaccess ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === strpos( $content, '# BEGIN WP Rocket' ) ) {
			return self::check( 'server_rules', $label, self::WARNING, __( 'Apache detected but .htaccess has no "# BEGIN WP Rocket" block: cache files are served through PHP instead of directly by Apache.', 'processing-wp-rocket' ) );
		}
		return self::check( 'server_rules', $label, self::OK, __( 'Apache serves cache files directly from .htaccess rules.', 'processing-wp-rocket' ) );
	}

	private static function preload() {
		$label = __( 'Preload', 'processing-wp-rocket' );
		if ( ! PWR_WPRocket_Adapter::get_option( 'manual_preload', 0 ) ) {
			return self::check( 'preload', $label, self::WARNING, __( 'Preload is off. WP Rocket only updates the wpr_rocket_cache table while preload is on, so the [cache] column below may be empty or stale. Use the [file] column instead.', 'processing-wp-rocket' ) );
		}
		return self::check( 'preload', $label, self::OK, __( 'Preload is on; the [cache] column reflects the preload queue.', 'processing-wp-rocket' ) );
	}

	private static function queue_backlog() {
		$label   = __( 'Background queue', 'processing-wp-rocket' );
		$status  = PWR_Cron_Status::get_status();
		$backlog = isset( $status['action_scheduler']['backlog'] ) ? $status['action_scheduler']['backlog'] : null;
		if ( null === $backlog ) {
			return self::check( 'queue', $label, self::INFO, __( 'Action Scheduler data not available.', 'processing-wp-rocket' ) );
		}
		$past_due = 0;
		foreach ( $backlog as $g ) { $past_due += (int) $g['past_due']; }
		if ( $past_due > 0 ) {
			$msg = sprintf( /* translators: %d: number of overdue actions */ _n( '%d WP Rocket action is overdue in Action Scheduler. The queue runner is not keeping up or not running (WP-Cron disabled without a system cron, blocked loopback requests, or PHP errors).', '%d WP Rocket actions are overdue in Action Scheduler. The queue runner is not keeping up or not running (WP-Cron disabled without a system cron, blocked loopback requests, or PHP errors).', $past_due, 'processing-wp-rocket' ), $past_due );
			return self::check( 'queue', $label, self::ERROR, $msg );
		}
		return self::check( 'queue', $label, self::OK, __( 'No overdue WP Rocket actions in Action Scheduler.', 'processing-wp-rocket' ) );
	}
}
