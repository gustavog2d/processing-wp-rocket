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
        require_once PWR_DIR . 'includes/data/class-pwr-error-log.php';
        require_once PWR_DIR . 'includes/integration/class-pwr-wprocket-adapter.php';
        require_once PWR_DIR . 'includes/integration/class-pwr-cron-status.php';
        require_once PWR_DIR . 'includes/integration/class-pwr-cache-inspector.php';
        require_once PWR_DIR . 'includes/integration/class-pwr-environment.php';
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
    }
}
