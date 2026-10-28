<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class PWR_List_Table extends WP_List_Table {
	private $args;
	private $error = '';

	public function __construct( $args = [] ) {
		parent::__construct( [
			'singular' => 'pwr_url',
			'plural'   => 'pwr_urls',
			'ajax'     => false,
		] );
		$this->args = wp_parse_args( $args, [ 'status' => '', 'search' => '', 'search_mode' => 'contains', 'per_page' => 20 ] );
	}

	public function get_error() {
		return $this->error;
	}

	public function get_columns() {
		return [
			'url'              => __( 'URL', 'processing-wp-rocket' ),
			'file'             => __( '[file]', 'processing-wp-rocket' ),
			'cache'            => __( '[cache]', 'processing-wp-rocket' ),
			'rucss_desktop'    => __( '[css] [desktop]', 'processing-wp-rocket' ),
			'rucss_mobile'     => __( '[css] [mobile]', 'processing-wp-rocket' ),
			'lazy_desktop'     => __( '[lazy] [desktop]', 'processing-wp-rocket' ),
			'lazy_mobile'      => __( '[lazy] [mobile]', 'processing-wp-rocket' ),
			'priority_desktop' => __( '[priority] [desktop]', 'processing-wp-rocket' ),
			'priority_mobile'  => __( '[priority] [mobile]', 'processing-wp-rocket' ),
			'updated'          => __( 'Last Update (any)', 'processing-wp-rocket' ),
			'actions'          => __( 'Actions', 'processing-wp-rocket' ),
		];
	}

	protected function get_sortable_columns() {
		return [
			'url'     => [ 'url', false ],
			'updated' => [ 'updated', false ],
		];
	}

	public function column_default( $item, $column_name ) {
		if ( in_array( $column_name, PWR_Repository::FIELDS, true ) ) {
			$error = isset( $item['errors'][ $column_name ] ) ? $item['errors'][ $column_name ] : null;
			return $this->render_status_with_date( $item[ $column_name ] ?? '', $item[ $column_name . '_updated' ] ?? '', $error );
		}
		switch ( $column_name ) {
			case 'url':
				return sprintf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', esc_url( $item['url'] ), esc_html( $item['url'] ) );
			case 'file':
				return $this->render_file_cell( $item['url'] );
			case 'updated':
				return esc_html( self::format_datetime( $item['updated'] ?? '' ) );
			case 'actions':
				return $this->render_row_actions( $item );
			default:
				return '';
		}
	}

	/** Formats a GMT database timestamp (or a Unix timestamp) in the site timezone. */
	public static function format_datetime( $date ) {
		if ( is_int( $date ) ) {
			$ts = $date;
		} else {
			if ( PWR_Repository::is_empty_date( $date ) ) { return ''; }
			$ts = strtotime( (string) $date );
			if ( ! $ts ) { return (string) $date; }
		}
		return wp_date( apply_filters( 'pwr_datetime_format', 'Y-m-d H:i:s' ), $ts, wp_timezone() );
	}

	private function render_file_cell( $url ) {
		$summary = PWR_Cache_Inspector::file_summary( $url );
		$out     = $this->file_line( __( 'desktop', 'processing-wp-rocket' ), $summary['desktop'] );
		if ( false !== $summary['mobile'] ) {
			$out .= $this->file_line( __( 'mobile', 'processing-wp-rocket' ), $summary['mobile'] );
		}
		return '<div class="pwr-statusblock">' . $out . '</div>';
	}

	private function file_line( $label, $mtime ) {
		if ( null === $mtime ) {
			return '<span class="pwr-file-line"><span class="pwr-badge pwr-status-failed" title="' . esc_attr__( 'No cache file on disk for this variant', 'processing-wp-rocket' ) . '">' . esc_html( $label ) . ' ✗</span></span>';
		}
		/* translators: %s: human readable time difference, e.g. "3 hours" */
		$age = sprintf( __( '%s ago', 'processing-wp-rocket' ), human_time_diff( $mtime ) );
		return '<span class="pwr-file-line"><span class="pwr-badge pwr-status-completed" title="' . esc_attr( self::format_datetime( $mtime ) ) . '">' . esc_html( $label ) . ' ✓</span><br /><small class="pwr-date">' . esc_html( $age ) . '</small></span>';
	}

	private function render_status_badge( $val ) {
		$val = (string) $val;
		if ( '' === $val ) {
			return '<span class="dashicons dashicons-minus"></span>';
		}
		$slug  = PWR_Repository::normalize_status( $val );
		$cls   = 'pwr-badge pwr-status-' . sanitize_html_class( $slug );
		$label = PWR_Repository::label_for_status( $val );
		// Keep the raw WP Rocket value visible on hover (e.g. "to-submit" vs "pending").
		return '<span class="' . esc_attr( $cls ) . '" title="' . esc_attr( $val ) . '">' . esc_html( $label ) . '</span>';
	}

	private function render_status_with_date( $status, $date, $error = null ) {
		$badge     = $this->render_status_badge( $status );
		$formatted = self::format_datetime( $date );
		$html      = '<div class="pwr-statusblock">' . $badge . '<br /><small class="pwr-date">' . esc_html( '' !== $formatted ? $formatted : '—' ) . '</small>';
		if ( $error && ( '' !== $error['message'] || '' !== $error['code'] ) ) {
			$text  = trim( ( '' !== $error['code'] ? '[' . $error['code'] . '] ' : '' ) . $error['message'] );
			$short = function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $text, 0, 80, '…' ) : substr( $text, 0, 80 );
			$html .= '<br /><small class="pwr-error" title="' . esc_attr( $text ) . '">' . esc_html( $short ) . '</small>';
		}
		return $html . '</div>';
	}

	private function render_row_actions( $item ) {
		$inspect = add_query_arg(
			[ 'page' => 'pwr-admin', 'pwr_inspect' => rawurlencode( $item['url'] ) ],
			admin_url( 'tools.php' )
		);
		$links  = sprintf( '<a class="button button-small" href="%s">%s</a>', esc_url( $inspect . '#pwr-inspect' ), esc_html__( 'Inspect', 'processing-wp-rocket' ) );
		$links .= sprintf( '<a class="button button-small" target="_blank" rel="noopener" href="https://pagespeed.web.dev/analysis?url=%s">%s</a>', rawurlencode( $item['url'] ), esc_html__( 'PageSpeed', 'processing-wp-rocket' ) );
		return '<div class="pwr-row-actions">' . $links . '</div>';
	}

	public function get_bulk_actions() {
		return [];
	}

	public function prepare_items() {
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		$per_page = (int) $this->args['per_page'];
		$data     = PWR_Repository::query_items( [
			'status'      => $this->args['status'],
			'search'      => $this->args['search'],
			'search_mode' => $this->args['search_mode'],
			'orderby'     => isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'updated', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'order'       => isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'desc', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'paged'       => $this->get_pagenum(),
			'per_page'    => $per_page,
		] );

		$this->items = $data['items'];
		$this->error = isset( $data['error'] ) ? (string) $data['error'] : '';
		$this->set_pagination_args( [
			'total_items' => (int) $data['total'],
			'per_page'    => $per_page,
		] );
	}
}
