<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Turns WP Rocket's accumulated error_message column into something readable.
 *
 * WP Rocket never replaces the message of a SaaS job: every failure is appended as
 * " - <GMT Y-m-d H:i:s> <code>: <message>" (AbstractQuery::make_status_failed / update_message),
 * so a job that failed dozens of times becomes one long string. This class splits it into
 * attempts, groups identical code + message pairs, and flags the same failure being recorded
 * several times in the same second, which one cron run never does (it means several cron
 * runners, usually several servers, are processing the same queue at once).
 */
class PWR_Error_Log {

	/** Only the newest bytes are parsed: the column is unbounded and the newest attempts are at the end. */
	const MAX_BYTES = 65536;

	/** The same code + message this many times in one second counts as a concurrency signal. */
	const BURST_MIN = 2;

	private static $memo = [];

	/**
	 * @param string $message Raw error_message column.
	 * @param string $code    Raw error_code column (code of the last failure).
	 * @return array{
	 *   attempts:int, groups:array, last:?array, burst:?array, prefix:string, truncated:bool, raw:string, code:string
	 * }
	 */
	public static function parse( $message, $code = '' ) {
		$message = trim( (string) $message );
		$code    = trim( (string) $code );
		$key     = md5( $code . '|' . $message );
		if ( isset( self::$memo[ $key ] ) ) { return self::$memo[ $key ]; }

		$truncated = false;
		$text      = $message;
		if ( strlen( $text ) > self::MAX_BYTES ) {
			$text      = substr( $text, -self::MAX_BYTES );
			$truncated = true;
		}

		// WP Rocket starts the column with " - " because the first attempt is appended to an empty string.
		$text = preg_replace( '/^-\s*/', '', $text );

		// Split on the " - <timestamp> " separator WP Rocket writes before every attempt (linear, no backtracking).
		$parts   = preg_split( '/(?:^|\s-\s)(?=\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} )/', $text );
		$parts   = is_array( $parts ) ? $parts : [ $text ];
		$entries = [];
		$prefix  = '';
		foreach ( $parts as $i => $part ) {
			$part = trim( $part );
			if ( '' === $part ) { continue; }
			if ( preg_match( '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) (\d*):\s?(.*)$/s', $part, $m ) ) {
				$prev = $entries ? $entries[ count( $entries ) - 1 ] : null;
				if ( $prev && '500' === $prev['code'] && '500' !== $m[2] && $prev['time'] === $m[1] && trim( $m[3] ) === $prev['message'] ) {
					// One failure, two lines: validate_and_fail() writes a generic 500 and the retry
					// strategy (JobSetFail) then writes the real code. Keep it as a single attempt.
					$entries[ count( $entries ) - 1 ]['code'] = $m[2];
					continue;
				}
				$entries[] = [
					'time'    => $m[1],
					'code'    => $m[2],
					'message' => trim( $m[3] ),
				];
			} elseif ( 0 === $i || ( $truncated && empty( $entries ) ) ) {
				// Text before the first timestamp (older WP Rocket versions, or a cut entry when truncated).
				$prefix = $truncated ? '' : $part;
			} else {
				$entries[] = [ 'time' => '', 'code' => '', 'message' => $part ];
			}
		}

		$groups = [];
		$per_second = [];
		foreach ( $entries as $e ) {
			$gk = $e['code'] . '|' . $e['message'];
			if ( ! isset( $groups[ $gk ] ) ) {
				$groups[ $gk ] = [
					'code'    => $e['code'],
					'message' => $e['message'],
					'count'   => 0,
					'first'   => $e['time'],
					'last'    => $e['time'],
				];
			}
			$groups[ $gk ]['count']++;
			if ( '' !== $e['time'] ) {
				if ( '' === $groups[ $gk ]['first'] ) { $groups[ $gk ]['first'] = $e['time']; }
				$groups[ $gk ]['last'] = $e['time'];
				$sk = $e['time'] . '|' . $gk;
				$per_second[ $sk ] = ( $per_second[ $sk ] ?? 0 ) + 1;
			}
		}

		$burst = null;
		foreach ( $per_second as $sk => $n ) {
			if ( $n < self::BURST_MIN ) { continue; }
			list( $time, $gcode, $gmsg ) = array_pad( explode( '|', $sk, 3 ), 3, '' );
			if ( null === $burst || $n > $burst['max'] ) {
				$burst = [ 'max' => $n, 'time' => $time, 'code' => $gcode, 'message' => $gmsg, 'seconds' => 0 ];
			}
		}
		if ( $burst ) {
			foreach ( $per_second as $n ) {
				if ( $n >= self::BURST_MIN ) { $burst['seconds']++; }
			}
		}

		$result = [
			'attempts'  => count( $entries ),
			'groups'    => array_values( $groups ),
			'last'      => $entries ? end( $entries ) : null,
			'burst'     => $burst,
			'prefix'    => $prefix,
			'truncated' => $truncated,
			'raw'       => $message,
			'code'      => $code,
		];

		if ( count( self::$memo ) > 200 ) { self::$memo = []; }
		self::$memo[ $key ] = $result;
		return $result;
	}

	/** One line for list cells: last failure plus how many attempts there were. */
	public static function short_summary( array $parsed ) {
		if ( ! $parsed['last'] ) {
			$text = trim( ( '' !== $parsed['code'] ? '[' . $parsed['code'] . '] ' : '' ) . ( '' !== $parsed['prefix'] ? $parsed['prefix'] : $parsed['raw'] ) );
			return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $text, 0, 80, '…' ) : substr( $text, 0, 80 );
		}
		$last = $parsed['last'];
		$text = trim( ( '' !== $last['code'] ? $last['code'] . ' ' : '' ) . $last['message'] );
		$text = function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $text, 0, 70, '…' ) : substr( $text, 0, 70 );
		if ( $parsed['attempts'] > 1 ) {
			/* translators: %d: number of failed attempts recorded */
			$text .= ' ' . sprintf( _n( '(%d attempt)', '(%d attempts)', $parsed['attempts'], 'processing-wp-rocket' ), $parsed['attempts'] );
		}
		return $text;
	}

	/** Plain-text version of the grouped log, used in tooltips. */
	public static function plain_summary( array $parsed ) {
		if ( ! $parsed['groups'] ) { return $parsed['raw']; }
		$lines = [];
		foreach ( $parsed['groups'] as $g ) {
			$lines[] = self::group_line( $g );
		}
		if ( $parsed['burst'] ) {
			$lines[] = self::burst_text( $parsed['burst'] );
		}
		return implode( "\n", $lines );
	}

	public static function group_line( array $g ) {
		$label = trim( ( '' !== $g['code'] ? $g['code'] . ' · ' : '' ) . $g['message'] );
		if ( $g['count'] > 1 ) {
			$label .= ' × ' . $g['count'];
		}
		$first = PWR_List_Table::format_datetime( $g['first'] );
		$last  = PWR_List_Table::format_datetime( $g['last'] );
		if ( '' !== $first ) {
			$label .= ' · ' . ( $first === $last ? $first : $first . ' → ' . self::time_only( $last, $first ) );
		}
		return $label;
	}

	public static function burst_text( array $burst ) {
		$when = PWR_List_Table::format_datetime( $burst['time'] );
		if ( $burst['seconds'] > 1 ) {
			/* translators: 1: number of times, 2: date/time, 3: number of seconds with repeats */
			$what = sprintf( __( 'The same failure was recorded %1$d times in the same second (%2$s), and this happened in %3$d different seconds.', 'processing-wp-rocket' ), $burst['max'], $when, $burst['seconds'] );
		} else {
			/* translators: 1: number of times, 2: date/time */
			$what = sprintf( __( 'The same failure was recorded %1$d times in the same second (%2$s).', 'processing-wp-rocket' ), $burst['max'], $when );
		}
		return $what . ' ' . __( 'A single cron run records each job only once per pass, so this usually means several cron runners (several servers, or WP-Cron plus a system cron) are processing the same queue at once. Run WP-Cron/Action Scheduler from a single place.', 'processing-wp-rocket' );
	}

	/** Drops the date part of $date when it is the same day as $reference. */
	private static function time_only( $date, $reference ) {
		if ( strlen( $date ) > 11 && substr( $date, 0, 10 ) === substr( $reference, 0, 10 ) ) {
			return substr( $date, 11 );
		}
		return $date;
	}
}
