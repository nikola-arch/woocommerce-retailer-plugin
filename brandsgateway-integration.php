<?php

/**
 * The plugin bootstrap file
 *
 * @wordpress-plugin
 * Plugin Name:       BrandsGateway Integration
 * Plugin URI:        https://brandsgateway.com
 * Description:       Plugin for BrandsGateway dropshippers to integrate with ShopWoo and import products
 * Version:           1.0.0
 * Requires PHP:      ^7.4
 * WC requires at least: ^9.6
 * Author:            BrandsGateway
 * Author URI:        https://brandsgateway.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wc-brandsgateway
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define('WC_BRANDSGATEWAY_VERSION', '1.0.0');
define('WC_BRANDSGATEWAY_MIN_PHP_VERSION', '7.4');
define('WC_BRANDSGATEWAY_MIN_WC_VERSION', '9.6');

/**
 * Check PHP version before loading anything else
 */
if (version_compare(PHP_VERSION, WC_BRANDSGATEWAY_MIN_PHP_VERSION, '<')) {
    add_action('admin_notices', function() {
        $plugin_data = get_file_data(__FILE__, array('Plugin Name' => 'Plugin Name'));
        $plugin_name = !empty($plugin_data['Plugin Name']) ? $plugin_data['Plugin Name'] : 'This plugin';
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php echo esc_html($plugin_name); ?></strong> requires PHP <?php echo esc_html(WC_BRANDSGATEWAY_MIN_PHP_VERSION); ?> or higher.
                You are running PHP <?php echo esc_html(PHP_VERSION); ?>.
                Please contact your hosting provider to upgrade PHP.
            </p>
        </div>
        <?php
    });
    return;
}

global $config_class;

require_once __DIR__ . '/constants.php';

$config_class = null;

foreach (get_declared_classes() as $class) {
    if (substr($class, -6) === 'Config' || strpos($class, '\\Config') !== false) {
        try {
            $reflection = new ReflectionClass($class);
            if ($reflection->hasConstant('RETAILER_SLUG') &&
                $reflection->hasConstant('RETAILER_NAME') &&
                $reflection->hasConstant('RETAILER_DOMAIN')) {
                $config_class = $class;
                break;
            }
        } catch (Exception $e) {
            continue;
        }
    }
}

if ($config_class === null) {
    wp_die('Retailer configuration not found. Please check constants.php');
}

function wc_retailer_config() {
    global $config_class;
    return $config_class;
}

if (function_exists('plugin_dir_url')) {
    $config_class::init();
}

require_once __DIR__ . '/includes/class-wc-retailer-options.php';
require_once __DIR__ . '/includes/class-wc-retailer-constants.php';
require_once __DIR__ . '/includes/class-wc-retailer-abstract-loader.php';
require_once __DIR__ . '/includes/class-wc-retailer-webhook.php';
require_once __DIR__ . '/includes/class-wc-retailer-activator.php';
require_once __DIR__ . '/includes/class-wc-retailer-plugin.php';
require_once __DIR__ . '/includes/setup/class-wc-retailer-setup.php';
require_once __DIR__ . '/includes/dashboard/class-wc-retailer-dashboard.php';
require_once __DIR__ . '/includes/class-wc-retailer-rest-api-extensions.php';

function activate_wc_retailer() {
    $activator = new WC_Retailer_Activator();
    $activator->activate( apply_filters( 'active_plugins', get_option('active_plugins' ) ) );
}

function deactivate_wc_retailer() {
    WC_Retailer_Webhook::handle_deactivation();
}

register_activation_hook( __FILE__, 'activate_wc_retailer' );
register_deactivation_hook( __FILE__, 'deactivate_wc_retailer' );

function run_wc_retailer() {

    $activator = new WC_Retailer_Activator();

    $missing_plugins = $activator->check_for_required_plugins( apply_filters( 'active_plugins', get_option('active_plugins' ) ) );

    if( !empty( $missing_plugins ) ) {
        array_map( array( $activator, 'report_missing_plugin' ), $missing_plugins );
        return;
    }

    if (!$activator->check_woocommerce_version()) {
        return;
    }

    $plugin = new WC_Retailer_Plugin();
    $plugin->run();
}

add_action( 'plugins_loaded', 'run_wc_retailer' );