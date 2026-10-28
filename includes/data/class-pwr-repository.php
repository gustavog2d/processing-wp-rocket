<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reads WP Rocket's processing tables.
 *
 * WP Rocket's table/column schema can change between versions, so tables and columns are
 * detected at runtime (get_sources()) instead of being hardcoded. Pagination, sorting and
 * status filtering happen in SQL so the admin screen stays fast on sites with many URLs.
 */
class PWR_Repository {
	const HEALTH_TRANSIENT = 'pwr_health_report';

	/** Logical layers the UI knows how to display. */
	const LAYERS = [ 'cache', 'rucss', 'lazy', 'priority' ];

	/** Per-layer/device fields shown in the table and the health panel. */
	const FIELDS = [ 'cache', 'rucss_desktop', 'rucss_mobile', 'lazy_desktop', 'lazy_mobile', 'priority_desktop', 'priority_mobile' ];

	/**
	 * Raw status values (lowercase) mapped to the canonical set. WP Rocket itself writes
	 * `to-submit`, `pending`, `in-progress`, `completed` and `failed`.
	 */
	const STATUS_SYNONYMS = [
		'pending'     => [ 'pending', 'to-submit', 'to_submit', 'aguardando', 'pendente' ],
		'in_progress' => [ 'in_progress', 'in-progress', 'in progress', 'processing', 'in_process', 'processando', 'em processamento', 'queued', 'queue', 'running' ],
		'completed'   => [ 'completed', 'complete', 'done', 'ok', 'success', 'sucesso' ],
		'failed'      => [ 'failed', 'fail', 'error', 'erro' ],
	];

	/** Rank used when two rows resolve to the same field: the most actionable status wins. */
	const STATUS_RANK = [ 'failed' => 4, 'in_progress' => 3, 'pending' => 2, 'completed' => 1 ];

	/** @var array|null Memoized result of get_sources(). */
	private static $sources = null;

	public static function get_status_filters() {
		$map = [
			'pending'     => __( 'Pending', 'processing-wp-rocket' ),
			'in_progress' => __( 'In Progress', 'processing-wp-rocket' ),
			'completed'   => __( 'Completed', 'processing-wp-rocket' ),
			'failed'      => __( 'Failed', 'processing-wp-rocket' ),
		];
		return apply_filters( 'pwr_status_labels', $map );
	}

	/**
	 * Detects which candidate tables exist and which columns hold each piece of data.
	 *
	 * @return array[] One entry per usable table: table, raw, layer, cols (logical name => column or '').
	 */
	public static function get_sources( $refresh = false ) {
		if ( null !== self::$sources && ! $refresh ) {
			return self::$sources;
		}
		global $wpdb;
		$candidates = apply_filters( 'pwr_tables', [ 'wpr_rocket_cache', 'wpr_rucss_used_css', 'wpr_lazy_render_content', 'wpr_above_the_fold' ] );
		$col_map    = [
			'url'             => [ 'url', 'page_url', 'permalink', 'path', 'source', 'request_uri' ],
			'status'          => [ 'status', 'state', 'processing_status', 'result', 'flag' ],
			'updated'         => [ 'modified', 'updated', 'updated_at', 'last_update', 'last_updated', 'last_modified', 'date', 'timestamp', 'created_at' ],
			'device'          => [ 'is_mobile', 'device', 'context', 'form_factor', 'platform', 'ua', 'client' ],
			'error_code'      => [ 'error_code' ],
			'error_message'   => [ 'error_message' ],
			'retries'         => [ 'retries' ],
			'next_retry_time' => [ 'next_retry_time' ],
			'submitted_at'    => [ 'submitted_at' ],
		];
		$sources = [];
		foreach ( (array) $candidates as $raw ) {
			$raw   = (string) $raw;
			$table = $wpdb->prefix . $raw;
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) { continue; }
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) { continue; }
			$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" );
			if ( empty( $columns ) ) { continue; }
			$all  = array_map( static function ( $c ) { return isset( $c->Field ) ? (string) $c->Field : ''; }, $columns );
			$cols = [];
			foreach ( $col_map as $logical => $options ) {
				$cols[ $logical ] = self::first_existing( $all, $options );
			}
			if ( '' === $cols['url'] ) { continue; }
			$sources[] = [
				'table' => $table,
				'raw'   => $raw,
				'layer' => self::guess_layer( $raw ),
				'cols'  => $cols,
			];
		}
		self::$sources = $sources;
		return $sources;
	}

	/**
	 * One page of URLs for the list table, one row per URL with every layer/device status.
	 */
	public static function query_items( $args ) {
		$defaults = [ 'status' => '', 'search' => '', 'search_mode' => 'contains', 'orderby' => 'updated', 'order' => 'desc', 'paged' => 1, 'per_page' => 20 ];
		$args     = wp_parse_args( $args, $defaults );
		$override = apply_filters( 'pwr_repository_items', null, $args );
		if ( is_array( $override ) && isset( $override['items'], $override['total'] ) ) { return $override; }

		global $wpdb;
		$sources = self::get_sources();
		$status  = array_key_exists( (string) $args['status'], self::STATUS_SYNONYMS ) ? (string) $args['status'] : '';
		$union   = self::union_sql( $sources, (string) $args['search'], (string) $args['search_mode'], $status );
		if ( '' === $union ) { return [ 'items' => [], 'total' => 0 ]; }

		$order    = 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$order_by = 'url' === $args['orderby'] ? 'url' : 'MAX(updated)';
		$per      = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['paged'] - 1 ) * $per );

		// The union is already escaped/prepared; LIMIT/OFFSET are cast to int, so no second prepare().
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT url FROM ( {$union} ) u GROUP BY url ) c" );
		$page  = $wpdb->get_results( "SELECT url, MAX(updated) AS updated FROM ( {$union} ) u GROUP BY url ORDER BY {$order_by} {$order}, url ASC LIMIT {$per} OFFSET {$offset}", ARRAY_A );
		if ( $wpdb->last_error ) {
			return [ 'items' => [], 'total' => 0, 'error' => $wpdb->last_error ];
		}

		$urls  = array_values( array_filter( array_map( static function ( $r ) { return (string) $r['url']; }, (array) $page ) ) );
		$rows  = self::fetch_rows( $sources, $urls );
		$items = [];
		foreach ( $urls as $url ) {
			$items[] = self::build_item( $url, isset( $rows[ $url ] ) ? $rows[ $url ] : [] );
		}
		return [ 'items' => $items, 'total' => $total ];
	}

	/**
	 * Every layer/device status (with error details) for a single URL. Used by the inspector.
	 */
	public static function get_item_for_url( $url ) {
		$url      = (string) $url;
		$variants = array_values( array_unique( [ $url, untrailingslashit( $url ), trailingslashit( $url ) ] ) );
		$rows     = self::fetch_rows( self::get_sources(), $variants );
		$merged   = [];
		foreach ( $rows as $list ) { $merged = array_merge( $merged, $list ); }
		return self::build_item( untrailingslashit( $url ), $merged );
	}

	/**
	 * Site-wide status breakdown per layer/device, computed with GROUP BY queries.
	 */
	public static function get_health_report( $force_refresh = false ) {
		if ( $force_refresh ) { delete_transient( self::HEALTH_TRANSIENT ); }
		$cached = get_transient( self::HEALTH_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['fields'] ) ) {
			$cached['from_cache'] = true;
			return $cached;
		}
		global $wpdb;
		$buckets = [ 'pending', 'in_progress', 'completed', 'failed', 'other' ];
		$counts  = [];
		foreach ( self::FIELDS as $field ) { $counts[ $field ] = array_fill_keys( $buckets, 0 ); }

		$sources = self::get_sources();
		foreach ( $sources as $s ) {
			if ( ! in_array( $s['layer'], self::LAYERS, true ) ) { continue; }
			$status_expr = $s['cols']['status'] ? "LOWER(TRIM(`{$s['cols']['status']}`))" : "''";
			$device_expr = $s['cols']['device'] ? "`{$s['cols']['device']}`" : "''";
			$res         = $wpdb->get_results( "SELECT {$status_expr} AS status, {$device_expr} AS device, COUNT(DISTINCT `{$s['cols']['url']}`) AS n FROM `{$s['table']}` GROUP BY 1, 2", ARRAY_A );
			foreach ( (array) $res as $r ) {
				$device = self::map_device( (string) $r['device'] );
				$field  = 'cache' === $s['layer'] ? 'cache' : $s['layer'] . '_' . ( $device ? $device : 'desktop' );
				if ( ! isset( $counts[ $field ] ) ) { continue; }
				$norm   = self::normalize_status( (string) $r['status'] );
				$bucket = in_array( $norm, $buckets, true ) && 'other' !== $norm ? $norm : 'other';
				$counts[ $field ][ $bucket ] += (int) $r['n'];
			}
		}

		$union      = self::union_sql( $sources, '', 'contains', '' );
		$total_urls = '' === $union ? 0 : (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT url FROM ( {$union} ) u GROUP BY url ) c" );
		$cache_base = array_sum( $counts['cache'] );
		$labels     = self::field_labels();
		$fields     = [];
		foreach ( self::FIELDS as $field ) {
			$tracked     = array_sum( $counts[ $field ] );
			$percentages = [];
			foreach ( $buckets as $b ) {
				$percentages[ $b ] = $tracked > 0 ? round( ( $counts[ $field ][ $b ] / $tracked ) * 100, 1 ) : 0.0;
			}
			$fields[ $field ] = [
				'label'       => isset( $labels[ $field ] ) ? $labels[ $field ] : $field,
				'tracked'     => $tracked,
				'counts'      => $counts[ $field ],
				'percentages' => $percentages,
				// Share of cached URLs that also have a row in this layer (null for the cache row itself).
				'coverage'    => ( 'cache' !== $field && $cache_base > 0 ) ? round( min( 100, ( $tracked / $cache_base ) * 100 ), 1 ) : null,
			];
		}
		$report = [ 'total_urls' => $total_urls, 'fields' => $fields, 'generated_at' => time() ];
		set_transient( self::HEALTH_TRANSIENT, $report, (int) apply_filters( 'pwr_health_cache_ttl', 5 * MINUTE_IN_SECONDS ) );
		$report['from_cache'] = false;
		return $report;
	}

	public static function normalize_status( $val ) {
		$v = strtolower( trim( (string) $val ) );
		if ( '' === $v ) { return ''; }
		foreach ( self::STATUS_SYNONYMS as $slug => $synonyms ) {
			if ( in_array( $v, $synonyms, true ) ) { return $slug; }
		}
		return $v;
	}

	public static function label_for_status( $slug ) {
		$slug   = self::normalize_status( $slug );
		$labels = self::get_status_filters();
		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug;
	}

	public static function map_device( $raw ) {
		$val = strtolower( trim( (string) $raw ) );
		if ( '' === $val ) { return ''; }
		if ( in_array( $val, [ 'mobile', 'm', 'phone', 'handset', 'tablet', '1', 'true', 'yes' ], true ) ) { return 'mobile'; }
		if ( in_array( $val, [ 'desktop', 'd', 'pc', '0', 'false', 'no' ], true ) ) { return 'desktop'; }
		if ( false !== strpos( $val, 'desktop' ) ) { return 'desktop'; }
		if ( false !== strpos( $val, 'mobile' ) ) { return 'mobile'; }
		return '';
	}

	/** True for empty values and MySQL zero dates, which WP Rocket uses as column defaults. */
	public static function is_empty_date( $value ) {
		$value = trim( (string) $value );
		return '' === $value || 0 === strpos( $value, '0000-00-00' );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * UNION ALL of (url, updated) across all sources with search/status filters applied.
	 * Values are escaped through $wpdb->prepare(); the result must not be prepared again.
	 */
	private static function union_sql( array $sources, $search, $search_mode, $status ) {
		global $wpdb;
		$parts = [];
		foreach ( $sources as $s ) {
			$url_col = "`{$s['cols']['url']}`";
			$where   = '1=1';
			if ( '' !== $search ) {
				if ( 'exact' === $search_mode ) {
					// WP Rocket stores URLs without the trailing slash; accept both spellings.
					$where .= $wpdb->prepare( " AND {$url_col} IN (%s, %s)", untrailingslashit( $search ), trailingslashit( $search ) );
				} else {
					$where .= $wpdb->prepare( " AND {$url_col} LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' );
				}
			}
			if ( '' !== $status ) {
				if ( ! $s['cols']['status'] ) { continue; }
				$raws         = self::STATUS_SYNONYMS[ $status ];
				$placeholders = implode( ', ', array_fill( 0, count( $raws ), '%s' ) );
				$where       .= $wpdb->prepare( " AND LOWER(TRIM(`{$s['cols']['status']}`)) IN ({$placeholders})", $raws );
			}
			$updated = $s['cols']['updated'] ? "`{$s['cols']['updated']}`" : 'NULL';
			// CAST AS BINARY: tables created at different times (or after a DB migration) can have
			// different collations, and UNION refuses to mix them ("Illegal mix of collations").
			$parts[] = "SELECT CAST({$url_col} AS BINARY) AS url, {$updated} AS updated FROM `{$s['table']}` WHERE {$where}";
		}
		return implode( ' UNION ALL ', $parts );
	}

	/**
	 * Raw rows for a set of URLs, grouped by URL.
	 *
	 * @return array<string, array[]>
	 */
	private static function fetch_rows( array $sources, array $urls ) {
		global $wpdb;
		$out = [];
		if ( empty( $urls ) ) { return $out; }
		$placeholders = implode( ', ', array_fill( 0, count( $urls ), '%s' ) );
		foreach ( $sources as $s ) {
			$selects = [ "`{$s['cols']['url']}` AS url", "'" . esc_sql( $s['layer'] ) . "' AS layer" ];
			foreach ( [ 'status', 'updated', 'device', 'error_code', 'error_message', 'retries', 'next_retry_time', 'submitted_at' ] as $logical ) {
				$selects[] = $s['cols'][ $logical ] ? "`{$s['cols'][ $logical ]}` AS {$logical}" : "'' AS {$logical}";
			}
			$sql = $wpdb->prepare( 'SELECT ' . implode( ', ', $selects ) . " FROM `{$s['table']}` WHERE `{$s['cols']['url']}` IN ({$placeholders})", $urls );
			foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) {
				$out[ (string) $r['url'] ][] = $r;
			}
		}
		return $out;
	}

	/**
	 * Collapses raw rows of one URL into the item shape used by the list table and inspector.
	 */
	private static function build_item( $url, array $rows ) {
		$item = [ 'url' => $url, 'updated' => '', 'errors' => [], 'raw' => [] ];
		foreach ( self::FIELDS as $field ) {
			$item[ $field ]              = '';
			$item[ $field . '_updated' ] = '';
		}
		foreach ( $rows as $r ) {
			if ( ! in_array( $r['layer'], self::LAYERS, true ) ) { continue; }
			$device = self::map_device( (string) $r['device'] );
			$field  = 'cache' === $r['layer'] ? 'cache' : $r['layer'] . '_' . ( $device ? $device : 'desktop' );
			$status = (string) $r['status'];
			$upd    = self::is_empty_date( $r['updated'] ) ? '' : (string) $r['updated'];

			$item[ $field ] = self::pick_status( $item[ $field ], $status );
			if ( $upd && ( '' === $item[ $field . '_updated' ] || strtotime( $upd ) > strtotime( $item[ $field . '_updated' ] ) ) ) {
				$item[ $field . '_updated' ] = $upd;
			}
			if ( $upd && ( '' === $item['updated'] || strtotime( $upd ) > strtotime( $item['updated'] ) ) ) {
				$item['updated'] = $upd;
			}
			$message = trim( (string) $r['error_message'] );
			$code    = trim( (string) $r['error_code'] );
			if ( '' !== $message || '' !== $code ) {
				$item['errors'][ $field ] = [
					'code'       => $code,
					'message'    => $message,
					'retries'    => (string) $r['retries'],
					'next_retry' => self::is_empty_date( $r['next_retry_time'] ) ? '' : (string) $r['next_retry_time'],
				];
			}
			$item['raw'][ $field ] = $r;
		}
		return $item;
	}

	public static function field_labels() {
		$labels = [
			'cache'            => __( 'Page Cache (preload queue)', 'processing-wp-rocket' ),
			'rucss_desktop'    => __( 'Remove Unused CSS (Desktop)', 'processing-wp-rocket' ),
			'rucss_mobile'     => __( 'Remove Unused CSS (Mobile)', 'processing-wp-rocket' ),
			'lazy_desktop'     => __( 'Lazy Render (Desktop)', 'processing-wp-rocket' ),
			'lazy_mobile'      => __( 'Lazy Render (Mobile)', 'processing-wp-rocket' ),
			'priority_desktop' => __( 'Priority Elements (Desktop)', 'processing-wp-rocket' ),
			'priority_mobile'  => __( 'Priority Elements (Mobile)', 'processing-wp-rocket' ),
		];
		return apply_filters( 'pwr_health_field_labels', $labels );
	}

	private static function first_existing( array $all, array $priority ) {
		foreach ( $priority as $c ) { if ( in_array( $c, $all, true ) ) { return $c; } }
		return '';
	}

	private static function pick_status( $current, $incoming ) {
		$cur = self::normalize_status( $current );
		$inc = self::normalize_status( $incoming );
		if ( '' === $inc ) { return $current; }
		if ( '' === $cur ) { return $incoming; }
		$rc = isset( self::STATUS_RANK[ $cur ] ) ? self::STATUS_RANK[ $cur ] : 0;
		$ri = isset( self::STATUS_RANK[ $inc ] ) ? self::STATUS_RANK[ $inc ] : 0;
		return ( $ri > $rc ) ? $incoming : $current;
	}

	private static function guess_layer( $raw ) {
		if ( false !== strpos( $raw, 'rucss' ) ) { return 'rucss'; }
		if ( false !== strpos( $raw, 'lazy_render' ) ) { return 'lazy'; }
		if ( false !== strpos( $raw, 'above_the_fold' ) ) { return 'priority'; }
		if ( false !== strpos( $raw, 'rocket_cache' ) ) { return 'cache'; }
		// Unknown tables added through `pwr_tables` keep their own name and are not mapped to a column.
		return sanitize_key( $raw );
	}
}
