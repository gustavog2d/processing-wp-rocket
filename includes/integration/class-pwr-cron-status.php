<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reports on the machinery that keeps WP Rocket's tables moving:
 * - native WP-Cron hooks (WordPress only stores the next run, never a history);
 * - Action Scheduler, which holds the real queue (preload URLs, SaaS jobs) with history.
 *
 * Hooks are matched by the `pwr_cron_hook_pattern` substring (default "rocket"), which covers
 * every WP Rocket hook and its Action Scheduler groups (`rocket`, `rocket-rucss`).
 */
class PWR_Cron_Status {
	const TRANSIENT = 'pwr_cron_status';

	public static function get_status( $force_refresh = false ) {
		static $memo = null;
		if ( $force_refresh ) { delete_transient( self::TRANSIENT ); $memo = null; }
		if ( null !== $memo ) { return $memo; }
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['generated_at'] ) ) {
			$cached['from_cache'] = true;
			$memo                 = $cached;
			return $memo;
		}

		$ttl = (int) apply_filters( 'pwr_cron_cache_ttl', 5 * MINUTE_IN_SECONDS );
		if ( ! PWR_WPRocket_Adapter::is_active() ) {
			$status = [ 'rocket_active' => false, 'generated_at' => time() ];
		} else {
			$pattern = (string) apply_filters( 'pwr_cron_hook_pattern', 'rocket' );
			$status  = [
				'rocket_active'    => true,
				'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'wp_cron_hooks'    => self::find_wp_cron_hooks( $pattern ),
				'action_scheduler' => self::get_action_scheduler_summary( $pattern ),
				'generated_at'     => time(),
			];
		}
		set_transient( self::TRANSIENT, $status, $ttl );
		$status['from_cache'] = false;
		$memo                 = $status;
		return $memo;
	}

	/**
	 * Next run per hook. A hook is only flagged overdue after a grace period of
	 * max(10 minutes, its own recurrence), since WP-Cron runs on page visits and small delays are normal.
	 */
	private static function find_wp_cron_hooks( $pattern ) {
		try {
			$crons = _get_cron_array();
			if ( ! is_array( $crons ) ) { return []; }
			$found = [];
			foreach ( $crons as $timestamp => $hooks ) {
				if ( ! is_array( $hooks ) ) { continue; }
				foreach ( $hooks as $hook_name => $entries ) {
					if ( false === stripos( (string) $hook_name, $pattern ) ) { continue; }
					$interval = 0;
					foreach ( (array) $entries as $entry ) {
						if ( ! empty( $entry['interval'] ) ) { $interval = max( $interval, (int) $entry['interval'] ); }
					}
					if ( ! isset( $found[ $hook_name ] ) || $timestamp < $found[ $hook_name ]['next_run'] ) {
						$found[ $hook_name ] = [ 'next_run' => (int) $timestamp, 'interval' => $interval ];
					}
				}
			}
			$now = time();
			$out = [];
			foreach ( $found as $hook => $data ) {
				$grace = max( 10 * MINUTE_IN_SECONDS, $data['interval'] );
				$out[] = [
					'hook'     => $hook,
					'next_run' => $data['next_run'],
					'interval' => $data['interval'],
					'overdue'  => $data['next_run'] < ( $now - $grace ),
				];
			}
			usort( $out, static function ( $a, $b ) { return $a['next_run'] <=> $b['next_run']; } );
			return $out;
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	private static function action_scheduler_available() {
		return function_exists( 'as_get_scheduled_actions' ) || class_exists( 'ActionScheduler_Store' );
	}

	private static function get_action_scheduler_summary( $pattern ) {
		if ( ! self::action_scheduler_available() ) {
			return [ 'available' => false, 'hooks' => [], 'backlog' => null ];
		}
		global $wpdb;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions ) ) !== $actions ) {
			return [ 'available' => false, 'hooks' => [], 'backlog' => null ];
		}
		$has_groups = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $groups ) ) === $groups;
		$like       = '%' . $wpdb->esc_like( $pattern ) . '%';
		$join       = $has_groups ? "LEFT JOIN `{$groups}` g ON a.group_id = g.group_id" : '';
		// Placeholders only: every query below goes through a single prepare() call.
		$match      = $has_groups ? '( a.hook LIKE %s OR g.slug LIKE %s )' : 'a.hook LIKE %s';
		$match_args = $has_groups ? [ $like, $like ] : [ $like ];
		$group_col  = $has_groups ? "COALESCE(g.slug, '')" : "''";

		// History: one aggregated row per hook/status within the window (no global LIMIT that a busy hook could monopolize).
		$days  = max( 1, (int) apply_filters( 'pwr_cron_window_days', 7 ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$hooks = [];
		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.hook, a.status, COUNT(*) AS n, MAX(a.last_attempt_gmt) AS last_run
					 FROM `{$actions}` a {$join}
					 WHERE {$match} AND a.status IN ('complete','failed') AND a.last_attempt_gmt >= %s
					 GROUP BY a.hook, a.status",
					array_merge( $match_args, [ $since ] )
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$hook = (string) $r['hook'];
				if ( ! isset( $hooks[ $hook ] ) ) {
					$hooks[ $hook ] = [ 'hook' => $hook, 'last_run' => 0, 'last_status' => '', 'recent_total' => 0, 'recent_success' => 0, 'recent_failed' => 0 ];
				}
				$n   = (int) $r['n'];
				$ts  = $r['last_run'] ? (int) strtotime( $r['last_run'] . ' UTC' ) : 0;
				$hooks[ $hook ]['recent_total'] += $n;
				if ( 'complete' === $r['status'] ) { $hooks[ $hook ]['recent_success'] += $n; }
				if ( 'failed' === $r['status'] )   { $hooks[ $hook ]['recent_failed'] += $n; }
				if ( $ts > $hooks[ $hook ]['last_run'] ) {
					$hooks[ $hook ]['last_run']    = $ts;
					$hooks[ $hook ]['last_status'] = (string) $r['status'];
				}
			}
		} catch ( \Throwable $e ) {
			$hooks = [];
		}
		$hooks = array_values( $hooks );
		usort( $hooks, static function ( $a, $b ) { return $b['last_run'] <=> $a['last_run']; } );

		// Backlog: pending / running actions per group, and how many are past their scheduled time.
		$grace    = (int) apply_filters( 'pwr_cron_past_due_grace', 10 * MINUTE_IN_SECONDS );
		$deadline = gmdate( 'Y-m-d H:i:s', time() - $grace );
		$backlog  = [];
		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$group_col} AS grp,
					        SUM(a.status = 'pending') AS pending,
					        SUM(a.status = 'in-progress') AS running,
					        SUM(a.status = 'pending' AND a.scheduled_date_gmt < %s) AS past_due,
					        MIN(CASE WHEN a.status = 'pending' AND a.scheduled_date_gmt < %s THEN a.scheduled_date_gmt END) AS oldest_past_due
					 FROM `{$actions}` a {$join}
					 WHERE {$match} AND a.status IN ('pending','in-progress')
					 GROUP BY grp",
					array_merge( [ $deadline, $deadline ], $match_args )
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$backlog[] = [
					'group'           => '' !== (string) $r['grp'] ? (string) $r['grp'] : '—',
					'pending'         => (int) $r['pending'],
					'running'         => (int) $r['running'],
					'past_due'        => (int) $r['past_due'],
					'oldest_past_due' => $r['oldest_past_due'] ? (int) strtotime( $r['oldest_past_due'] . ' UTC' ) : 0,
				];
			}
		} catch ( \Throwable $e ) {
			$backlog = [];
		}

		return [ 'available' => true, 'hooks' => $hooks, 'backlog' => $backlog, 'window_days' => $days ];
	}
}
