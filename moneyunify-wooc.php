<?php
/*
Plugin Name: MoneyUnify WooCommerce Payment Gateway
Plugin URI: https://github.com/MoneyUnify/MoneyUnify-Woocommerce
Description: Take payments via MoneyUnify on your WooCommerce store.
Version: 0.0.1
Author: Kazzashim Kuzasuwat
Author URI: https://moneyunify.com
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

add_filter('woocommerce_payment_gateways', 'add_moneyunify_gateway_class');
function add_moneyunify_gateway_class($gateways)
{
    $gateways[] = 'WC_MoneyUnify_Gateway';
    return $gateways;
}

add_action('plugins_loaded', 'init_moneyunify_gateway_class');
function init_moneyunify_gateway_class()
{
    class WC_MoneyUnify_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id                 = 'moneyunify';
            $this->icon               = ''; // Icon URL
            $this->has_fields         = true;
            $this->method_title       = __('MoneyUnify', 'moneyunify-pay-woocommerce');
            $this->method_description = __('Take payments via MoneyUnify', 'moneyunify-pay-woocommerce');
            $this->supports           = array(
                'products'
            );

            // Load the settings
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title        = $this->get_option('title');
            $this->description  = $this->get_option('description');
            $this->enabled      = $this->get_option('enabled');
            $this->uuid         = $this->get_option('uuid');

            // Save settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __('Enable/Disable', 'moneyunify-pay-woocommerce'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable MoneyUnify Gateway', 'moneyunify-pay-woocommerce'),
                    'default' => 'no'
                ),
                'title' => array(
                    'title'       => __('Title', 'moneyunify-pay-woocommerce'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'moneyunify-pay-woocommerce'),
                    'default'     => __('MoneyUnify', 'moneyunify-pay-woocommerce'),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __('Description', 'moneyunify-pay-woocommerce'),
                    'type'        => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'moneyunify-pay-woocommerce'),
                    'default'     => __('Pay via MoneyUnify; you can pay with your mobile money account.', 'moneyunify-pay-woocommerce'),
                ),
                'uuid' => array(
                    'title'       => __('MoneyUnify UUID', 'moneyunify-pay-woocommerce'),
                    'type'        => 'text',
                    'description' => __('Enter your MoneyUnify UUID here.', 'moneyunify-pay-woocommerce'),
                    'default'     => '',
                ),
            );
        }

        public function process_payment($order_id)
{
    $order = wc_get_order($order_id);

    // Retrieve customer details from the order
    $phone       = $order->get_billing_phone();
    $amount      = $order->get_total();
    $email       = $order->get_billing_email();
    $first_name  = $order->get_billing_first_name();
    $last_name   = $order->get_billing_last_name();
    $transaction = uniqid(); // Generate a unique transaction ID

    // MoneyUnify API Request - Request Payment
    $response = $this->moneyunify_request_payment($phone, $amount, $email, $first_name, $last_name, $transaction);

    // Check for API errors
    if ($response === false || isset($response['error'])) {
        // Payment initiation failed, handle accordingly
        wc_add_notice('Payment initiation failed. Please try again.', 'error');
        return;
    }

    // Check if the transaction request is successful
    if (isset($response['data']['status']) && $response['data']['status'] === 'TXN_AUTH_SUCCESSFUL') {
        // Save the MoneyUnify reference and phone number in the order for verification
        update_post_meta($order_id, '_moneyunify_reference', $response['data']['reference']);
        update_post_meta($order_id, '_moneyunify_phone_number', $phone);

        // Mark the order as on-hold
        $order->update_status('on-hold', __('Awaiting payment confirmation from MoneyUnify.', 'moneyunify-pay-woocommerce'));

        // Redirect to the order completed page
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        );
    } else {
        // Payment initiation failed, handle accordingly
        wc_add_notice('Payment initiation failed: ' . $response['message'], 'error');
        return;
    }
}

        

        private function moneyunify_request_payment($phone, $amount, $email, $first_name, $last_name, $transaction)
        {
         
            $url = 'https://api.moneyunify.com/moneyunify/request_payment';

           
            $body = array(
                'muid'               => $this->uuid,
                'phone_number'       => $phone,
                'transaction_details'=> 'Order payment',
                'amount'             => $amount,
                'email'              => $email,
                'first_name'         => $first_name,
                'last_name'          => $last_name,
            );

            // Initialize cURL session
            $curl = curl_init();

            // Set cURL options
            curl_setopt_array($curl, array(
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_SSL_VERIFYPEER => false, 
            ));

            // Execute cURL request
            $response = curl_exec($curl);

            // Check for errors
            if ($response === false) {
                // Log cURL error
                error_log('cURL Error: ' . curl_error($curl));
                return false;
            }

            // Decode JSON response
            $result = json_decode($response, true);

            // Close cURL session
            curl_close($curl);

            return $result;
        }

        public function payment_fields()
        {
            echo '<div class="form-row form-row-wide">';
            echo '<label for="moneyunify_phone_number">' . __('Phone Number', 'moneyunify-pay-woocommerce') . ' <span class="required">*</span></label>';
            echo '<input type="text" class="input-text" name="moneyunify_phone_number" id="moneyunify_phone_number" placeholder="' . __('Enter your phone number', 'moneyunify-pay-woocommerce') . '" />';
            echo '</div>';
        }

        public function verify_transaction($reference)
        {
            // MoneyUnify API endpoint for transaction verification
            $url = 'https://api.moneyunify.com/moneyunify/verify_transaction';

            // Prepare request body
            $body = array(
                'muid'      => $this->uuid,
                'reference' => $reference,
            );

            // Initialize cURL session
            $curl = curl_init();

            // Set cURL options
            curl_setopt_array($curl, array(
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_SSL_VERIFYPEER => false, 
            ));

            // Execute cURL request
            $response = curl_exec($curl);

           
            if ($response === false) {
                
                error_log('cURL Error: ' . curl_error($curl));
                return false;
            }

          
            $result = json_decode($response, true);

           
            curl_close($curl);

            return $result;
        }
    }
}
?>
