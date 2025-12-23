<?php

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class WC_Retailer_Dashboard extends WC_Retailer_Abstract_Loader
{
    private static function config() {
        return wc_retailer_config();
    }

    public function run()
    {
        add_action('admin_menu', array($this, 'add_dashboard_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_dashboard_scripts'));
    }

    public function add_dashboard_menu()
    {
        $config = self::config();
        if (WC_Retailer_Options::is_setup_completed()) {
            add_menu_page(
                $config::RETAILER_NAME,
                $config::RETAILER_NAME,
                'manage_options',
                $config::RETAILER_SLUG . '-home',
                array($this, 'render_page'),
                'dashicons-store',
                WC_Retailer_Constants::MENU_POSITION
            );

            add_submenu_page(
                $config::RETAILER_SLUG . '-home',
                'Home',
                'Home',
                'manage_options',
                $config::RETAILER_SLUG . '-home',
                array($this, 'render_page')
            );

            add_submenu_page(
                $config::RETAILER_SLUG . '-home',
                'Products',
                'Products',
                'manage_options',
                $config::RETAILER_SLUG . '-products-importer',
                array($this, 'render_page')
            );

            add_submenu_page(
                $config::RETAILER_SLUG . '-home',
                'Orders',
                'Orders',
                'manage_options',
                $config::RETAILER_SLUG . '-orders',
                array($this, 'render_page')
            );

            add_submenu_page(
                $config::RETAILER_SLUG . '-home',
                'Settings',
                'Settings',
                'manage_options',
                $config::RETAILER_SLUG . '-settings',
                array($this, 'render_page')
            );

            add_submenu_page(
                $config::RETAILER_SLUG . '-home',
                'Support',
                'Support',
                'manage_options',
                $config::RETAILER_SLUG . '-support',
                array($this, 'render_page')
            );

            add_submenu_page(
                $config::RETAILER_SLUG . '-home',
                'Help Desk',
                'Help Desk',
                'manage_options',
                $config::RETAILER_SLUG . '-helpdesk',
                array($this, 'render_page')
            );
        }
    }

    public function enqueue_dashboard_scripts()
    {
        $config = self::config();
        if (isset($_GET['page']) && strpos($_GET['page'], $config::RETAILER_SLUG . '-') === 0) {
            $script_file = $config::DEBUG_MODE
                ? 'assets/src/dashboard.js'
                : 'assets/dist/dashboard.min.js';

            $version = $config::DEBUG_MODE
                ? time()
                : WC_BRANDSGATEWAY_VERSION;

            wp_enqueue_script(
                $config::RETAILER_SLUG . '-dashboard',
                $config::$PLUGIN_URL . $script_file,
                array('jquery'),
                $version,
                true
            );

            wp_localize_script($config::RETAILER_SLUG . '-dashboard', $config::RETAILER_SLUG . '_dashboard', array(
                'iframe_url' => $this->get_iframe_url(),
                'shopwoo_base_url' => $config::SHOPWOO_BASE_URL,
                'retailer_slug' => $config::RETAILER_SLUG
            ));
        }
    }

    public function render_page()
    {
        $config = self::config();
        $page_config = $this->get_page_config();
        $iframe_url = $this->get_iframe_url_for_page($page_config['route']);

        ?>
        <div class="wrap" id="<?php echo $config::RETAILER_SLUG; ?>-dashboard">
            <?php if ($iframe_url): ?>
            <div id="iframe-fallback"
                 style="display: none; text-align: center; padding: 20px; background: #f9f9f9; margin-bottom: 20px;">
                <p><strong>Having trouble loading the page?</strong></p>
                <p><strong>Brave Browser Users:</strong> Click the shield icon 🛡️ and disable shields for this site.</p>
                <p><a href="<?php echo esc_url($iframe_url); ?>" target="_blank" class="button button-primary">Open in
                        New Tab</a></p>
                <p><small>Some privacy browsers block iframe content for security. This is normal behavior.</small></p>
            </div>

            <iframe
                    id="shopwoo-iframe"
                    src="<?php echo esc_url($iframe_url); ?>"
                    width="100%"
                    frameborder="0"
                    style="border: none;">
            </iframe>
        </div>
    <?php else: ?>
        <div class="notice notice-error">
            <p>Unable to load <?php echo esc_html($page_config['title']); ?>. Please complete the setup process
                first.</p>
            <p><a href="<?php echo admin_url('admin.php?page=' . $config::RETAILER_SLUG . '-setup'); ?>" class="button-primary">Go to
                    Setup</a></p>
        </div>
    <?php endif; ?>

        <style>
            .wrap#<?php echo $config::RETAILER_SLUG; ?>-dashboard {
                margin-right: 20px;
            }

            #shopwoo-iframe {
                display: block;
                width: 100%;
                height: 100vh;
                border: none;
                transition: height 0.3s ease;
            }

            .iframe-loading {
                text-align: center;
                padding: 40px;
                background: #f9f9f9;
            }
        </style>

        <script>
            jQuery(document).ready(function ($) {
                if (typeof <?php echo $config::RETAILER_SLUG; ?>_dashboard === 'undefined') {
                    window.<?php echo $config::RETAILER_SLUG; ?>_dashboard = {
                        iframe_url: '<?php echo esc_js($iframe_url); ?>',
                        shopwoo_base_url: '<?php echo esc_js($config::SHOPWOO_BASE_URL); ?>'
                    };
                }
            });
        </script>
        <?php
    }

    private function get_iframe_url()
    {
        return $this->generate_secure_iframe_url('/home');
    }

    private function generate_secure_iframe_url($route_path = '/home')
    {
        $config = self::config();
        $retailer_uuid = $config::RETAILER_UUID;
        $secret_uuid = WC_Retailer_Options::get_secret_uuid();

        if (empty($retailer_uuid) || empty($secret_uuid)) {
            return false;
        }

        return $this->build_signed_url($retailer_uuid, $secret_uuid, $route_path);
    }

    private function build_signed_url($retailer_uuid, $secret_uuid, $route_path)
    {
        $config = self::config();
        $params = $this->get_url_params();
        $signature = $this->generate_signature($params, $secret_uuid);
        $params['signature'] = $signature;

        $base_url = rtrim($config::SHOPWOO_BASE_URL, '/');
        $route_path = ltrim($route_path, '/');

        return $base_url . '/woocommerce-apps/retailer/' . $retailer_uuid . '/' . $route_path . '?'
            . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function get_url_params()
    {
        $current_user = wp_get_current_user();
        $user_name = trim($current_user->display_name);

        if (empty($user_name)) {
            $user_name = $current_user->user_email;
        }

        return [
            'email' => WC_Retailer_Options::get_email(),
            'current_user_email' => $current_user->user_email,
            'user_name' => $user_name,
            'store_url' => get_site_url(),
            'plugin_version' => $this->get_plugin_version()
        ];
    }

    private function get_plugin_version()
    {
        if (!function_exists('get_plugin_data')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        $plugin_file = dirname(dirname(__DIR__)) . '/retailer.php';
        $plugin_data = get_plugin_data($plugin_file);

        return $plugin_data['Version'];
    }

    private function generate_signature($params, $secret_uuid)
    {
        ksort($params);
        $query_string = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return hash_hmac('sha256', $query_string, $secret_uuid);
    }

    private function get_page_config()
    {
        $config = self::config();
        $current_page = isset($_GET['page']) ? $_GET['page'] : $config::RETAILER_SLUG . '-home';

        $page_map = [
            $config::RETAILER_SLUG . '-home' => ['title' => 'Home', 'route' => '/home'],
            $config::RETAILER_SLUG . '-products-importer' => ['title' => 'Products', 'route' => '/products-importer'],
            $config::RETAILER_SLUG . '-orders' => ['title' => 'Orders', 'route' => '/orders'],
            $config::RETAILER_SLUG . '-settings' => ['title' => 'Settings', 'route' => '/settings'],
            $config::RETAILER_SLUG . '-support' => ['title' => 'Support', 'route' => '/support'],
            $config::RETAILER_SLUG . '-helpdesk' => ['title' => 'Help Desk', 'route' => '/helpdesk'],
        ];

        return isset($page_map[$current_page]) ? $page_map[$current_page] : $page_map[$config::RETAILER_SLUG . '-home'];
    }

    private function get_iframe_url_for_page($route)
    {
        return $this->generate_secure_iframe_url($route);
    }
}