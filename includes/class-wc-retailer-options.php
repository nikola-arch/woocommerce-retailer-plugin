<?php

// If this file is called directly, abort.
if (!defined('WPINC') && !defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

class WC_Retailer_Options
{
    private static function config() {
        return wc_retailer_config();
    }

    private static function key($name) {
        $config = self::config();
        return $config::RETAILER_SLUG . '_' . $name;
    }

    public static function SETUP_COMPLETED() { return self::key('setup_completed'); }
    public static function SECRET_UUID() { return self::key('secret_uuid'); }
    public static function EMAIL() { return self::key('email'); }
    public static function PLUGIN_STATE() { return self::key('plugin_state'); }
    public static function TEMP_EMAIL() { return self::key('temp_email'); }
    public static function TEMP_STORE_URL() { return self::key('temp_store_url'); }
    public static function VERIFICATION_ID() { return self::key('verification_id'); }
    public static function VERIFICATION_CODE() { return self::key('verification_code'); }
    public static function ACCESS_TOKEN() { return self::key('access_token'); }
    public static function OAUTH_IN_PROGRESS() { return self::key('oauth_in_progress'); }
    public static function OAUTH_SUCCESS() { return self::key('oauth_success'); }

    public static function get($option_name, $default = null)
    {
        return get_option($option_name, $default);
    }

    public static function set($option_name, $value)
    {
        return update_option($option_name, $value);
    }

    public static function delete($option_name)
    {
        return delete_option($option_name);
    }

    public static function is_setup_completed()
    {
        return (bool) self::get(self::SETUP_COMPLETED(), false);
    }

    public static function get_secret_uuid()
    {
        return self::get(self::SECRET_UUID());
    }

    public static function get_email()
    {
        return self::get(self::EMAIL());
    }
    
    public static function delete_setup_flow_data()
    {
        $setup_flow_options = [
            self::TEMP_EMAIL(),
            self::TEMP_STORE_URL(),
            self::VERIFICATION_ID(),
            self::VERIFICATION_CODE(),
            self::ACCESS_TOKEN(),
            self::OAUTH_IN_PROGRESS(),
            self::OAUTH_SUCCESS()
        ];

        foreach ($setup_flow_options as $option) {
            self::delete($option);
        }
    }
    
    public static function delete_all_data()
    {
        self::delete_setup_flow_data();

        $permanent_options = [
            self::SECRET_UUID(),
            self::EMAIL(),
            self::SETUP_COMPLETED(),
            self::PLUGIN_STATE()
        ];

        foreach ($permanent_options as $option) {
            self::delete($option);
        }
    }

    public static function get_temp_setup_data()
    {
        return [
            'email' => self::get(self::TEMP_EMAIL()),
            'store_url' => self::get(self::TEMP_STORE_URL()),
            'verification_id' => self::get(self::VERIFICATION_ID()),
            'verification_code' => self::get(self::VERIFICATION_CODE()),
            'access_token' => self::get(self::ACCESS_TOKEN())
        ];
    }

    public static function validate_temp_data()
    {
        $required = [
            self::TEMP_EMAIL(),
            self::TEMP_STORE_URL(),
            self::VERIFICATION_ID(),
            self::ACCESS_TOKEN()
        ];

        foreach ($required as $option) {
            if (empty(self::get($option))) {
                return false;
            }
        }

        return true;
    }
}