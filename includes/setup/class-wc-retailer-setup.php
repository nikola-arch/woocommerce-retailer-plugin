<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class WC_Retailer_Setup extends WC_Retailer_Abstract_Loader {
    private static function config() {
        return wc_retailer_config();
    }

    public function run() {
        $config = self::config();
        add_action('admin_menu', array($this, 'add_setup_page'));
        add_action('wp_ajax_' . $config::RETAILER_SLUG . '_submit_setup', array($this, 'handle_setup_submission'));
        add_action('wp_ajax_' . $config::RETAILER_SLUG . '_verify_email', array($this, 'handle_email_verification'));
        add_action('wp_ajax_' . $config::RETAILER_SLUG . '_complete_registration', array($this, 'handle_complete_registration_ajax'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_setup_scripts'));
        add_action('init', array($this, 'handle_success_oauth'));
    }

    public function add_setup_page() {
        $config = self::config();
        if (!WC_Retailer_Options::is_setup_completed()) {
            add_menu_page(
                $config::RETAILER_NAME,
                $config::RETAILER_NAME,
                'manage_options',
                $config::RETAILER_SLUG . '-setup',
                array($this, 'render_setup_page'),
                'dashicons-store',
                WC_Retailer_Constants::MENU_POSITION
            );
        }
    }

    public function enqueue_setup_scripts() {
        $config = self::config();
        if (isset($_GET['page']) && $_GET['page'] === $config::RETAILER_SLUG . '-setup') {
            wp_enqueue_script($config::RETAILER_SLUG . '-setup', $config::$PLUGIN_URL . 'assets/setup.js', array('jquery'), '1.0.0', true);
            wp_localize_script($config::RETAILER_SLUG . '-setup', $config::RETAILER_SLUG . '_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'shopwoo_base_url' => $config::SHOPWOO_BASE_URL,
                'admin_url' => admin_url(),
                'nonce' => wp_create_nonce($config::RETAILER_SLUG . '_setup_nonce'),
                'retailer_slug' => $config::RETAILER_SLUG,
                'ajax_action_prefix' => $config::RETAILER_SLUG . '_'
            ));
        }
    }

    public function render_setup_page() {
        $config = self::config();
        $step = isset($_GET['step']) ? intval($_GET['step']) : 1;
        $complete_registration = isset($_GET['complete_registration']) && $_GET['complete_registration'] === '1';
        $email = get_bloginfo('admin_email');
        $store_url = get_site_url();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html($config::RETAILER_NAME); ?> Setup</h1>

            <div id="<?php echo esc_attr($config::RETAILER_SLUG); ?>-setup-container">
                <?php if ($step === 1): ?>
                    <div id="step-1">
                        <h2>Step 1: Store Information</h2>
                        <form id="<?php echo esc_attr($config::RETAILER_SLUG); ?>-setup-form">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="email">Email Address</label>
                                    </th>
                                    <td>
                                        <input type="email" id="email" name="email" value="<?php echo esc_attr($email); ?>" class="regular-text" required />
                                        <p class="description">This email will be used for verification and notifications.</p>
                                    </td>
                                </tr>
                            </table>
                            <input type="hidden" name="retailer_uuid" value="<?php echo esc_attr($config::RETAILER_UUID); ?>" />
                            <p class="submit">
                                <button type="submit" class="button-primary">Submit & Verify Email</button>
                            </p>
                        </form>
                    </div>
                <?php elseif ($step === 2): ?>
                    <?php if ($complete_registration): ?>
                        <div id="step-2-registration">
                            <h2>Step 2: Completing Registration</h2>
                            <div class="notice success">
                                <p><strong>Almost done!</strong> WooCommerce authorization successful.</p>
                            </div>

                            <div id="registration-progress">
                                <div class="registration-spinner">
                                    <div class="spinner is-active"></div>
                                    <p id="registration-status">Completing registration with <?php echo esc_html($config::RETAILER_NAME); ?>...</p>
                                </div>

                                <div class="registration-steps">
                                    <ul>
                                        <li class="completed">✓ Email verified</li>
                                        <li class="completed">✓ WooCommerce authorized</li>
                                        <li class="in-progress">⏳ Completing registration and compatibility check...</li>
                                        <li class="pending">⏸ Loading dashboard</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div id="step-2">
                            <h2>Step 2: Email Verification</h2>
                            <p>We've sent a verification code to your email. Please enter it below:</p>
                            <form id="<?php echo esc_attr($config::RETAILER_SLUG); ?>-verify-form">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">
                                            <label for="verification_code">Verification Code</label>
                                        </th>
                                        <td>
                                            <input type="text" id="verification_code" name="verification_code" class="regular-text" required />
                                            <p class="description">Enter the code sent to your email.</p>
                                        </td>
                                    </tr>
                                </table>
                                <p class="submit">
                                    <button type="submit" class="button-primary">Verify & Authorize WooCommerce</button>
                                </p>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div id="<?php echo esc_attr($config::RETAILER_SLUG); ?>-messages"></div>
        </div>

        <style>
            #<?php echo esc_attr($config::RETAILER_SLUG); ?>-setup-container {
                max-width: 600px;
            }
            #<?php echo esc_attr($config::RETAILER_SLUG); ?>-messages {
                margin-top: 20px;
            }
            .notice {
                padding: 12px;
                margin: 5px 0 15px;
                border-left: 4px solid #00a0d2;
                background: #fff;
            }
            .notice.error, .notice.notice-error {
                border-left-color: #dc3232;
            }
            .notice.success {
                border-left-color: #46b450;
            }
            #step-2-success, #step-2-registration {
                text-align: center;
            }

            /* Registration progress styling */
            #registration-progress {
                margin: 30px 0;
                padding: 20px;
                background: #f9f9f9;
                border-radius: 8px;
            }
            .registration-spinner {
                margin-bottom: 30px;
            }
            .registration-spinner .spinner {
                float: none;
                margin: 0 auto 15px;
                display: block;
            }
            #registration-status {
                font-size: 16px;
                color: #555;
                margin: 0;
            }
            .registration-steps {
                text-align: left;
                max-width: <?php echo WC_Retailer_Constants::MAX_FORM_WIDTH; ?>px;
                margin: 0 auto;
            }
            .registration-steps ul {
                list-style: none;
                padding: 0;
                margin: 0;
            }
            .registration-steps li {
                padding: 10px 0;
                font-size: 15px;
                border-bottom: 1px solid #eee;
            }
            .registration-steps li:last-child {
                border-bottom: none;
            }
            .registration-steps li.completed {
                color: #46b450;
                font-weight: 500;
            }
            .registration-steps li.in-progress {
                color: #0073aa;
                font-weight: 500;
            }
            .registration-steps li.pending {
                color: #999;
            }

            .retailer-debug-info {
                margin-top: 15px;
                padding: 15px;
                background: #f7f7f7;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 12px;
            }
            .retailer-debug-info h4 {
                margin: 0 0 10px 0;
                font-size: 14px;
                color: #333;
                border-bottom: 1px solid #ddd;
                padding-bottom: 5px;
            }
            .retailer-debug-info p {
                margin: 8px 0;
                line-height: 1.4;
            }
            .retailer-debug-info strong {
                color: #555;
            }
            .debug-json {
                background: #fff;
                border: 1px solid #ccc;
                padding: 10px;
                font-family: 'Courier New', monospace;
                font-size: 11px;
                line-height: 1.3;
                white-space: pre-wrap;
                word-wrap: break-word;
                max-height: 200px;
                overflow-y: auto;
                margin: 5px 0;
                border-radius: 3px;
            }
        </style>
        <?php
    }

    public function handle_setup_submission() {
        $config = self::config();
        if (!wp_verify_nonce($_POST['nonce'], $config::RETAILER_SLUG . '_setup_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }

        $email = sanitize_email($_POST['email']);
        $store_url = get_site_url();
        $retailer_uuid = sanitize_text_field($_POST['retailer_uuid']);

        if (!is_email($email)) {
            wp_send_json_error('Invalid email address');
            return;
        }

        $response = $this->verify_woocommerce($email, $store_url, $retailer_uuid);

        if (is_wp_error($response)) {
            $debug_info = [
                'endpoint' => $config::SHOPWOO_BASE_URL . 'api/woocommerce/verify-store',
                'request_data' => [
                    'email' => $email,
                    'store_url' => $store_url,
                    'retailer_uuid' => $retailer_uuid
                ],
                'error' => $response->get_error_message()
            ];
            $error_message = 'Verification failed! ' . $response->get_error_message();
            $response_data = ['message' => $error_message];
            if ($config::DEBUG_MODE) {
                $response_data['debug'] = $debug_info;
            }
            wp_send_json_error($response_data);
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $response_code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);


        $debug_info = [
            'endpoint' => $config::SHOPWOO_BASE_URL . 'api/woocommerce/verify-store',
            'response_code' => $response_code,
            'raw_response' => $raw_body,
            'parsed_response' => $body,
            'request_data' => [
                'email' => $email,
                'store_url' => $store_url,
                'retailer_uuid' => $retailer_uuid
            ]
        ];

        if ($response_code !== WC_Retailer_Constants::HTTP_OK) {
            $response_data = [
                'message' => $this->format_error_message($response_code, $body, 'Verification')
            ];

            if ($config::DEBUG_MODE) {
                $response_data['debug'] = $debug_info;
            }

            wp_send_json_error($response_data);
            return;
        }

        WC_Retailer_Options::set(WC_Retailer_Options::TEMP_EMAIL(), $email);
        WC_Retailer_Options::set(WC_Retailer_Options::TEMP_STORE_URL(), $store_url);
        WC_Retailer_Options::set(WC_Retailer_Options::VERIFICATION_ID(), $body['verification_id']);
        WC_Retailer_Options::set(WC_Retailer_Options::ACCESS_TOKEN(), $body['access_token']);

        wp_send_json_success('Verification email sent. Please check your email for the code.');
    }

    public function handle_email_verification() {
        $config = self::config();
        if (!wp_verify_nonce($_POST['nonce'], $config::RETAILER_SLUG . '_setup_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }

        $verification_code = sanitize_text_field($_POST['verification_code']);
        $email = WC_Retailer_Options::get(WC_Retailer_Options::TEMP_EMAIL());
        $verification_id = WC_Retailer_Options::get(WC_Retailer_Options::VERIFICATION_ID());
        $access_token = WC_Retailer_Options::get(WC_Retailer_Options::ACCESS_TOKEN());
        $retailer_uuid = $config::RETAILER_UUID;

        WC_Retailer_Options::set(WC_Retailer_Options::VERIFICATION_CODE(), $verification_code);

        if (empty($verification_code)) {
            wp_send_json_error('Verification code is required');
            return;
        }


        $response = $this->verify_email_code($email, $verification_code, $verification_id, $access_token, $retailer_uuid);

        if (is_wp_error($response)) {
            wp_send_json_error('Failed to verify code: ' . $response->get_error_message());
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $response_code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);


        $debug_info = [
            'endpoint' => $config::SHOPWOO_BASE_URL . 'api/woocommerce/verify-email',
            'response_code' => $response_code,
            'raw_response' => $raw_body,
            'parsed_response' => $body,
            'request_data' => [
                'retailer_uuid' => $retailer_uuid,
                'email' => $email,
                'access_token' => $access_token,
                'verification_code' => $verification_code,
                'verification_id' => $verification_id,
            ]
        ];

        if ($response_code !== WC_Retailer_Constants::HTTP_OK) {
            $response_data = [
                'message' => $this->format_error_message($response_code, $body, 'Email verification')
            ];

            if ($config::DEBUG_MODE) {
                $response_data['debug'] = $debug_info;
            }

            wp_send_json_error($response_data);
            return;
        }

        if (isset($body['redirect_url'])) {
            WC_Retailer_Options::set(WC_Retailer_Options::OAUTH_IN_PROGRESS(), true);

            $response_data = [
                'message' => 'Email verified! Redirecting to WooCommerce authorization...',
                'redirect_url' => $body['redirect_url']
            ];
            if ($config::DEBUG_MODE) {
                $response_data['debug'] = $debug_info;
            }
            wp_send_json_success($response_data);
            return;
        }

        $response_data = ['message' => 'Email verification failed! No redirect URL received from server'];
        if ($config::DEBUG_MODE) {
            $response_data['debug'] = $debug_info;
        }
        wp_send_json_error($response_data);
    }

    public function handle_success_oauth() {
        $config = self::config();
        // Generic OAuth callback parameter that works for all retailers
        if (!isset($_GET['wc-oauth-success'])) {
            return;
        }

        if (!WC_Retailer_Options::get(WC_Retailer_Options::OAUTH_IN_PROGRESS())) {
            wp_die('Invalid OAuth callback. No active OAuth session found.');
        }

        $temp_data = WC_Retailer_Options::get_temp_setup_data();
        $email = $temp_data['email'];
        $store_url = $temp_data['store_url'];
        $verification_id = $temp_data['verification_id'];
        $access_token = $temp_data['access_token'];

        if (empty($email) || empty($store_url) || empty($verification_id) || empty($access_token)) {
            wp_die('Missing required data for dropshipper registration.');
        }

        WC_Retailer_Options::delete(WC_Retailer_Options::OAUTH_IN_PROGRESS());

        $redirect_url = admin_url('admin.php?page=' . $config::RETAILER_SLUG . '-setup&step=2&complete_registration=1');
        wp_redirect($redirect_url);
        exit;
    }

    public function handle_complete_registration_ajax() {
        $config = self::config();
        if (!wp_verify_nonce($_POST['nonce'], $config::RETAILER_SLUG . '_setup_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }


        $temp_data = WC_Retailer_Options::get_temp_setup_data();
        $email = $temp_data['email'];
        $store_url = $temp_data['store_url'];
        $verification_id = $temp_data['verification_id'];
        $verification_code = $temp_data['verification_code'];
        $access_token = $temp_data['access_token'];

        if (!WC_Retailer_Options::validate_temp_data()) {
            wp_send_json_error('Missing required data for registration completion');
            return;
        }

        $registration_response = $this->register_dropshipper($email, $store_url, $verification_id, $verification_code, $access_token);

        if (is_wp_error($registration_response)) {
            wp_send_json_error('Registration failed: ' . $registration_response->get_error_message());
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($registration_response), true);
        $response_code = wp_remote_retrieve_response_code($registration_response);
        $raw_body = wp_remote_retrieve_body($registration_response);


        if ($response_code !== WC_Retailer_Constants::HTTP_OK) {
            $debug_info = [
                'endpoint' => $config::SHOPWOO_BASE_URL . 'api/woocommerce/register',
                'response_code' => $response_code,
                'raw_response' => $raw_body,
                'parsed_response' => $body,
                'request_data' => [
                    'email' => $email,
                    'store_url' => $store_url,
                    'verification_id' => $verification_id,
                    'retailer_uuid' => $config::RETAILER_UUID,
                ]
            ];

            $response_data = [
                'message' => $this->format_error_message($response_code, $body, 'Registration')
            ];

            if ($config::DEBUG_MODE) {
                $response_data['debug'] = $debug_info;
            }

            wp_send_json_error($response_data);
            return;
        }

        if (!isset($body['secret_uuid'])) {
            wp_send_json_error('Registration completed but no authentication token received');
            return;
        }

        WC_Retailer_Options::set(WC_Retailer_Options::SECRET_UUID(), $body['secret_uuid']);
        WC_Retailer_Options::set(WC_Retailer_Options::EMAIL(), $email);
        WC_Retailer_Options::set(WC_Retailer_Options::SETUP_COMPLETED(), true);
        WC_Retailer_Options::delete_setup_flow_data();

        wp_send_json_success([
            'message' => 'Setup completed successfully! Your store is now connected to ' . $config::RETAILER_NAME . '.',
            'dashboard_url' => admin_url('admin.php?page=' . $config::RETAILER_SLUG . '-home')
        ]);
    }

    private function register_dropshipper($email, $store_url, $verification_id, $verification_code, $access_token) {
        $config = self::config();
        $current_user = wp_get_current_user();
        $current_user_email = $current_user->user_email;

        $user_name = trim($current_user->display_name);
        if (empty($user_name)) {
            $user_name = $current_user_email;
        }

        return wp_remote_post($config::SHOPWOO_BASE_URL . 'api/woocommerce/register', [
            'timeout' => WC_Retailer_Constants::API_TIMEOUT,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'email' => $email,
                'current_user_email' => $current_user_email,
                'user_name' => $user_name,
                'store_url' => $store_url,
                'verification_id' => $verification_id,
                'verification_code' => $verification_code,
                'access_token' => $access_token,
                'retailer_uuid' => $config::RETAILER_UUID
            ])
        ]);
    }

    private function verify_woocommerce($email, $store_url, $retailer_uuid) {
        $config = self::config();
        return wp_remote_post($config::SHOPWOO_BASE_URL . 'api/woocommerce/verify-store', [
            'timeout' => WC_Retailer_Constants::API_TIMEOUT,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'email' => $email,
                'store_url' => $store_url,
                'retailer_uuid' => $retailer_uuid
            ])
        ]);
    }

    private function verify_email_code($email, $verification_code, $verification_id, $access_token, $retailer_uuid) {
        $config = self::config();

        return wp_remote_post($config::SHOPWOO_BASE_URL . 'api/woocommerce/verify-email', [
            'timeout' => WC_Retailer_Constants::API_TIMEOUT,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'retailer_uuid' => $retailer_uuid,
                'email' => $email,
                'access_token' => $access_token,
                'verification_id' => $verification_id,
                'verification_code' => $verification_code
            ])
        ]);
    }

    /**
     * @param int $response_code
     * @param array $body Decoded JSON response body
     * @param string $context Error context (e.g., "Verification", "Email verification", "Registration")
     * @return string
     */
    private function format_error_message($response_code, $body, $context = 'Request') {
        if ($response_code >= 500) {
            return 'Application server error (' . $response_code . '). Please try again later or contact support.';
        }

        $validation_errors = $this->extract_validation_errors($body);

        if (!empty($validation_errors)) {
            return $context . ' error: ' . implode("\n", $validation_errors);
        }

        if (isset($body['message']) && !empty($body['message'])) {
            return $context . ' error: ' . $body['message'];
        }
        
        return $context . ' error: An error occurred';
    }

    /**
     * @param array $body Decoded JSON response body
     * @return array Flat array of error messages
     */
    private function extract_validation_errors($body) {
        $validation_errors = [];

        if (!isset($body['errors']) || !is_array($body['errors'])) {
            return $validation_errors;
        }

        foreach ($body['errors'] as $field => $messages) {
            if (is_array($messages)) {
                foreach ($messages as $message) {
                    $validation_errors[] = $message;
                }
            } else {
                $validation_errors[] = $messages;
            }
        }

        return $validation_errors;
    }
}
