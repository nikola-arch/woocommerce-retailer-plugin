<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class WC_Retailer_Activator extends WC_Retailer_Abstract_Loader {
    private static function config() {
        return wc_retailer_config();
    }

    protected $required_plugins = array(
        'woocommerce/woocommerce.php' => 'WooCommerce',
    );

    public function activate( array $active_plugins ) {

        $missing_plugins = $this->check_for_required_plugins( $active_plugins );

        if( !empty( $missing_plugins ) ) {
            array_map( array( $this, 'report_missing_plugin' ), $missing_plugins );
            return false;
        }

        return true;
    }

    public function check_for_required_plugins( array $active_plugins ) {

        $missing_plugins = $this->required_plugins;

        foreach ( $active_plugins as $plugin_handle ) {

            if( array_key_exists( $plugin_handle, $this->required_plugins ) ) {
                unset( $missing_plugins[ $plugin_handle ] );
            }
        }

        return $missing_plugins;
    }

    public function report_missing_plugin( $name ) {

        if( array_key_exists( $name, $this->required_plugins ) ) {
            $name = $this->required_plugins[ $name ];
        }

        $config = self::config();
        $error = sprintf(
            __(
                'One of the required plugins is not installed or active. The WooCommerce ' . $config::RETAILER_NAME . ' plugin will not work without it:',
                'wc-' . $config::RETAILER_SLUG
            ) . ' %s.',
            $name
        );


        if(is_admin()) {
            add_action('admin_notices', function() use ($error) {
                ?>
                <div class="notice notice-error">
                    <p>
                        <?php echo $error ?>
                    </p>
                </div>
                <?php
            });
        }

        return $name;
    }

    public function run()
    {

    }
}