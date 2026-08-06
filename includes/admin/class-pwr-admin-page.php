<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class PWR_Admin_Page {
    public static function render() {
        if ( ! current_user_can( pwr_min_cap() ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'processing-wp-rocket' ) );
        }
        $status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $exact    = isset( $_GET['exact'] ) && '1' === $_GET['exact'];
        $per_page = (int) apply_filters( 'pwr_table_per_page', 20 );
        $list_table = new PWR_List_Table( [ 'status' => $status, 'search' => $search, 'search_mode' => $exact ? 'exact' : 'contains', 'per_page' => $per_page ] );
        $list_table->prepare_items();
        $statuses = PWR_Repository::get_status_filters();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html__( 'Processing WP Rocket', 'processing-wp-rocket' ); ?></h1>
            <hr class="wp-header-end"/>
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
            <form method="post">
                <?php $list_table->display(); ?>
            </form>
            <?php
            if ( isset( $_GET['pwr_debug'] ) && current_user_can( pwr_min_cap() ) ) :
                $data_debug = PWR_Repository::query_items( [ 'status' => $status, 'search' => $search, 'per_page' => 1 ] );
                if ( ! empty( $data_debug['debug'] ) ) :
            ?>
                <div class="notice notice-info">
                    <p><strong><?php esc_html_e( 'PWR Debug:', 'processing-wp-rocket' ); ?></strong></p>
                    <pre style="max-height:300px;overflow:auto;"><?php echo esc_html( print_r( $data_debug['debug'], true ) ); ?></pre>
                </div>
            <?php
                endif;
            endif;
            ?>
        </div>
        <?php
    }

    private static function render_health_panel() {
        $force  = isset( $_GET['pwr_refresh_health'] ) && current_user_can( pwr_min_cap() );
        $report = PWR_Repository::get_health_report( $force );
        $statuses = PWR_Repository::get_status_filters();
        ?>
        <div class="pwr-panel pwr-health-panel">
            <h2>
                <?php esc_html_e( 'Site Health', 'processing-wp-rocket' ); ?>
                <a class="pwr-refresh-link" href="<?php echo esc_url( add_query_arg( 'pwr_refresh_health', 1 ) ); ?>"><?php esc_html_e( 'Refresh', 'processing-wp-rocket' ); ?></a>
            </h2>
            <?php if ( 0 === (int) $report['total_urls'] ) : ?>
                <p><?php esc_html_e( 'No data yet.', 'processing-wp-rocket' ); ?></p>
            <?php else : ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: 1: number of URLs tracked, 2: date/time the report was generated */
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
                                        <?php echo esc_html( number_format_i18n( $field['percentages'][ $slug ], 1 ) ); ?>%
                                        <span class="pwr-health-count">(<?php echo (int) $field['counts'][ $slug ]; ?>)</span>
                                    </td>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <td><?php echo (int) $field['tracked']; ?></td>
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
        $force  = isset( $_GET['pwr_refresh_cron'] ) && current_user_can( pwr_min_cap() );
        $status = PWR_Cron_Status::get_status( $force );
        ?>
        <div class="pwr-panel pwr-cron-panel">
            <h2>
                <?php esc_html_e( 'Cron Status', 'processing-wp-rocket' ); ?>
                <a class="pwr-refresh-link" href="<?php echo esc_url( add_query_arg( 'pwr_refresh_cron', 1 ) ); ?>"><?php esc_html_e( 'Refresh', 'processing-wp-rocket' ); ?></a>
            </h2>
            <?php if ( empty( $status['rocket_active'] ) ) : ?>
                <p><?php esc_html_e( 'WP Rocket is not active.', 'processing-wp-rocket' ); ?></p>
            <?php else : ?>
                <?php if ( ! empty( $status['wp_cron_disabled'] ) ) : ?>
                    <p class="pwr-note"><?php esc_html_e( 'Note: DISABLE_WP_CRON is set. This is common on sites using a real system cron job and is not necessarily a problem.', 'processing-wp-rocket' ); ?></p>
                <?php endif; ?>

                <h3><?php esc_html_e( 'WP-Cron Hooks', 'processing-wp-rocket' ); ?></h3>
                <?php if ( empty( $status['wp_cron_hooks'] ) ) : ?>
                    <p><em><?php esc_html_e( 'No WP Rocket cron hooks detected.', 'processing-wp-rocket' ); ?></em></p>
                <?php else : ?>
                    <ul class="pwr-cron-list">
                    <?php foreach ( $status['wp_cron_hooks'] as $h ) : ?>
                        <li>
                            <code><?php echo esc_html( $h['hook'] ); ?></code> —
                            <?php
                            echo $h['next_run']
                                ? esc_html( wp_date( 'Y-m-d H:i:s', $h['next_run'] ) )
                                : esc_html__( 'not scheduled', 'processing-wp-rocket' );
                            ?>
                            <?php if ( ! empty( $h['overdue'] ) ) : ?>
                                <span class="pwr-badge pwr-status-failed"><?php esc_html_e( 'Overdue', 'processing-wp-rocket' ); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <h3><?php esc_html_e( 'Action Scheduler', 'processing-wp-rocket' ); ?></h3>
                <?php if ( empty( $status['action_scheduler']['available'] ) ) : ?>
                    <p><em><?php esc_html_e( 'Action Scheduler is not loaded on this site.', 'processing-wp-rocket' ); ?></em></p>
                <?php elseif ( empty( $status['action_scheduler']['hooks'] ) ) : ?>
                    <p><em><?php esc_html_e( 'No WP Rocket related scheduled actions found.', 'processing-wp-rocket' ); ?></em></p>
                <?php else : ?>
                    <ul class="pwr-cron-list">
                    <?php foreach ( $status['action_scheduler']['hooks'] as $a ) : ?>
                        <li>
                            <code><?php echo esc_html( $a['hook'] ); ?></code> —
                            <?php
                            echo $a['last_run']
                                ? esc_html( wp_date( 'Y-m-d H:i:s', $a['last_run'] ) )
                                : esc_html__( 'never run', 'processing-wp-rocket' );
                            ?>
                            <?php if ( $a['last_status'] ) : ?>
                                <span class="pwr-badge pwr-status-<?php echo esc_attr( 'complete' === $a['last_status'] ? 'completed' : 'failed' ); ?>">
                                    <?php echo esc_html( $a['last_status'] ); ?>
                                </span>
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
        </div>
        <?php
    }
}
