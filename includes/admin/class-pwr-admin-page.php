<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class PWR_Admin_Page {
    public static function render() {
        if ( ! current_user_can( pwr_min_cap() ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'processing-wp-rocket' ) );
        }
        $status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $per_page = (int) apply_filters( 'pwr_table_per_page', 20 );
        $list_table = new PWR_List_Table( [ 'status' => $status, 'search' => $search, 'per_page' => $per_page ] );
        $list_table->prepare_items();
        $statuses = PWR_Repository::get_status_filters();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html__( 'Processing WP Rocket', 'processing-wp-rocket' ); ?></h1>
            <hr class="wp-header-end"/>
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
}
