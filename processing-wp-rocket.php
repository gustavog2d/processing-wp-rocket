<?php
/**
 * Plugin Name:       Processing WP Rocket
 * Plugin URI:        https://github.com/gustavog2d/processing-wp-rocket
 * Description:       Admin list of URLs processed by WP Rocket with filters, bulk actions, per-row cache purges (page/RUCSS/Priority Elements), and one-click PageSpeed test.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Gustavo Henrique
 * Author URI:        https://github.com/gustavog2d
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       processing-wp-rocket
 * Domain Path:       /languages
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'PWR_VERSION', '1.5.0' );
define( 'PWR_FILE', __FILE__ );
define( 'PWR_DIR', plugin_dir_path( __FILE__ ) );
define( 'PWR_URL', plugin_dir_url( __FILE__ ) );
require_once PWR_DIR . 'includes/helpers.php';
function pwr_load_textdomain() {
    load_plugin_textdomain( 'processing-wp-rocket', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'pwr_load_textdomain' );
require_once PWR_DIR . 'includes/class-pwr-plugin.php';
PWR_Plugin::instance();
