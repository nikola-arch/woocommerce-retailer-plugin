<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

abstract class WC_Retailer_Abstract_Loader {
    abstract public function run();
}
