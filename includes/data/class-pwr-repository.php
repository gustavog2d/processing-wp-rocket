<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class PWR_Repository {
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
	 * Return rows grouped by CANONICAL URL (scheme+host+path). One line per page URL,
	 * aggregating Desktop/Mobile statuses for layers: cache, rucss, lazy, priority.
	 */
	public static function query_items( $args ) {
		global $wpdb;
		$defaults = [ 'status'=>'', 'search'=>'', 'orderby'=>'updated', 'order'=>'desc', 'paged'=>1, 'per_page'=>20 ];
		$args = wp_parse_args( $args, $defaults );
		$override = apply_filters( 'pwr_repository_items', null, $args );
		if ( is_array( $override ) && isset( $override['items'], $override['total'] ) ) { return $override; }
		$candidates = apply_filters( 'pwr_tables', [ 'wpr_rucss_used_css','wpr_rocket_cache','wpr_lazy_render_content','wpr_above_the_fold' ] );
		$url_cols     = [ 'url','page_url','permalink','path','source','request_uri' ];
		$status_cols  = [ 'status','state','processing_status','result','flag' ];
		$updated_cols = [ 'updated','updated_at','last_update','last_updated','last_modified','modified','date','timestamp','created_at' ];
		$device_cols  = [ 'device','context','form_factor','platform','is_mobile','ua','client' ];
		$rows = []; $debug = [ 'detected'=>[] ];
		foreach ( $candidates as $raw ) {
			$table = $wpdb->prefix . $raw;
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) continue;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) continue;
			$cols = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" );
			if ( empty( $cols ) ) continue;
			$all = array_map( static function( $c ){ return isset( $c->Field ) ? (string) $c->Field : ''; }, $cols );
			$col_url     = self::first_existing( $all, $url_cols );
			$col_status  = self::first_existing( $all, $status_cols );
			$col_updated = self::first_existing( $all, $updated_cols );
			$col_device  = self::first_existing( $all, $device_cols );
			if ( ! $col_url ) continue;
			$where='1=1'; $params=[];
			if ( ! empty( $args['search'] ) ) { $where.=" AND `{$table}`.`{$col_url}` LIKE %s"; $params[]='%'.$wpdb->esc_like( $args['search'] ).'%'; }
			$selects = [
				"`{$table}`.`{$col_url}` AS url",
				$col_status  ? "`{$table}`.`{$col_status}` AS status"   : "'' AS status",
				$col_updated ? "`{$table}`.`{$col_updated}` AS updated" : "'' AS updated",
				$col_device  ? "`{$table}`.`{$col_device}` AS device"   : "'' AS device",
				"'". esc_sql( self::guess_layer( $raw ) ) ."' AS layer",
			];
			$sql = "SELECT ".implode(', ',$selects)." FROM `{$table}` WHERE {$where}";
			$sql = $wpdb->prepare( $sql, $params );
			$res = (array) $wpdb->get_results( $sql, ARRAY_A );
			foreach ( $res as $r ) { $rows[] = $r; }
			$debug['detected'][] = [ 'table'=>$table,'layer'=>self::guess_layer($raw),'url_col'=>$col_url,'status_col'=>$col_status,'updated_col'=>$col_updated,'device_col'=>$col_device,'count'=>count($res) ];
		}
		// Group by canonical URL (scheme+host+path), ignoring query/fragment; lowercase host.
		$groups = [];
		foreach ( $rows as $r ) {
			$full = isset($r['url'])?(string)$r['url']:''; if ($full==='') continue;
			$key = self::canonical_key($full); if ($key==='') continue;
			if ( ! isset($groups[$key]) ) {
				$groups[$key] = [ 'url'=>$full,
					'cache'=>'','rucss_desktop'=>'','rucss_mobile'=>'',
					'lazy_desktop'=>'','lazy_mobile'=>'','priority_desktop'=>'','priority_mobile'=>'','updated'=>'' ];
			}
			$device = self::map_device( isset($r['device'])?(string)$r['device']:'' ); if (!$device) $device='desktop';
			$layer  = isset($r['layer'])?(string)$r['layer']:'cache';
			$status = isset($r['status'])?(string)$r['status']:'';
			$upd    = isset($r['updated'])?(string)$r['updated']:'';
			$field = ( $layer === 'cache' ) ? 'cache' : ( $layer . '_' . $device );
			$groups[$key][$field] = self::pick_status( $groups[$key][$field], $status );

            // Track per-field latest updated date
            if ( ! isset( $groups[$key][ $field . '_updated' ] ) ) { $groups[$key][ $field . '_updated' ] = ''; }
            if ( $upd && ( empty( $groups[$key][ $field . '_updated' ] ) || strtotime( $upd ) > strtotime( $groups[$key][ $field . '_updated' ] ) ) ) {
                $groups[$key][ $field . '_updated' ] = $upd;
            }
			if ( $upd && ( empty($groups[$key]['updated']) || strtotime($upd) > strtotime($groups[$key]['updated']) ) ) {
				$groups[$key]['updated'] = $upd;
			}
		}
		$list = []; $filter = (string)$args['status'];
		foreach ( $groups as $g ) {
			if ( $filter ) {
				$has=false; foreach (['cache','rucss_desktop','rucss_mobile','lazy_desktop','lazy_mobile','priority_desktop','priority_mobile'] as $fld) { if ( isset($g[$fld]) && $g[$fld]===$filter ) { $has=true; break; } }
				if ( ! $has ) continue;
			}
			$list[] = array_merge([
            'cache_desktop_updated' => isset($g['cache_desktop_updated']) ? $g['cache_desktop_updated'] : '',
            'cache_mobile_updated'  => isset($g['cache_mobile_updated']) ? $g['cache_mobile_updated'] : '',
            'rucss_desktop_updated' => isset($g['rucss_desktop_updated']) ? $g['rucss_desktop_updated'] : '',
            'rucss_mobile_updated'  => isset($g['rucss_mobile_updated']) ? $g['rucss_mobile_updated'] : '',
            'lazy_desktop_updated'  => isset($g['lazy_desktop_updated']) ? $g['lazy_desktop_updated'] : '',
            'lazy_mobile_updated'   => isset($g['lazy_mobile_updated']) ? $g['lazy_mobile_updated'] : '',
            'priority_desktop_updated' => isset($g['priority_desktop_updated']) ? $g['priority_desktop_updated'] : '',
            'priority_mobile_updated'  => isset($g['priority_mobile_updated']) ? $g['priority_mobile_updated'] : '',
        ], $g);
		}
		$orderby = in_array( $args['orderby'], [ 'url','updated' ], true ) ? $args['orderby'] : 'updated';
		usort( $list, function( $a, $b ) use ( $orderby, $args ) {
			$ord = strtolower( $args['order'] ) === 'asc' ? 1 : -1;
			$va = $a[ $orderby ] ?? ''; $vb = $b[ $orderby ] ?? '';
			if ( $orderby === 'updated' ) { return ( strtotime( $va ) <=> strtotime( $vb ) ) * $ord; }
			return ( $va <=> $vb ) * $ord;
		});
		$total = count($list); $per=(int)$args['per_page']; $off=max(0,((int)$args['paged']-1)*$per);
		$items = array_slice($list,$off,$per);
		if ( isset($_GET['pwr_debug']) && current_user_can( pwr_min_cap() ) ) { return [ 'items'=>$items, 'total'=>$total, 'debug'=>$debug ]; }
		return [ 'items'=>$items, 'total'=>$total ];
	}
	private static function first_existing( array $all, array $priority ) { foreach ( $priority as $c ) { if ( in_array( $c, $all, true ) ) return $c; } return ''; }
	private static function canonical_key( $url ) {
		$p = wp_parse_url( $url ); if ( empty( $p ) ) return '';
		$scheme = isset($p['scheme']) ? strtolower($p['scheme']) : 'https';
		$host   = isset($p['host']) ? strtolower($p['host']) : '';
		$path   = isset($p['path']) ? $p['path'] : '/';
		return $scheme . '://' . $host . $path;
	}
	private static function map_device( $raw ) {
		$val = strtolower( trim( (string) $raw ) );
		if ( $val === '' ) return '';
		if ( in_array( $val, [ 'mobile','m','phone' ], true ) ) return 'mobile';
		if ( in_array( $val, [ 'desktop','d','pc' ], true ) ) return 'desktop';
		if ( in_array( $val, [ '1','true','yes' ], true ) ) return 'mobile';
		if ( in_array( $val, [ '0','false','no' ], true ) ) return 'desktop';
		if ( strpos( $val, 'desktop' ) !== false ) return 'desktop';
		if ( strpos( $val, 'mobile' ) !== false ) return 'mobile';
		if ( in_array( $val, [ 'phone','handset','tablet' ], true ) ) return 'mobile';
		return '';
	}
	public static function label_for_status( $slug ) {
        $slug = strtolower( (string) $slug );
        $labels = self::get_status_filters();
        return isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug;
    }
    private static function normalize_status( $val ) {
        $v = strtolower( trim( (string) $val ) );
        if ( $v === '' ) return '';
        // synonyms
        if ( in_array( $v, [ 'in progress','in-progress','processing','in_process','processando','em processamento','queued','queue' ], true ) ) return 'in_progress';
        if ( in_array( $v, [ 'done','completed','complete','ok','sucesso','success' ], true ) ) return 'completed';
        if ( in_array( $v, [ 'fail','failed','erro','error' ], true ) ) return 'failed';
        if ( in_array( $v, [ 'pending','aguardando','pendente' ], true ) ) return 'pending';
        return $v;
    }
    private static function adjust_datetime( $val ) {
        $val = trim( (string) $val );
        if ( $val === '' ) return '';
        $ts = strtotime( $val );
        if ( ! $ts ) return $val;
        try {
            $dt = new DateTime( '@' . $ts );
            $dt->setTimezone( wp_timezone() );
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( Exception $e ) {
            return $val;
        }
    }
    private static function pick_status( $current, $incoming ) {
		$cur = self::normalize_status( $current ); $inc = self::normalize_status( $incoming );
		if ( $inc === '' ) return $current; if ( $cur === '' ) return $incoming;
		$rank = [ 'failed'=>3, 'in_progress'=>2, 'done'=>1 ];
		$rc = $rank[$cur] ?? 0; $ri = $rank[$inc] ?? 0;
		return ( $ri > $rc ) ? $incoming : $current;
	}
	private static function guess_layer( $raw ) {
		if ( strpos($raw,'rucss') !== false ) return 'rucss';
		if ( strpos($raw,'lazy') !== false ) return 'lazy';
		if ( strpos($raw,'above_the_fold') !== false ) return 'priority';
		if ( strpos($raw,'cache') !== false ) return 'cache';
		return 'cache';
	}
}
