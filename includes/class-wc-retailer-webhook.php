<?php

// If this file is called directly, abort.
if (!defined('WPINC') && !defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

/**
 * Handles webhook notifications for plugin lifecycle events
 */
class WC_Retailer_Webhook
{
    /**
     * Plugin states
     */
    const STATE_ACTIVE = 'active';
    const STATE_DEACTIVATED = 'deactivated';
    const STATE_DELETED = 'deleted';

    /**
     * Webhook endpoints
     */
    const ENDPOINT_PLUGIN_STATUS = 'api/woocommerce/plugin-state';

    private static function config()
    {
        return wc_retailer_config();
    }

    /**
     * @return string|null
     */
    public static function get_plugin_version()
    {
        $plugin_file = dirname(dirname(__FILE__)) . '/retailer.php';

        if (!file_exists($plugin_file)) {
            return null;
        }

        $plugin_data = get_file_data($plugin_file, array(
            'Version' => 'Version'
        ));

        return $plugin_data['Version'] ?? null;
    }

    /**
     * @return string
     */
    public static function get_plugin_state()
    {
        return WC_Retailer_Options::get(
            WC_Retailer_Options::PLUGIN_STATE(),
            self::STATE_DEACTIVATED
        );
    }

    /**
     * @param string $state
     * @return bool
     */
    public static function set_plugin_state($state)
    {
        return WC_Retailer_Options::set(
            WC_Retailer_Options::PLUGIN_STATE(),
            $state
        );
    }

    /**
     * @return bool
     */
    private static function has_valid_setup()
    {
        return WC_Retailer_Options::is_setup_completed() &&
               !empty(WC_Retailer_Options::get_secret_uuid());
    }

    /**
     * @param string $endpoint
     * @param array $payload
     * @param int $timeout
     * @return bool|WP_Error
     */
    private static function send_webhook($endpoint, $payload, $timeout = 5)
    {
        $config = self::config();
        $url = rtrim($config::SHOPWOO_BASE_URL, '/') . '/' . ltrim($endpoint, '/');

        $args = array(
            'body' => json_encode($payload),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'timeout' => $timeout,
            'blocking' => false,
            'sslverify' => !$config::DEBUG_MODE,
        );

        if ($config::DEBUG_MODE) {
            error_log(sprintf(
                'WC Retailer Webhook: Sending to %s with payload: %s',
                $url,
                json_encode($payload)
            ));
        }

        return wp_remote_post($url, $args);
    }

    /**
     * @return array
     */
    private static function build_base_payload()
    {
        $config = self::config();

        return array(
            'retailer_uuid' => $config::RETAILER_UUID,
            'secret_uuid' => WC_Retailer_Options::get_secret_uuid(),
            'store_url' => get_site_url(),
            'wp_version' => get_bloginfo('version'),
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : null,
            'php_version' => PHP_VERSION,
            'timestamp' => current_time('mysql'),
        );
    }


    /**
     * @return void
     */
    public static function handle_activation()
    {
        $is_setup_completed = self::has_valid_setup();

        $payload = array_merge(self::build_base_payload(), array(
            'event' => 'plugin_activated',
            'plugin_version' => self::get_plugin_version(),
            'state' => self::STATE_ACTIVE,
            'setup_completed' => $is_setup_completed,
            'message' => $is_setup_completed
                ? 'Plugin activated. Integration enabled.'
                : 'Plugin activated. Setup required.',
        ));

        self::send_webhook(self::ENDPOINT_PLUGIN_STATUS, $payload);
        self::set_plugin_state(self::STATE_ACTIVE);
    }

    /**
     * @return void
     */
    public static function handle_deactivation()
    {
        $payload = array_merge(self::build_base_payload(), array(
            'event' => 'plugin_deactivated',
            'plugin_version' => self::get_plugin_version(),
            'state' => self::STATE_DEACTIVATED,
            'message' => 'Plugin deactivated. Integration paused.',
        ));

        self::send_webhook(self::ENDPOINT_PLUGIN_STATUS, $payload);
        self::set_plugin_state(self::STATE_DEACTIVATED);
    }

    /**
     * @return void
     */
    public static function handle_deletion()
    {
        $payload = array_merge(self::build_base_payload(), array(
            'event' => 'plugin_deleted',
            'plugin_version' => self::get_plugin_version(),
            'state' => self::STATE_DELETED,
            'message' => 'Plugin deleted. All local data removed.',
        ));

        self::send_webhook(self::ENDPOINT_PLUGIN_STATUS, $payload);
    }

}