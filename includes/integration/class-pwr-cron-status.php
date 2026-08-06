<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class PWR_Cron_Status {
	const TRANSIENT = 'pwr_cron_status';

	/**
	 * Combines native WP-Cron (next-run only, WordPress keeps no run history) with
	 * Action Scheduler (real last-run/status history) for any hook/group whose name
	 * matches the 'pwr_cron_hook_pattern' filter (default: containing "rocket").
	 * Hooks are discovered dynamically rather than hardcoded, since exact WP Rocket
	 * cron/Action Scheduler hook names can drift across versions.
	 */
	public static function get_status( $force_refresh = false ) {
		if ( $force_refresh ) { delete_transient( self::TRANSIENT ); }
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['generated_at'] ) ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		$ttl = (int) apply_filters( 'pwr_cron_cache_ttl', 5 * MINUTE_IN_SECONDS );

		if ( ! PWR_WPRocket_Adapter::is_active() ) {
			$status = [ 'rocket_active' => false, 'generated_at' => time() ];
			set_transient( self::TRANSIENT, $status, $ttl );
			$status['from_cache'] = false;
			return $status;
		}

		$pattern = apply_filters( 'pwr_cron_hook_pattern', 'rocket' );
		$status  = [
			'rocket_active'    => true,
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'wp_cron_hooks'    => self::find_wp_cron_hooks( $pattern ),
			'action_scheduler' => self::get_action_scheduler_summary( $pattern ),
			'generated_at'     => time(),
		];
		set_transient( self::TRANSIENT, $status, $ttl );
		$status['from_cache'] = false;
		return $status;
	}

	/**
	 * Native WP-Cron only exposes "next scheduled run", never a run history, so that's
	 * all we can report here — flagged as overdue when that time has already passed.
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
					if ( ! isset( $found[ $hook_name ] ) || $timestamp < $found[ $hook_name ] ) {
						$found[ $hook_name ] = (int) $timestamp;
					}
				}
			}
			$now = time();
			$out = [];
			foreach ( $found as $hook => $ts ) {
				$out[] = [ 'hook' => $hook, 'next_run' => $ts, 'overdue' => $ts < $now ];
			}
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
			return [ 'available' => false, 'hooks' => [] ];
		}
		$limit = (int) apply_filters( 'pwr_cron_actions_limit', 50 );
		$rows  = self::query_action_scheduler_rows( $pattern, $limit );
		if ( empty( $rows ) ) {
			return [ 'available' => true, 'hooks' => [] ];
		}
		$by_hook = [];
		foreach ( $rows as $row ) {
			$hook = isset( $row['hook'] ) ? (string) $row['hook'] : '';
			if ( '' === $hook ) { continue; }
			if ( ! isset( $by_hook[ $hook ] ) ) {
				$by_hook[ $hook ] = [
					'hook'           => $hook,
					'last_run'       => null,
					'last_status'    => '',
					'recent_total'   => 0,
					'recent_success' => 0,
					'recent_failed'  => 0,
				];
			}
			$status = isset( $row['status'] ) ? (string) $row['status'] : '';
			$when   = ! empty( $row['last_attempt_gmt'] ) && '0000-00-00 00:00:00' !== $row['last_attempt_gmt']
				? $row['last_attempt_gmt']
				: ( isset( $row['scheduled_date_gmt'] ) ? $row['scheduled_date_gmt'] : '' );
			$ts = $when ? strtotime( $when . ' UTC' ) : 0;

			$by_hook[ $hook ]['recent_total']++;
			if ( 'complete' === $status ) { $by_hook[ $hook ]['recent_success']++; }
			if ( 'failed' === $status )   { $by_hook[ $hook ]['recent_failed']++; }

			if ( $ts && $ts > (int) $by_hook[ $hook ]['last_run'] ) {
				$by_hook[ $hook ]['last_run']    = $ts;
				$by_hook[ $hook ]['last_status'] = $status;
			}
		}
		return [ 'available' => true, 'hooks' => array_values( $by_hook ) ];
	}

	/**
	 * Reads Action Scheduler's own table directly (SHOW TABLES-guarded, same defensive
	 * pattern PWR_Repository uses for WP Rocket tables) instead of relying on Action
	 * Scheduler API methods, which vary across bundled library versions. Tries a join
	 * against the groups table first (to also match by group slug), falls back to a
	 * hook-only match if that join isn't available, and degrades to an empty result
	 * rather than failing the page.
	 */
	private static function query_action_scheduler_rows( $pattern, $limit ) {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) { return []; }
		$like         = '%' . $wpdb->esc_like( $pattern ) . '%';
		$groups_table = $wpdb->prefix . 'actionscheduler_groups';

		try {
			$groups_exist = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $groups_table ) );
			if ( $groups_exist === $groups_table ) {
				$sql = $wpdb->prepare(
					"SELECT a.hook, a.status, a.last_attempt_gmt, a.scheduled_date_gmt
					 FROM `{$table}` a
					 LEFT JOIN `{$groups_table}` g ON a.group_id = g.group_id
					 WHERE ( a.hook LIKE %s OR g.slug LIKE %s )
					 AND a.status IN ('complete','failed')
					 ORDER BY a.last_attempt_gmt DESC
					 LIMIT %d",
					$like, $like, $limit
				);
				$rows = $wpdb->get_results( $sql, ARRAY_A );
				if ( is_array( $rows ) ) { return $rows; }
			}
		} catch ( \Throwable $e ) {
			// Fall through to the hook-only query below.
		}

		try {
			$sql = $wpdb->prepare(
				"SELECT hook, status, last_attempt_gmt, scheduled_date_gmt
				 FROM `{$table}`
				 WHERE hook LIKE %s AND status IN ('complete','failed')
				 ORDER BY last_attempt_gmt DESC
				 LIMIT %d",
				$like, $limit
			);
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			return is_array( $rows ) ? $rows : [];
		} catch ( \Throwable $e ) {
			return [];
		}
	}
}
