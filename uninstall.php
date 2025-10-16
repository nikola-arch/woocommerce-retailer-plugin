<?php

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$classes_before = get_declared_classes();

require_once plugin_dir_path(__FILE__) . 'constants.php';

$classes_after = get_declared_classes();
$new_classes = array_diff($classes_after, $classes_before);
$config_class = null;

foreach ($new_classes as $class) {
	if (substr($class, -7) === '\Config' && defined($class . '::RETAILER_SLUG')) {
		$config_class = $class;
		break;
	}
}

if ($config_class === null) {
	foreach (get_declared_classes() as $class) {
		if (substr($class, -7) === '\Config' && class_exists($class)) {
			$reflection = new ReflectionClass($class);
			if ($reflection->hasConstant('RETAILER_SLUG')) {
				$config_class = $class;
				break;
			}
		}
	}
}

if ($config_class === null) {
	return;
}

if (!function_exists('wc_retailer_config')) {
	function wc_retailer_config() {
		global $config_class;
		return $config_class;
	}
}

require_once plugin_dir_path(__FILE__) . 'includes/class-wc-retailer-options.php';

WC_Retailer_Options::delete_all_data();
