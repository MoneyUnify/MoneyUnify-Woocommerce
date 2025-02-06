<?php
/*
Plugin Name: MoneyUnify WooCommerce Payment Gateway
Plugin URI: https://github.com/MoneyUnify/MoneyUnify-Woocommerce
Description: Take payments via MoneyUnify on your WooCommerce store.
Version: 0.0.1
Author: Kazasim Kuzasuwat
Author URI: https://moneyunify.com
Requires at least: 6.4.3
Requires PHP: 7.0
Requires WooCommerce: 5.0
*/


if (!defined('ABSPATH')) {
    exit;
}

// Ensure WooCommerce is active
function moneyunify_check_woocommerce() {
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('WooCommerce must be installed and active to use this plugin.');
    }
}
register_activation_hook(__FILE__, 'moneyunify_check_woocommerce');

// Load the Payment Gateway
add_action('plugins_loaded', 'init_moneyunify_gateway');

function init_moneyunify_gateway() {
    class WC_Gateway_MoneyUnify extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'moneyunify';
            $this->icon = '';
            $this->has_fields = true; // Enable input fields
            $this->method_title = 'MoneyUnify Mobile Money';
            $this->method_description = 'Accept payments via MoneyUnify Mobile Money API.';

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->muid = $this->get_option('muid');
            $this->api_key = $this->get_option('api_key');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable MoneyUnify Payment Gateway',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'Payment title shown at checkout.',
                    'default' => 'Mobile Money Payment',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'Payment description shown at checkout.',
                    'default' => 'Pay using MoneyUnify Mobile Money.',
                ),
                'muid' => array(
                    'title' => 'MoneyUnify ID (MUID)',
                    'type' => 'text',
                    'description' => 'Your MoneyUnify ID from the dashboard.',
                    'default' => '',
                ),
                'api_key' => array(
                    'title' => 'API Key',
                    'type' => 'password',
                    'description' => 'Your MoneyUnify API Key.',
                    'default' => '',
                ),
            );
        }

        public function payment_fields() {
            echo '<p>Enter your Mobile Money phone number to complete the payment.</p>';
            echo '<label for="moneyunify_phone">Phone Number (Mobile Money):</label>';
            echo '<input type="text" name="moneyunify_phone" id="moneyunify_phone" required placeholder="097XXXXXXXX" style="width: 100%; padding: 10px; margin-top: 5px;" />';
        }

        public function validate_fields() {
            if (empty($_POST['moneyunify_phone']) || !preg_match('/^\d{10}$/', $_POST['moneyunify_phone'])) {
                wc_add_notice('Please enter a valid 10-digit mobile money phone number.', 'error');
                return false;
            }
            return true;
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $phone_number = sanitize_text_field($_POST['moneyunify_phone']);
            $amount = intval($order->get_total()); // Convert amount to integer
            $muid = $this->muid;

            $response = $this->send_payment_request($muid, $phone_number, $amount);

            if ($response && $response['isError'] === false) {
                $reference = $response['data']['reference'];
                $order->update_meta_data('_moneyunify_phone', $phone_number);
                $order->update_meta_data('_moneyunify_reference', $reference);
                $order->save();

                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order),
                );
            } else {
                wc_add_notice('Payment request failed: ' . ($response['message'] ?? 'Unknown error'), 'error');
                return array(
                    'result' => 'failure',
                    'redirect' => '',
                );
            }
        }

        private function send_payment_request($muid, $phone_number, $amount) {
            $url = 'https://api.moneyunify.com/v2/request_payment';
            $args = array(
                'body' => http_build_query(array(
                    'muid' => $muid,
                    'phone_number' => $phone_number,
                    'amount' => $amount,
                )),
                'method' => 'POST',
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ),
            );

            $response = wp_remote_post($url, $args);

            if (is_wp_error($response)) {
                return array('isError' => true, 'message' => 'API request failed');
            }

            return json_decode(wp_remote_retrieve_body($response), true);
        }
    }
}

// Register the Payment Gateway
add_filter('woocommerce_payment_gateways', 'add_moneyunify_gateway');

function add_moneyunify_gateway($gateways) {
    $gateways[] = 'WC_Gateway_MoneyUnify';
    return $gateways;
}

// Verify Payment on Order Processing
add_action('woocommerce_order_status_processing', 'moneyunify_verify_payment');

function moneyunify_verify_payment($order_id) {
    $order = wc_get_order($order_id);
    $muid = get_option('moneyunify_muid');
    $reference = $order->get_meta('_moneyunify_reference');

    if ($reference) {
        $status = moneyunify_check_transaction_status($muid, $reference);

        if ($status === 'successful') {
            $order->payment_complete();
            $order->reduce_order_stock();
            WC()->cart->empty_cart();
        } else {
            $order->update_status('failed', __('Payment verification failed or declined.', 'woocommerce'));
        }
    }
}

function moneyunify_check_transaction_status($muid, $reference) {
    $url = 'https://api.moneyunify.com/v2/verify_transaction';
    $args = array(
        'body' => http_build_query(array(
            'muid' => $muid,
            'reference' => $reference,
        )),
        'method' => 'POST',
        'headers' => array(
            'Content-Type' => 'application/x-www-form-urlencoded',
        ),
    );

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        return 'failed';
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    return $body['data']['status'] ?? 'failed';
}
