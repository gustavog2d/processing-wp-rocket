<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Looks at what WP Rocket actually wrote to disk for a URL.
 *
 * Every optimization step in WP Rocket's `rocket_buffer` pipeline leaves a marker
 * (`<!-- wpr_<feature> -->`), and the Support module turns those markers into
 * `<meta name="generator" content="WP Rocket x.y" data-wpr-features="...">` in the cached
 * file. Comparing that list with the enabled settings shows what really reached the page.
 */
class PWR_Cache_Inspector {

	/**
	 * Directory WP Rocket uses for a URL, mirroring WP_Rocket\Buffer\Cache::get_request_cache_path()
	 * for anonymous visitors.
	 *
	 * Returns '' for anything that is not a page of this site: the host must be one of this site's
	 * hosts, `.`/`..` segments are refused, and the final path must stay inside WP Rocket's cache root.
	 * The URL comes from the request (`?pwr_inspect=`), so this is the only gate before filesystem reads.
	 */
	public static function dir_for_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) { return ''; }
		$host = trim( strtolower( (string) $parts['host'] ), '.' );
		if ( '' === $host || ! in_array( $host, self::allowed_hosts(), true ) ) { return ''; }
		if ( apply_filters( 'rocket_url_no_dots', false ) ) {
			$host = str_replace( '.', '_', $host );
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		foreach ( explode( '/', rawurldecode( $path ) ) as $segment ) {
			if ( '.' === $segment || '..' === $segment || false !== strpos( $segment, '\\' ) || false !== strpos( $segment, "\0" ) ) {
				return '';
			}
		}
		$path = preg_replace_callback( '/%[0-9A-F]{2}/', static function ( $m ) { return strtolower( $m[0] ); }, $path );
		$path = rtrim( $path, '/' );
		$dir  = PWR_WPRocket_Adapter::cache_root() . rawurlencode( $host ) . $path;
		return self::is_within_cache_root( $dir ) ? $dir : '';
	}

	/**
	 * Hosts this site answers on (home and site URL). Other sites of a multisite network are
	 * excluded on purpose, so a subsite admin cannot inspect another subsite's cache.
	 *
	 * @return string[]
	 */
	public static function allowed_hosts() {
		$hosts = [];
		foreach ( [ home_url(), site_url() ] as $u ) {
			$h = wp_parse_url( $u, PHP_URL_HOST );
			if ( $h ) { $hosts[] = trim( strtolower( (string) $h ), '.' ); }
		}
		return array_values( array_unique( (array) apply_filters( 'pwr_inspect_allowed_hosts', $hosts ) ) );
	}

	/**
	 * True when $path (existing or not) resolves inside WP Rocket's cache root, symlinks included.
	 */
	private static function is_within_cache_root( $path ) {
		$root = realpath( PWR_WPRocket_Adapter::cache_root() );
		if ( false === $root ) { return false; }
		$root = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR;
		// Resolve the deepest existing ancestor; the rest of the path has no `..` by construction.
		$probe = $path;
		while ( false === realpath( $probe ) ) {
			$parent = dirname( $probe );
			if ( $parent === $probe ) { return false; }
			$probe = $parent;
		}
		$real = rtrim( (string) realpath( $probe ), '/\\' ) . DIRECTORY_SEPARATOR;
		return 0 === strpos( $real, $root );
	}

	/**
	 * Values of the WP Rocket generator meta tag, found with strpos() and parsed on that tag only
	 * (a regex over the whole file could backtrack for seconds on large or malformed HTML).
	 *
	 * @return string[]|null Feature markers, or null when the tag is absent.
	 */
	private static function parse_features( $html ) {
		// Bounded work: at most 20 candidates, each looked at within a 512 + 2048 byte window,
		// so a large or malformed file can never cost more than a few kilobytes of scanning.
		$offset   = 0;
		$attempts = 0;
		while ( $attempts++ < 20 && false !== ( $pos = stripos( $html, 'data-wpr-features', $offset ) ) ) {
			$offset = $pos + 17;
			$base   = max( 0, $pos - 512 );
			$start  = strrpos( substr( $html, $base, $pos - $base ), '<' );
			$end    = strpos( substr( $html, $pos, 2048 ), '>' );
			if ( false === $start || false === $end ) { continue; }
			$tag = substr( $html, $base + $start, ( $pos + $end ) - ( $base + $start ) + 1 );
			if ( 0 !== stripos( $tag, '<meta' ) || ! preg_match( '/\bname\s*=\s*["\']generator["\']/i', $tag ) ) { continue; }
			if ( preg_match( '/data-wpr-features\s*=\s*["\']([^"\']*)["\']/i', $tag, $m ) ) {
				return array_values( array_filter( preg_split( '/\s+/', trim( $m[1] ) ) ) );
			}
		}
		return null;
	}

	/**
	 * Expected file names for the default anonymous visit (no WebP, no dynamic cookies).
	 *
	 * @return array{desktop:string, mobile:string} mobile is '' when mobile pages share the desktop file.
	 */
	public static function expected_files( $url ) {
		$scheme = wp_parse_url( (string) $url, PHP_URL_SCHEME );
		$https  = ( 'https' === $scheme && PWR_WPRocket_Adapter::get_option( 'cache_ssl', 1 ) ) ? '-https' : '';
		$mobile = PWR_WPRocket_Adapter::get_option( 'cache_mobile', 0 ) && PWR_WPRocket_Adapter::get_option( 'do_caching_mobile_files', 0 );
		return [
			'desktop' => 'index' . $https . '.html',
			'mobile'  => $mobile ? 'index-mobile' . $https . '.html' : '',
		];
	}

	/**
	 * Cheap per-row check for the list table: modification time of the desktop/mobile files.
	 *
	 * @return array{desktop:int|null, mobile:int|null|false} false = mobile cache not separated.
	 */
	public static function file_summary( $url ) {
		$dir      = self::dir_for_url( $url );
		$expected = self::expected_files( $url );
		$out      = [ 'desktop' => null, 'mobile' => false ];
		if ( '' === $dir ) { return $out; }
		$desktop        = $dir . '/' . $expected['desktop'];
		$out['desktop'] = is_readable( $desktop ) ? (int) filemtime( $desktop ) : null;
		if ( '' !== $expected['mobile'] ) {
			$mobile        = $dir . '/' . $expected['mobile'];
			$out['mobile'] = is_readable( $mobile ) ? (int) filemtime( $mobile ) : null;
		}
		return $out;
	}

	/**
	 * Full report for one URL: files on disk, features applied vs expected, per-post exclusions.
	 */
	public static function inspect( $url ) {
		$url      = (string) $url;
		$dir      = self::dir_for_url( $url );
		$expected = self::expected_files( $url );
		$lifespan = PWR_WPRocket_Adapter::cache_lifespan();
		$report   = [
			'url'        => $url,
			'dir'        => $dir,
			'lifespan'   => $lifespan,
			'files'      => [],
			'analyzed'   => null,
			'features'   => [],
			'found'      => [],
			'cached_at'  => null,
			'meta_found' => false,
			'notes'      => [],
			'post_id'    => 0,
			'excluded'   => [],
		];

		if ( '' === $dir ) {
			$report['notes'][] = __( 'This URL does not belong to this site, so its cache files were not read.', 'processing-wp-rocket' );
		} elseif ( is_dir( $dir ) ) {
			$files = glob( $dir . '/index*.html' );
			foreach ( (array) $files as $file ) {
				$name  = basename( $file );
				$mtime = (int) filemtime( $file );
				$report['files'][] = [
					'name'       => $name,
					'path'       => $file,
					'size'       => (int) filesize( $file ),
					'gzip'       => is_readable( $file . '_gzip' ),
					'mtime'      => $mtime,
					'expires_at' => $lifespan > 0 ? $mtime + $lifespan : 0,
					'variant'    => self::describe_variant( $name ),
				];
			}
		}

		// Analyze the default desktop file, falling back to any file found.
		$target = '';
		foreach ( $report['files'] as $f ) {
			if ( $f['name'] === $expected['desktop'] ) { $target = $f['path']; break; }
		}
		if ( '' === $target && ! empty( $report['files'] ) ) { $target = $report['files'][0]['path']; }

		if ( '' !== $target ) {
			$report['analyzed'] = basename( $target );
			$max                = (int) apply_filters( 'pwr_inspect_max_bytes', 8 * MB_IN_BYTES );
			$html               = (string) file_get_contents( $target, false, null, 0, $max ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$features = self::parse_features( $html );
			if ( null !== $features ) {
				$report['meta_found'] = true;
				$report['found']      = $features;
			}
			if ( preg_match( '/cached@(\d{9,11})/', $html, $m ) ) {
				$report['cached_at'] = (int) $m[1];
			}
			if ( ! $report['meta_found'] ) {
				$report['notes'][] = __( 'No data-wpr-features meta tag found in the cached file. WP Rocket omits it when white label is active, when the rocket_disable_meta_generator filter returns true, or when the page was generated with optimizations disabled (DONOTROCKETOPTIMIZE).', 'processing-wp-rocket' );
			}
		}

		$report['post_id']  = self::post_id_for_url( $url );
		$report['excluded'] = self::post_exclusions( $report['post_id'] );
		$analyzed_mtime     = '' !== $target ? (int) filemtime( $target ) : 0;
		$report['features'] = self::compare_features( $report['found'], $report['excluded'], $report['meta_found'], PWR_Repository::get_item_for_url( $url ), $analyzed_mtime );
		return $report;
	}

	/**
	 * Builds the expected-vs-applied feature table.
	 *
	 * kind: `config` = should appear whenever enabled and the page has matching content;
	 *       `data`   = also depends on data collected by WP Rocket's beacon/SaaS for this URL.
	 */
	private static function compare_features( array $found, array $excluded, $meta_found, array $item = [], $file_mtime = 0 ) {
		// Markers whose data lives in a WP Rocket table: tells "waiting for data" apart from
		// "data ready, but the cached page was generated before it".
		$data_fields = [
			'wpr_remove_unused_css'        => 'rucss_desktop',
			'wpr_oci'                      => 'priority_desktop',
			'wpr_automatic_lazy_rendering' => 'lazy_desktop',
		];
		$opt   = [ 'PWR_WPRocket_Adapter', 'get_option' ];
		$delay = (bool) call_user_func( $opt, 'delay_js', 0 );
		$rucss = (bool) call_user_func( $opt, 'remove_unused_css', 0 );
		$defs  = [
			'wpr_remove_unused_css'           => [ __( 'Remove Unused CSS', 'processing-wp-rocket' ), 'remove_unused_css', $rucss, 'data' ],
			'wpr_async_css'                   => [ __( 'Load CSS asynchronously (critical CSS)', 'processing-wp-rocket' ), 'async_css', ! $rucss && (bool) call_user_func( $opt, 'async_css', 0 ), 'data' ],
			'wpr_minify_css'                  => [ __( 'Minify CSS', 'processing-wp-rocket' ), 'minify_css', (bool) call_user_func( $opt, 'minify_css', 0 ), 'config' ],
			'wpr_minify_js'                   => [ __( 'Minify JavaScript', 'processing-wp-rocket' ), 'minify_js', (bool) call_user_func( $opt, 'minify_js', 0 ), 'config' ],
			'wpr_minify_concatenate_js'       => [ __( 'Combine JavaScript', 'processing-wp-rocket' ), 'minify_concatenate_js', ! $delay && (bool) call_user_func( $opt, 'minify_concatenate_js', 0 ), 'config' ],
			'wpr_defer_js'                    => [ __( 'Load JavaScript deferred', 'processing-wp-rocket' ), 'defer_all_js', (bool) call_user_func( $opt, 'defer_all_js', 0 ), 'config' ],
			'wpr_delay_js'                    => [ __( 'Delay JavaScript execution', 'processing-wp-rocket' ), 'delay_js', $delay, 'config' ],
			'wpr_lazyload_images'             => [ __( 'LazyLoad images', 'processing-wp-rocket' ), 'lazyload', (bool) call_user_func( $opt, 'lazyload', 0 ), 'config' ],
			'wpr_lazyload_iframes'            => [ __( 'LazyLoad iframes and videos', 'processing-wp-rocket' ), 'lazyload_iframes', (bool) call_user_func( $opt, 'lazyload_iframes', 0 ), 'config' ],
			'wpr_lazyload_css_bg_img'         => [ __( 'LazyLoad CSS background images', 'processing-wp-rocket' ), 'lazyload_css_bg_img', (bool) call_user_func( $opt, 'lazyload_css_bg_img', 0 ), 'config' ],
			'wpr_image_dimensions'            => [ __( 'Add missing image dimensions', 'processing-wp-rocket' ), 'image_dimensions', (bool) call_user_func( $opt, 'image_dimensions', 0 ), 'config' ],
			'wpr_cdn'                         => [ __( 'CDN rewriting', 'processing-wp-rocket' ), 'cdn', (bool) call_user_func( $opt, 'cdn', 0 ), 'config' ],
			'wpr_preload_links'               => [ __( 'Preload links', 'processing-wp-rocket' ), 'preload_links', (bool) call_user_func( $opt, 'preload_links', 0 ), 'config' ],
			'wpr_host_fonts_locally'          => [ __( 'Self-host Google Fonts', 'processing-wp-rocket' ), 'host_fonts_locally', (bool) call_user_func( $opt, 'host_fonts_locally', 0 ), 'config' ],
			'wpr_cache_webp'                  => [ __( 'Separate WebP cache', 'processing-wp-rocket' ), 'cache_webp', (bool) call_user_func( $opt, 'cache_webp', 0 ), 'data' ],
			'wpr_oci'                         => [ __( 'Optimize critical images (LCP / above the fold)', 'processing-wp-rocket' ), '', (bool) apply_filters( 'rocket_above_the_fold_optimization', true ), 'data' ],
			'wpr_automatic_lazy_rendering'    => [ __( 'Automatic lazy rendering', 'processing-wp-rocket' ), '', (bool) apply_filters( 'rocket_lrc_optimization', true ), 'data' ],
			'wpr_preconnect_external_domains' => [ __( 'Preconnect to external domains', 'processing-wp-rocket' ), '', (bool) apply_filters( 'rocket_preconnect_external_domains_optimization', true ), 'data' ],
			'wpr_auto_preload_fonts'          => [ __( 'Automatic font preload', 'processing-wp-rocket' ), 'auto_preload_fonts', (bool) call_user_func( $opt, 'auto_preload_fonts', 0 ), 'data' ],
		];

		$rows = [];
		foreach ( $defs as $feature => $def ) {
			list( $label, $option, $enabled, $kind ) = $def;
			$applied     = in_array( $feature, $found, true );
			$is_excluded = '' !== $option && in_array( $option, $excluded, true );
			if ( ! $meta_found ) {
				$state = $enabled ? 'unknown' : 'off';
			} elseif ( $applied ) {
				$state = $enabled ? 'applied' : 'applied_unexpected';
			} elseif ( ! $enabled ) {
				$state = 'off';
			} elseif ( $is_excluded ) {
				$state = 'excluded';
			} elseif ( 'data' === $kind ) {
				$state = 'waiting';
				if ( isset( $data_fields[ $feature ], $item[ $data_fields[ $feature ] ] ) && 'completed' === PWR_Repository::normalize_status( $item[ $data_fields[ $feature ] ] ) ) {
					$data_time = strtotime( (string) $item[ $data_fields[ $feature ] . '_updated' ] );
					// Data finished after this file was written: the next cache generation should pick it up.
					$state = ( $data_time && $file_mtime && $data_time > $file_mtime ) ? 'stale' : 'missing';
				} elseif ( isset( $data_fields[ $feature ], $item[ $data_fields[ $feature ] ] ) && 'failed' === PWR_Repository::normalize_status( $item[ $data_fields[ $feature ] ] ) ) {
					$state = 'data_failed';
				}
			} else {
				$state = 'missing';
			}
			$rows[] = [ 'feature' => $feature, 'label' => $label, 'option' => $option, 'enabled' => $enabled, 'kind' => $kind, 'state' => $state ];
		}
		// Markers present in the page that this plugin doesn't know yet (newer WP Rocket versions).
		foreach ( $found as $feature ) {
			if ( isset( $defs[ $feature ] ) || in_array( $feature, [ 'wpr_mobile', 'wpr_desktop', 'wpr_dns_prefetch' ], true ) ) { continue; }
			$rows[] = [ 'feature' => $feature, 'label' => $feature, 'option' => '', 'enabled' => true, 'kind' => 'config', 'state' => 'applied' ];
		}
		return $rows;
	}

	private static function describe_variant( $name ) {
		$rest  = preg_replace( '/^index|\.html$/', '', $name );
		$parts = [];
		if ( 0 === strpos( $rest, '-mobile' ) ) {
			$parts[] = __( 'mobile', 'processing-wp-rocket' );
			$rest    = substr( $rest, strlen( '-mobile' ) );
		} else {
			$parts[] = __( 'desktop', 'processing-wp-rocket' );
		}
		foreach ( [ '-https' => 'https', '-webp' => 'webp' ] as $suffix => $label ) {
			if ( 0 === strpos( $rest, $suffix ) ) {
				$parts[] = $label;
				$rest    = substr( $rest, strlen( $suffix ) );
			}
		}
		// Anything left comes from cookies declared in `rocket_cache_dynamic_cookies`.
		if ( '' !== $rest ) {
			$parts[] = __( 'dynamic cookie', 'processing-wp-rocket' );
		}
		return implode( ' · ', $parts );
	}

	private static function post_id_for_url( $url ) {
		if ( untrailingslashit( $url ) === untrailingslashit( home_url() ) ) {
			return 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		}
		return (int) url_to_postid( $url );
	}

	/**
	 * Options disabled for this post in WP Rocket's editor metabox (post meta `_rocket_exclude_{option}`).
	 */
	private static function post_exclusions( $post_id ) {
		if ( ! $post_id ) { return []; }
		$out = [];
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( 0 === strpos( (string) $key, '_rocket_exclude_' ) && ! empty( $values[0] ) ) {
				$out[] = substr( (string) $key, strlen( '_rocket_exclude_' ) );
			}
		}
		return $out;
	}
}
