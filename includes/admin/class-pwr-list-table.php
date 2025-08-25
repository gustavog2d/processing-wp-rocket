<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class PWR_List_Table extends WP_List_Table {
    private $args;

    public function __construct( $args = [] ) {
        parent::__construct( [
            'singular' => 'pwr_url',
            'plural'   => 'pwr_urls',
            'ajax'     => false,
        ] );
        $this->args = wp_parse_args( $args, [ 'status' => '', 'search' => '', 'per_page' => 20 ] );
    }

    public function get_columns() {
        return [
            'cb'                => '<input type="checkbox" />',
            'url'               => __( 'URL', 'processing-wp-rocket' ),
            'cache'             => __( '[cache]', 'processing-wp-rocket' ),
            'rucss_desktop'     => __( '[css] [desktop]', 'processing-wp-rocket' ),
            'rucss_mobile'      => __( '[css] [mobile]', 'processing-wp-rocket' ),
            'lazy_desktop'      => __( '[lazy] [desktop]', 'processing-wp-rocket' ),
            'lazy_mobile'       => __( '[lazy] [mobile]', 'processing-wp-rocket' ),
            'priority_desktop'  => __( '[priority] [desktop]', 'processing-wp-rocket' ),
            'priority_mobile'   => __( '[priority] [mobile]', 'processing-wp-rocket' ),
            'updated'           => __( 'Last Update (any)', 'processing-wp-rocket' ),
            'actions'           => __( 'Actions', 'processing-wp-rocket' ),
        ];
    }

    protected function get_sortable_columns() {
        return [
            'url'     => [ 'url', false ],
            'updated' => [ 'updated', false ],
        ];
    }

    protected function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="pwr_bulk[]" value="%s" />', esc_attr( $item['url'] ) );
    }

    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'url':
                return sprintf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', esc_url( $item['url'] ), esc_html( $item['url'] ) );

            case 'cache':
                return $this->render_status_with_date( isset($item['cache']) ? $item['cache'] : '', isset($item['cache_updated']) ? $item['cache_updated'] : '' );

            case 'rucss_desktop':
                return $this->render_status_with_date( isset($item['rucss_desktop']) ? $item['rucss_desktop'] : '', isset($item['rucss_desktop_updated']) ? $item['rucss_desktop_updated'] : '' );
            case 'rucss_mobile':
                return $this->render_status_with_date( isset($item['rucss_mobile']) ? $item['rucss_mobile'] : '', isset($item['rucss_mobile_updated']) ? $item['rucss_mobile_updated'] : '' );

            case 'lazy_desktop':
                return $this->render_status_with_date( isset($item['lazy_desktop']) ? $item['lazy_desktop'] : '', isset($item['lazy_desktop_updated']) ? $item['lazy_desktop_updated'] : '' );
            case 'lazy_mobile':
                return $this->render_status_with_date( isset($item['lazy_mobile']) ? $item['lazy_mobile'] : '', isset($item['lazy_mobile_updated']) ? $item['lazy_mobile_updated'] : '' );

            case 'priority_desktop':
                return $this->render_status_with_date( isset($item['priority_desktop']) ? $item['priority_desktop'] : '', isset($item['priority_desktop_updated']) ? $item['priority_desktop_updated'] : '' );
            case 'priority_mobile':
                return $this->render_status_with_date( isset($item['priority_mobile']) ? $item['priority_mobile'] : '', isset($item['priority_mobile_updated']) ? $item['priority_mobile_updated'] : '' );

            case 'updated':
                $d = isset($item['updated']) ? $item['updated'] : '';
                return esc_html( $this->pwr_format_datetime( $d ) );

            case 'actions':
                return $this->render_row_actions( $item );

            default:
                return '';
        }
    }

    private function pwr_format_datetime( $date ) {
        $date = trim( (string) $date );
        if ( $date === '' ) { return ''; }
        $ts = strtotime( $date );
        if ( ! $ts ) { return $date; }
        $tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( wp_timezone_string() );
        $fmt = apply_filters( 'pwr_datetime_format', 'Y-m-d H:i:s' );
        return function_exists( 'wp_date' ) ? wp_date( $fmt, $ts, $tz ) : date_i18n( $fmt, $ts );
    }

    private function render_status_badge( $val ) {
        $val = (string) $val;
        if ( $val === '' ) {
            return '<span class="dashicons dashicons-minus"></span>';
        }
        $cls   = 'pwr-badge pwr-status-' . sanitize_html_class( strtolower( $val ) );
        $label = method_exists( 'PWR_Repository', 'label_for_status' ) ? PWR_Repository::label_for_status( $val ) : $val;
        return '<span class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</span>';
    }

    private function render_status_with_date( $status, $date ) {
        $badge = $this->render_status_badge( $status );
        $formatted = $this->pwr_format_datetime( $date );
        $date_html = $formatted !== '' ? '<br /><small class="pwr-date">' . esc_html( $formatted ) . '</small>' : '<br /><small class="pwr-date">—</small>';
        return '<div class="pwr-statusblock">' . $badge . $date_html . '</div>';
    }

    private function render_row_actions( $item ) {
        $ps = sprintf('<a class="button button-small" target="_blank" rel="noopener" href="https://pagespeed.web.dev/analysis?url=%s">%s</a>', rawurlencode( $item['url'] ), esc_html__( 'PageSpeed', 'processing-wp-rocket' ) );
        return '<div class="pwr-row-actions">' . $ps . '</div>';
    }

    public function get_bulk_actions() {
        return [];
    }

    public function prepare_items() {
        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [ $columns, $hidden, $sortable ];

        $paged    = $this->get_pagenum();
        $per_page = (int) $this->args['per_page'];
        $order_by = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'updated';
        $order    = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'desc';

        $data  = PWR_Repository::query_items( [
            'status'  => $this->args['status'],
            'search'  => $this->args['search'],
            'orderby' => $order_by,
            'order'   => $order,
            'paged'   => $paged,
            'per_page'=> $per_page,
        ] );

        $this->items = $data['items'];
        $this->set_pagination_args( [
            'total_items' => (int) $data['total'],
            'per_page'    => $per_page,
        ] );
    }
}
