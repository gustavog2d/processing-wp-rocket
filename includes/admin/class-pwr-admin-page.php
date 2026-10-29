<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class PWR_Admin_Page {
	public static function render() {
		if ( ! current_user_can( pwr_min_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'processing-wp-rocket' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen, GET filters only.
		$status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$exact    = isset( $_GET['exact'] ) && '1' === $_GET['exact'];
		$inspect  = isset( $_GET['pwr_inspect'] ) ? esc_url_raw( wp_unslash( $_GET['pwr_inspect'] ) ) : '';
		$debug    = isset( $_GET['pwr_debug'] );
		// phpcs:enable
		$per_page = (int) apply_filters( 'pwr_table_per_page', 20 );

		$list_table = new PWR_List_Table( [ 'status' => $status, 'search' => $search, 'search_mode' => $exact ? 'exact' : 'contains', 'per_page' => $per_page ] );
		$list_table->prepare_items();
		$statuses = PWR_Repository::get_status_filters();
		?>
		<div class="wrap pwr-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Processing WP Rocket', 'processing-wp-rocket' ); ?></h1>
			<hr class="wp-header-end"/>
			<?php self::render_environment_panel(); ?>
			<?php if ( '' !== $inspect ) { self::render_inspect_panel( $inspect ); } ?>
			<?php self::render_health_panel(); ?>
			<?php self::render_cron_panel(); ?>
			<form method="get">
				<input type="hidden" name="page" value="pwr-admin" />
				<label for="pwr-filter-status" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'processing-wp-rocket' ); ?></label>
				<select id="pwr-filter-status" name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'processing-wp-rocket' ); ?></option>
					<?php foreach ( $statuses as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $status ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="pwr-search" class="screen-reader-text"><?php esc_html_e( 'Search URL', 'processing-wp-rocket' ); ?></label>
				<input id="pwr-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search URL…', 'processing-wp-rocket' ); ?>" />
				<label for="pwr-exact"><input type="checkbox" id="pwr-exact" name="exact" value="1" <?php checked( $exact ); ?> /> <?php esc_html_e( 'Exact match', 'processing-wp-rocket' ); ?></label>
				<?php submit_button( __( 'Filter', 'processing-wp-rocket' ), 'secondary', 'filter_action', false ); ?>
			</form>
			<?php if ( '' !== $list_table->get_error() ) : ?>
				<div class="notice notice-error"><p>
					<?php
					// The raw database error (table names, SQL fragments) is only shown when debugging.
					if ( $debug || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
						echo esc_html( sprintf( /* translators: %s: database error */ __( 'Could not read WP Rocket tables: %s', 'processing-wp-rocket' ), $list_table->get_error() ) );
					} else {
						esc_html_e( 'Could not read WP Rocket tables. Add ?pwr_debug=1 to the URL to see the database error.', 'processing-wp-rocket' );
					}
					?>
				</p></div>
			<?php endif; ?>
			<p class="description pwr-legend">
				<?php esc_html_e( '[file] = cache file actually on disk right now. [cache] = preload queue status (not proof that the file exists). [css] = Remove Unused CSS job at WP Rocket\'s SaaS; "Completed" means the Used CSS is ready, and the page uses it from its next cache generation. [lazy] / [priority] = data collected by WP Rocket\'s beacon in a real browser; no row means no visit measured yet, not a failure.', 'processing-wp-rocket' ); ?>
			</p>
			<form method="get">
				<input type="hidden" name="page" value="pwr-admin" />
				<?php $list_table->display(); ?>
			</form>
			<?php if ( $debug && current_user_can( pwr_min_cap() ) ) : ?>
				<div class="notice notice-info">
					<p><strong><?php esc_html_e( 'PWR Debug:', 'processing-wp-rocket' ); ?></strong></p>
					<pre style="max-height:300px;overflow:auto;"><?php echo esc_html( print_r( PWR_Repository::get_sources(), true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r ?></pre>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Refresh links carry a nonce so a forged link cannot make an admin re-run the heavy queries. */
	private static function refresh_url( $param ) {
		return wp_nonce_url( add_query_arg( $param, 1, remove_query_arg( [ 'pwr_refresh_health', 'pwr_refresh_cron', '_wpnonce' ] ) ), 'pwr_refresh' );
	}

	private static function refresh_requested( $param ) {
		if ( ! isset( $_GET[ $param ], $_GET['_wpnonce'] ) || ! current_user_can( pwr_min_cap() ) ) { return false; }
		return (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'pwr_refresh' );
	}

	private static function status_badge( $status, $label ) {
		$map = [ 'ok' => 'completed', 'info' => 'info', 'warning' => 'pending', 'error' => 'failed' ];
		$cls = isset( $map[ $status ] ) ? $map[ $status ] : 'info';
		return '<span class="pwr-badge pwr-status-' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function render_environment_panel() {
		$checks = PWR_Environment::get_checks();
		$worst  = PWR_Environment::worst_status( $checks );
		$labels = [
			'ok'      => __( 'OK', 'processing-wp-rocket' ),
			'info'    => __( 'Info', 'processing-wp-rocket' ),
			'warning' => __( 'Warning', 'processing-wp-rocket' ),
			'error'   => __( 'Error', 'processing-wp-rocket' ),
		];
		$summary = 'ok' === $worst ? __( 'everything WP Rocket needs is in place', 'processing-wp-rocket' ) : ( 'error' === $worst ? __( 'problems found', 'processing-wp-rocket' ) : __( 'points of attention', 'processing-wp-rocket' ) );
		?>
		<details class="pwr-panel pwr-env-panel" <?php echo 'ok' === $worst ? '' : 'open'; ?>>
			<summary><h2><?php esc_html_e( 'Environment Checklist', 'processing-wp-rocket' ); ?> <?php echo self::status_badge( $worst, $summary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2></summary>
			<table class="widefat striped pwr-env-table">
				<tbody>
				<?php foreach ( $checks as $c ) : ?>
					<tr>
						<td class="pwr-env-status"><?php echo self::status_badge( $c['status'], $labels[ $c['status'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<th scope="row"><?php echo esc_html( $c['label'] ); ?></th>
						<td><?php echo esc_html( $c['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php
	}

	private static function render_inspect_panel( $url ) {
		$report = PWR_Cache_Inspector::inspect( $url );
		$item   = PWR_Repository::get_item_for_url( $url );
		$close  = remove_query_arg( 'pwr_inspect' );
		$states = [
			'applied'            => [ 'completed', __( 'Applied', 'processing-wp-rocket' ) ],
			'applied_unexpected' => [ 'info', __( 'Applied (not enabled in settings)', 'processing-wp-rocket' ) ],
			'missing'            => [ 'failed', __( 'Enabled but missing', 'processing-wp-rocket' ) ],
			'waiting'            => [ 'pending', __( 'Enabled, waiting for data', 'processing-wp-rocket' ) ],
			'stale'              => [ 'pending', __( 'Data ready, page not regenerated yet', 'processing-wp-rocket' ) ],
			'data_failed'        => [ 'failed', __( 'Enabled, data processing failed', 'processing-wp-rocket' ) ],
			'excluded'           => [ 'info', __( 'Disabled for this post', 'processing-wp-rocket' ) ],
			'unknown'            => [ 'info', __( 'Enabled (could not verify)', 'processing-wp-rocket' ) ],
			'off'                => [ 'info', __( 'Off', 'processing-wp-rocket' ) ],
		];
		$fmt = static function ( $ts ) { return PWR_List_Table::format_datetime( (int) $ts ); };
		?>
		<div class="pwr-panel pwr-inspect-panel" id="pwr-inspect">
			<h2>
				<?php esc_html_e( 'Inspect URL', 'processing-wp-rocket' ); ?>
				<a class="pwr-refresh-link" href="<?php echo esc_url( $close ); ?>"><?php esc_html_e( 'Close', 'processing-wp-rocket' ); ?></a>
			</h2>
			<p><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><strong><?php echo esc_html( $url ); ?></strong></a>
			<?php if ( $report['post_id'] ) : ?>
				· <a href="<?php echo esc_url( get_edit_post_link( $report['post_id'] ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: post ID */ __( 'Post #%d', 'processing-wp-rocket' ), $report['post_id'] ) ); ?></a>
			<?php endif; ?>
			</p>
			<?php if ( ! empty( $report['excluded'] ) ) : ?>
				<p class="pwr-note"><?php echo esc_html( sprintf( /* translators: %s: list of options */ __( 'Options disabled for this post in the WP Rocket metabox: %s', 'processing-wp-rocket' ), implode( ', ', $report['excluded'] ) ) ); ?></p>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Cache files on disk', 'processing-wp-rocket' ); ?></h3>
			<?php if ( empty( $report['files'] ) && PWR_WPRocket_Adapter::host_handles_page_cache() ) : ?>
				<p><?php esc_html_e( 'Page cache is handled by the hosting, so WP Rocket writes no cache files to inspect. Check the page source in the browser for the data-wpr-features meta tag instead.', 'processing-wp-rocket' ); ?></p>
			<?php elseif ( '' === $report['dir'] ) : ?>
				<p class="pwr-note"><?php esc_html_e( 'This URL does not belong to this site, so its cache files were not read.', 'processing-wp-rocket' ); ?></p>
			<?php elseif ( empty( $report['files'] ) ) : ?>
				<p><?php echo esc_html( sprintf( /* translators: %s: folder path */ __( 'No cache file found in %s. The page is not cached right now on this server (never generated, purged, excluded, or cached on another server node).', 'processing-wp-rocket' ), str_replace( WP_CONTENT_DIR, 'wp-content', $report['dir'] ) ) ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'File', 'processing-wp-rocket' ); ?></th>
						<th><?php esc_html_e( 'Variant', 'processing-wp-rocket' ); ?></th>
						<th><?php esc_html_e( 'Size', 'processing-wp-rocket' ); ?></th>
						<th><?php esc_html_e( 'Generated', 'processing-wp-rocket' ); ?></th>
						<th><?php esc_html_e( 'Expires (cache lifespan)', 'processing-wp-rocket' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $report['files'] as $f ) : ?>
						<tr>
							<td><code><?php echo esc_html( $f['name'] ); ?></code><?php echo $f['gzip'] ? ' <small>+ gzip</small>' : ''; ?></td>
							<td><?php echo esc_html( $f['variant'] ); ?></td>
							<td><?php echo esc_html( size_format( $f['size'] ) ); ?></td>
							<td><?php echo esc_html( $fmt( $f['mtime'] ) ); ?> <small class="pwr-date">(<?php echo esc_html( sprintf( /* translators: %s: time difference */ __( '%s ago', 'processing-wp-rocket' ), human_time_diff( $f['mtime'] ) ) ); ?>)</small></td>
							<td><?php echo $f['expires_at'] ? esc_html( $fmt( $f['expires_at'] ) ) : esc_html__( 'Never', 'processing-wp-rocket' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $report['analyzed'] ) : ?>
				<h3><?php echo esc_html( sprintf( /* translators: %s: file name */ __( 'Optimizations applied in %s', 'processing-wp-rocket' ), $report['analyzed'] ) ); ?></h3>
				<?php if ( $report['cached_at'] ) : ?>
					<p class="description"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'WP Rocket footprint: cached at %s.', 'processing-wp-rocket' ), $fmt( $report['cached_at'] ) ) ); ?></p>
				<?php endif; ?>
				<?php foreach ( $report['notes'] as $note ) : ?>
					<p class="pwr-note"><?php echo esc_html( $note ); ?></p>
				<?php endforeach; ?>
				<table class="widefat striped pwr-features-table">
					<thead><tr>
						<th><?php esc_html_e( 'Optimization', 'processing-wp-rocket' ); ?></th>
						<th><?php esc_html_e( 'Marker', 'processing-wp-rocket' ); ?></th>
						<th><?php esc_html_e( 'Result', 'processing-wp-rocket' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $report['features'] as $row ) : ?>
						<?php list( $cls, $label ) = isset( $states[ $row['state'] ] ) ? $states[ $row['state'] ] : [ 'info', $row['state'] ]; ?>
						<tr class="pwr-feature-<?php echo esc_attr( $row['state'] ); ?>">
							<td><?php echo esc_html( $row['label'] ); ?></td>
							<td><code><?php echo esc_html( $row['feature'] ); ?></code></td>
							<td><span class="pwr-badge pwr-status-<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $label ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( '"Enabled but missing" can be legitimate when the page has nothing for that step (no iframes, no images without dimensions) or when an exclusion matched. "Waiting for data" depends on WP Rocket\'s SaaS or beacon having processed this URL; the marker appears on the next cache generation after that.', 'processing-wp-rocket' ); ?></p>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Processing queues for this URL', 'processing-wp-rocket' ); ?></h3>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Layer', 'processing-wp-rocket' ); ?></th>
					<th><?php esc_html_e( 'Status', 'processing-wp-rocket' ); ?></th>
					<th><?php esc_html_e( 'Updated', 'processing-wp-rocket' ); ?></th>
					<th><?php esc_html_e( 'Error', 'processing-wp-rocket' ); ?></th>
					<th><?php esc_html_e( 'Retries / next retry', 'processing-wp-rocket' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				$labels = PWR_Repository::field_labels();
				foreach ( PWR_Repository::FIELDS as $field ) :
					$st  = $item[ $field ];
					$err = isset( $item['errors'][ $field ] ) ? $item['errors'][ $field ] : null;
					?>
					<tr>
						<td><?php echo esc_html( isset( $labels[ $field ] ) ? $labels[ $field ] : $field ); ?></td>
						<td><?php echo '' === $st ? '—' : '<span class="pwr-badge pwr-status-' . esc_attr( sanitize_html_class( PWR_Repository::normalize_status( $st ) ) ) . '" title="' . esc_attr( $st ) . '">' . esc_html( PWR_Repository::label_for_status( $st ) ) . '</span>'; ?></td>
						<td><?php echo esc_html( PWR_List_Table::format_datetime( $item[ $field . '_updated' ] ) ); ?></td>
						<td><?php echo $err ? self::render_error_log( $err ) : '—'; ?></td>
						<td><?php echo $err ? esc_html( trim( $err['retries'] . ( '' !== $err['next_retry'] ? ' · ' . PWR_List_Table::format_datetime( $err['next_retry'] ) : '' ) ) ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Readable version of WP Rocket's accumulated error column: last failure, grouped attempts,
	 * a concurrency warning when the same failure repeats within one second, and the raw text.
	 */
	private static function render_error_log( array $err ) {
		$log = PWR_Error_Log::parse( $err['message'], $err['code'] );
		if ( ! $log['last'] ) {
			return esc_html( trim( ( '' !== $err['code'] ? '[' . $err['code'] . '] ' : '' ) . $err['message'] ) );
		}
		$last = $log['last'];
		$out  = '<div class="pwr-errlog">';
		$out .= '<p class="pwr-errlog-last"><strong>' . esc_html__( 'Last failure:', 'processing-wp-rocket' ) . '</strong> '
			. esc_html( trim( ( '' !== $last['code'] ? $last['code'] . ' · ' : '' ) . $last['message'] ) )
			. ( '' !== $last['time'] ? ' <span class="pwr-date">(' . esc_html( PWR_List_Table::format_datetime( $last['time'] ) ) . ')</span>' : '' )
			. '</p>';
		if ( $log['attempts'] > 1 ) {
			$out .= '<p class="pwr-errlog-count">' . esc_html( sprintf(
				/* translators: 1: number of failed attempts, 2: number of distinct errors */
				_n( '%1$d failed attempt recorded, %2$d distinct error:', '%1$d failed attempts recorded, %2$d distinct errors:', $log['attempts'], 'processing-wp-rocket' ),
				$log['attempts'],
				count( $log['groups'] )
			) ) . '</p><ul class="pwr-errlog-groups">';
			foreach ( $log['groups'] as $g ) {
				$out .= '<li>' . esc_html( PWR_Error_Log::group_line( $g ) ) . '</li>';
			}
			$out .= '</ul>';
		}
		if ( $log['burst'] ) {
			$out .= '<p class="pwr-errlog-burst"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html( PWR_Error_Log::burst_text( $log['burst'] ) ) . '</p>';
		}
		if ( $log['truncated'] ) {
			$out .= '<p class="pwr-date">' . esc_html__( 'Only the newest part of a very long log was analyzed.', 'processing-wp-rocket' ) . '</p>';
		}
		$out .= '<details class="pwr-errlog-raw"><summary>' . esc_html__( 'Raw text from WP Rocket', 'processing-wp-rocket' ) . '</summary><code>'
			. esc_html( trim( ( '' !== $err['code'] ? '[' . $err['code'] . '] ' : '' ) . $err['message'] ) ) . '</code></details>';
		return $out . '</div>';
	}

	private static function render_health_panel() {
		$force    = self::refresh_requested( 'pwr_refresh_health' );
		$report   = PWR_Repository::get_health_report( $force );
		$statuses = PWR_Repository::get_status_filters();
		?>
		<div class="pwr-panel pwr-health-panel">
			<h2>
				<?php esc_html_e( 'Site Health', 'processing-wp-rocket' ); ?>
				<a class="pwr-refresh-link" href="<?php echo esc_url( self::refresh_url( 'pwr_refresh_health' ) ); ?>"><?php esc_html_e( 'Refresh', 'processing-wp-rocket' ); ?></a>
			</h2>
			<?php if ( 0 === (int) $report['total_urls'] ) : ?>
				<p><?php esc_html_e( 'No data yet.', 'processing-wp-rocket' ); ?></p>
			<?php else : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: number of URLs tracked, 2: date/time the report was generated, 3: " (cached)" or empty */
						esc_html__( '%1$d URLs tracked · as of %2$s%3$s', 'processing-wp-rocket' ),
						(int) $report['total_urls'],
						esc_html( wp_date( apply_filters( 'pwr_datetime_format', 'Y-m-d H:i:s' ), $report['generated_at'] ) ),
						$report['from_cache'] ? esc_html__( ' (cached)', 'processing-wp-rocket' ) : ''
					);
					?>
				</p>
				<table class="pwr-health-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Layer', 'processing-wp-rocket' ); ?></th>
							<?php foreach ( $statuses as $label ) : ?>
								<th><?php echo esc_html( $label ); ?></th>
							<?php endforeach; ?>
							<th><?php esc_html_e( 'Tracked', 'processing-wp-rocket' ); ?></th>
							<th title="<?php esc_attr_e( 'Share of URLs in the preload/cache table that also have a row in this layer', 'processing-wp-rocket' ); ?>"><?php esc_html_e( 'Coverage vs cache', 'processing-wp-rocket' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					$other_total = 0;
					foreach ( $report['fields'] as $field ) :
						$other_total += (int) $field['counts']['other'];
						?>
						<tr>
							<td><?php echo esc_html( $field['label'] ); ?></td>
							<?php if ( 0 === (int) $field['tracked'] ) : ?>
								<td colspan="<?php echo (int) count( $statuses ); ?>"><em><?php esc_html_e( 'No data', 'processing-wp-rocket' ); ?></em></td>
							<?php else : ?>
								<?php foreach ( array_keys( $statuses ) as $slug ) : ?>
									<td>
										<?php echo esc_html( number_format_i18n( $field['percentages'][ $slug ] ?? 0, 1 ) ); ?>%
										<span class="pwr-health-count">(<?php echo (int) ( $field['counts'][ $slug ] ?? 0 ); ?>)</span>
									</td>
								<?php endforeach; ?>
							<?php endif; ?>
							<td><?php echo (int) $field['tracked']; ?></td>
							<td><?php echo null === $field['coverage'] ? '—' : esc_html( number_format_i18n( $field['coverage'], 1 ) . '%' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $other_total > 0 ) : ?>
					<p class="description pwr-note">
						<?php
						printf(
							/* translators: %d: number of URLs with an unrecognized status value */
							esc_html__( '%d URL(s) had a status value not recognized by any layer above.', 'processing-wp-rocket' ),
							(int) $other_total
						);
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_cron_panel() {
		$force  = self::refresh_requested( 'pwr_refresh_cron' );
		$status = PWR_Cron_Status::get_status( $force );
		?>
		<div class="pwr-panel pwr-cron-panel">
			<h2>
				<?php esc_html_e( 'Cron Status', 'processing-wp-rocket' ); ?>
				<a class="pwr-refresh-link" href="<?php echo esc_url( self::refresh_url( 'pwr_refresh_cron' ) ); ?>"><?php esc_html_e( 'Refresh', 'processing-wp-rocket' ); ?></a>
			</h2>
			<?php if ( empty( $status['rocket_active'] ) ) : ?>
				<p><?php esc_html_e( 'WP Rocket is not active.', 'processing-wp-rocket' ); ?></p>
			<?php else : ?>
				<?php if ( ! empty( $status['wp_cron_disabled'] ) ) : ?>
					<p class="pwr-note"><?php esc_html_e( 'Note: DISABLE_WP_CRON is set. This is common on sites using a real system cron job and is not necessarily a problem.', 'processing-wp-rocket' ); ?></p>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Action Scheduler queue', 'processing-wp-rocket' ); ?></h3>
				<?php $as = $status['action_scheduler']; ?>
				<?php if ( empty( $as['available'] ) ) : ?>
					<p><em><?php esc_html_e( 'Action Scheduler is not loaded on this site.', 'processing-wp-rocket' ); ?></em></p>
				<?php elseif ( empty( $as['backlog'] ) ) : ?>
					<p><em><?php esc_html_e( 'No pending WP Rocket actions.', 'processing-wp-rocket' ); ?></em></p>
				<?php else : ?>
					<table class="widefat striped pwr-backlog-table">
						<thead><tr>
							<th><?php esc_html_e( 'Group', 'processing-wp-rocket' ); ?></th>
							<th><?php esc_html_e( 'Pending', 'processing-wp-rocket' ); ?></th>
							<th><?php esc_html_e( 'Running', 'processing-wp-rocket' ); ?></th>
							<th><?php esc_html_e( 'Overdue', 'processing-wp-rocket' ); ?></th>
							<th><?php esc_html_e( 'Oldest overdue since', 'processing-wp-rocket' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $as['backlog'] as $g ) : ?>
							<tr>
								<td><code><?php echo esc_html( $g['group'] ); ?></code></td>
								<td><?php echo (int) $g['pending']; ?></td>
								<td><?php echo (int) $g['running']; ?></td>
								<td><?php echo $g['past_due'] ? '<span class="pwr-badge pwr-status-failed">' . (int) $g['past_due'] . '</span>' : '0'; ?></td>
								<td><?php echo $g['oldest_past_due'] ? esc_html( PWR_List_Table::format_datetime( $g['oldest_past_due'] ) ) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( ! empty( $as['available'] ) ) : ?>
					<h3><?php echo esc_html( sprintf( /* translators: %d: number of days */ __( 'Action Scheduler history (last %d days)', 'processing-wp-rocket' ), (int) ( $as['window_days'] ?? 7 ) ) ); ?></h3>
					<?php if ( empty( $as['hooks'] ) ) : ?>
						<p><em><?php esc_html_e( 'No WP Rocket related scheduled actions found.', 'processing-wp-rocket' ); ?></em></p>
					<?php else : ?>
						<ul class="pwr-cron-list">
						<?php foreach ( $as['hooks'] as $a ) : ?>
							<li>
								<code><?php echo esc_html( $a['hook'] ); ?></code> —
								<?php echo $a['last_run'] ? esc_html( wp_date( 'Y-m-d H:i:s', $a['last_run'] ) ) : esc_html__( 'never run', 'processing-wp-rocket' ); ?>
								<?php if ( $a['last_status'] ) : ?>
									<span class="pwr-badge pwr-status-<?php echo esc_attr( 'complete' === $a['last_status'] ? 'completed' : 'failed' ); ?>"><?php echo esc_html( $a['last_status'] ); ?></span>
								<?php endif; ?>
								<span class="pwr-cron-tally">
									<?php
									printf(
										/* translators: 1: number of successful recent runs, 2: total recent runs considered */
										esc_html__( '(%1$d / %2$d succeeded recently)', 'processing-wp-rocket' ),
										(int) $a['recent_success'],
										(int) $a['recent_total']
									);
									?>
								</span>
							</li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php endif; ?>

				<h3><?php esc_html_e( 'WP-Cron Hooks', 'processing-wp-rocket' ); ?></h3>
				<?php if ( empty( $status['wp_cron_hooks'] ) ) : ?>
					<p><em><?php esc_html_e( 'No WP Rocket cron hooks detected.', 'processing-wp-rocket' ); ?></em></p>
				<?php else : ?>
					<ul class="pwr-cron-list">
					<?php foreach ( $status['wp_cron_hooks'] as $h ) : ?>
						<li>
							<code><?php echo esc_html( $h['hook'] ); ?></code> —
							<?php echo $h['next_run'] ? esc_html( wp_date( 'Y-m-d H:i:s', $h['next_run'] ) ) : esc_html__( 'not scheduled', 'processing-wp-rocket' ); ?>
							<?php if ( ! empty( $h['overdue'] ) ) : ?>
								<span class="pwr-badge pwr-status-failed"><?php esc_html_e( 'Overdue', 'processing-wp-rocket' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
