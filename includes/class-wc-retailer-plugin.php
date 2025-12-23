<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class WC_Retailer_Plugin {

    protected $loaders = array();

    public function __construct() {
        $this->loaders = array(
            new WC_Retailer_Setup(),
            new WC_Retailer_Dashboard(),
            new WC_Retailer_REST_API_Extensions()
        );
    }

    public function init_loaders() {
        foreach ( $this->loaders as $loader ) {
            /* @var $loader WC_Retailer_Abstract_Loader */
            if( ! $loader instanceof WC_Retailer_Abstract_Loader ) {
                throw new RuntimeException(
                    sprintf(
                        'Class %s must be an instance of %s',
                        get_class($loader),
                        WC_Retailer_Abstract_Loader::class
                    )
                );
            }
            $loader->run();
        }
    }

    public function run() {
        $this->init_loaders();
    }
}
