<?php

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $config_class;

require_once plugin_dir_path(__FILE__) . 'constants.php';

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
    error_log('WC Retailer Uninstall: Config class not found');
    return;
}

if (!function_exists('wc_retailer_config')) {
    function wc_retailer_config() {
        global $config_class;
        return $config_class;
    }
}

require_once plugin_dir_path(__FILE__) . 'includes/class-wc-retailer-options.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-retailer-webhook.php';

try {
    WC_Retailer_Webhook::handle_deletion();
    
    usleep(100000);

    WC_Retailer_Options::delete_all_data();
} catch (Exception $e) {
    error_log('WC Retailer Uninstall Error: ' . $e->getMessage());
}