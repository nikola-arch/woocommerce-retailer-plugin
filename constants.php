<?php
namespace BrandsGateway;
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
class Config {
    const RETAILER_NAME = 'BrandsGateway';
    const RETAILER_SLUG = 'brandsgateway';
    const RETAILER_DOMAIN = 'brandsgateway.com';
    const RETAILER_UUID = '67b8f613-7f05-415b-8e4e-5e33010dbc97';
    const SHOPWOO_BASE_URL = 'https://nova.shopwoo.com/';
    const DEBUG_MODE = false;
    public static $PLUGIN_URL = null;
    public static function init() {
          self::$PLUGIN_URL = plugin_dir_url( dirname( __FILE__ ) . '/brandsgateway-integration.php' );
    }
}
