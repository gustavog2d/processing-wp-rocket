<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class PWR_Plugin {
    private static $instance;
    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() {
        require_once PWR_DIR . 'includes/admin/class-pwr-admin-page.php';
        require_once PWR_DIR . 'includes/admin/class-pwr-list-table.php';
        require_once PWR_DIR . 'includes/data/class-pwr-repository.php';
        require_once PWR_DIR . 'includes/integration/class-pwr-wprocket-adapter.php';
        require_once PWR_DIR . 'includes/rest/class-pwr-rest.php';
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
    }
    public function register_menu() {
        $cap = pwr_min_cap();
        add_management_page(
            __( 'Processing WP Rocket', 'processing-wp-rocket' ),
            __( 'Processing WP Rocket', 'processing-wp-rocket' ),
            $cap, 'pwr-admin', [ 'PWR_Admin_Page', 'render' ]
        );
    }
    public function enqueue_admin( $hook ) {
        if ( 'tools_page_pwr-admin' !== $hook ) { return; }
        wp_enqueue_style( 'pwr-admin', PWR_URL . 'assets/css/admin.css', [], PWR_VERSION );
        wp_enqueue_script( 'pwr-admin', PWR_URL . 'assets/js/admin.js', [ 'jquery' ], PWR_VERSION, true );
        wp_localize_script( 'pwr-admin', 'pwrConfig', [
            'nonce'      => wp_create_nonce( 'pwr_rest' ),
            'restBase'   => esc_url_raw( rest_url( 'processing-wp-rocket/v1' ) ),
            'restNonce'  => wp_create_nonce( 'wp_rest' ),
            'capability' => current_user_can( pwr_min_cap() ),
        ] );
    }
}
