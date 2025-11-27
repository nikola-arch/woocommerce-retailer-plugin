<?php

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class WC_Retailer_Constants
{
    const API_TIMEOUT = 30;
    const EMAIL_REDIRECT_DELAY = 2000;
    const VERIFICATION_REDIRECT_DELAY = 1500;
    const REGISTRATION_REDIRECT_DELAY = 3000;
    const NOTICE_AUTO_DISMISS_DELAY = 5000;
    const IFRAME_LOADING_TIMEOUT = 8000;
    const IFRAME_ANIMATION_DURATION = 300;
    const REGISTRATION_COMPLETION_DELAY = 1000;
    const MIN_IFRAME_HEIGHT = 400;
    const MENU_POSITION = 30;
    const MAX_FORM_WIDTH = 400;
    const HTTP_OK = 200;
}