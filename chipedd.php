<?php

/**
 * Plugin Name: CHIP for Easy Digital Downloads
 * Plugin URI: https://wordpress.org/plugins/chip-for-easy-digital-downloads/
 * Description: CHIP - Digital Finance Platform
 * Version: 1.0.0
 * Author: Chip In Sdn Bhd
 * Author URI: https://www.chip-in.asia
 * Requires PHP: 7.1
 * Requires at least: 4.7
 *
 *
 * Copyright: © 2024 CHIP
 * License: GNU General Public License v3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

use CHIP\EDD\Chip_EDD_API;

require 'includes/API.php';

final class EDD_Chip_Payments {
  private static $instance;
  public $gateway_id = 'chip';
  public $purchase;

  public $cached_api;
  public $secret_key;
  public $brand_id;
  public $public_key;


  public static function getInstance() {
    if ( ! isset( self::$instance ) && ! ( self::$instance instanceof EDD_Chip_Payments ) ) {
      self::$instance = new EDD_Chip_Payments;
    }

    return self::$instance;
  }

  private function __construct() {
    // Initialize all 
    $this->secret_key = edd_get_option('chip_secret_key');
    $this->brand_id = edd_get_option('chip_brand_id');
    // $this->public_key = 

    // Run this separate so we can ditch as early as possible
    $this->register();

    // Check if CHIP Gateway Active
    if ( ! edd_is_gateway_active( $this->gateway_id ) ) {
      return;
    }

    // Check WP_DEBUG on
    // if( WP_DEBUG === true ) { 
    //   // enabled 
    //   echo '<h1><center>WP_DEBUG enabled</center></h1>';
    // } else {
    //     // not enabled 
    //     echo '<h1><center>WP_DEBUG not enabled</center></h1>';
    // }
    
    $this->config();
    $this->includes();
    // $this->setup_client();
    $this->filters(); // run filters
    $this->actions(); // call the purchase API
  }

  private function register() {
    add_filter( 'edd_payment_gateways', array( $this, 'register_gateway' ), 1, 1 );
    if ( is_admin() ) {
      add_filter( 'edd_settings_sections_gateways', array( $this, 'register_gateway_section' ), 1, 1 );
      add_filter( 'edd_settings_gateways', array( $this, 'register_gateway_settings' ), 1, 1 );
      // add_action( 'admin_notices', array( $this, 'chip_payments_notice' ) );
      // add_filter( 'edd_payment_details_transaction_id-' . $this->gateway_id, array( $this, 'link_transaction_id' ), 10, 2 );
    }
  }

  // Register gateway to EDD general 
  public function register_gateway( $gateways ) {

    $default_chip_info = array(
      $this->gateway_id => array(
        'admin_label'    => __( 'CHIP', 'chip-for-edd' ),
        'checkout_label' => __( 'CHIP', 'chip-for-edd' ),
        'supports'       => array(),
        'icons'          => array( 'chip' ),
      ),
    );

    $default_chip_info = apply_filters( 'edd_register_chip_gateway', $default_chip_info );
    $gateways            = array_merge( $gateways, $default_chip_info );

    return $gateways;
  }

  // Register Gateway section in EDD
  public function register_gateway_section( $gateway_sections ) {
    $gateway_sections['chip'] = __( 'CHIP', 'chip-for-edd' );

    return $gateway_sections;
  }

  // Add Chip Setting in EDD Payment Setting
  public function register_gateway_settings( $gateway_settings ) {
    $default_chip_settings = array(
      'chip_settings'              => array(
        'id'   => 'chip_settings',
        'name' => '<h3>' . __( 'CHIP Settings', 'chip-for-edd' ) . '</h3>',
        'type' => 'header',
        'tooltip_title' => __( 'Connect with CHIP', 'easy-digital-downloads' ),
        'tooltip_desc'  => __( 'Connecting your store with CHIP allows Easy Digital Downloads to automatically configure your store to securely communicate with CHIP.<br \><br \>', 'easy-digital-downloads'),
      ),
      'chip_seller_id' => array(
        'id'   => 'chip_secret_key',
        'name' => __( 'Secret Key', 'chip-for-edd' ),
        'desc' => __( 'Secret key can be obtained from CHIP Collect Dashboard >> Developers >> Keys', 'chip-for-edd' ),
        'type' => 'text',
        'size' => 'regular',
      ),
      'chip_brand_id' => array(
        'id'   => 'chip_brand_id',
        'name' => __( 'Brand ID', 'chip-for-edd' ),
        'desc' => __( 'Brand ID can be obtained from CHIP Collect Dashboard >> Developers >> Brands', 'chip-for-edd' ),
        'type' => 'text',
        'size' => 'regular',
      ),
    );

    $default_chip_settings    = apply_filters( 'edd_default_chip_settings', $default_chip_settings );
    $gateway_settings['chip'] = $default_chip_settings;

    return $gateway_settings;
  }

  // Setup configuration for file paths
  private function config() {
    // Enabling Debug
    // define( 'WP_DEBUG', true ); // delete before commit
    // define( 'WP_DEBUG_LOG', true ); // delete before commit - use docker

    // Define CHIP Plugin File
    if (! defined('CHIP_PLUGIN_FILE')) {
      define('CHIP_PLUGIN_FILE', __FILE__);
    }

    if (! defined('EDD_CHIP_CLASS_DIR')) {
        $path = trailingslashit(plugin_dir_path(CHIP_PLUGIN_FILE)) . 'includes';
        define('EDD_CHIP_CLASS_DIR', trailingslashit($path));
    }
  }

  // Load additional files
  private function includes() {
    require_once EDD_CHIP_CLASS_DIR . 'API.php';
  } 

  // Load actions
  private function actions() {
    add_action('edd_gateway_' . $this->gateway_id, array($this, 'edd_purchase'));
  }

  // Create purchase
  public function edd_purchase() {
    // Get edd purchase data
    $purchase_data = EDD()->session->get( 'edd_purchase' );
    $profile   = EDD()->session->get( 'edd_purchase' )['user_info'];

    // Setup payment details
    $payment = array(
      'price' => $purchase_data['price'],
      'date' => $purchase_data['date'],
      'user_email' => $purchase_data['user_email'],
      'purchase_key' => $purchase_data['purchase_key'],
      'currency' => edd_get_option( 'currency' ),
      'downloads' => $purchase_data['downloads'],
      'cart_details' => $purchase_data['cart_details'],
      'user_info' => $purchase_data['user_info'],
      'status' => 'pending'
    );

    // Record the pending payment (Insert into database) - Generate order ID
    $payment_id = edd_insert_payment($payment);

    // Set and Get Transaction ID
    $transaction_id = $payment_id;

    edd_set_payment_transaction_id( $payment_id, $transaction_id );

    // Checking
    if ( ! is_user_logged_in() ) { // check if customer not login
      // echo 'hello';
      $user = get_user_by( 'email', $profile['email'] );

      // $user = get_user_by( 'email', 'awis@chip-in.asia' );
      // echo '<pre>'; print_r($user); echo '</pre>';

      // if ( $user ) {
      // 	edd_log_user_in( $user->ID, $user->user_login, '' );

      // 	$customer = array(
      // 		'first_name' => $user->first_name,
      // 		'last_name'  => $user->last_name,
      // 		'email'      => $user->user_email
      // 	);

      // } else {
      // 	$names = explode( ' ', $profile['name'], 2 );

      // 	$customer = array(
      // 		'first_name' => $names[0],
      // 		'last_name'  => isset( $names[1] ) ? $names[1] : '',
      // 		'email'      => $profile['email']
      // 	);

      // 	// Create a customer account if registration is not disabled
      // 	if ( 'none' !== edd_get_option( 'show_register_form' ) ) {
      // 		$args  = array(
      // 			'user_email'   => $profile['email'],
      // 			'user_login'   => $profile['email'],
      // 			'display_name' => $profile['name'],
      // 			'first_name'   => $customer['first_name'],
      // 			'last_name'    => $customer['last_name'],
      // 			'user_pass'    => wp_generate_password( 20 ),
      // 		);

      // 		$user_id = wp_insert_user( $args );

      // 		edd_log_user_in( $user_id, $args['user_login'], $args['user_pass'] );
      // 	}
      // }

      // EDD()->session->set( 'customer', $customer );
    }

    // Set callback
    // $success_redirect_url = get_permalink( edd_get_option( 'success_page', false ));
    // $success_callback_url = add_query_arg('payment-confirmation', 'chip', get_permalink( edd_get_option( 'success_page', false )));

    // Set Params
    $params = [
          'success_callback' => 'https://webhook.site/a7f5ac22-709a-4413-93d8-f2b1d4b00320',
          // 'success_callback' => $success_callback_url, // $callback_url
          // 'success_redirect' => $success_redirect_url, // $callback_url
          // 'failure_redirect' => $callback_url,
          // 'cancel_redirect'  => $callback_url,
          // 'force_recurring'  => $this->force_token == 'yes',
          // 'send_receipt'     => $this->purchase_sr == 'yes',
          // 'creator_agent'    => 'WooCommerce: ' . WC_CHIP_MODULE_VERSION,
          // 'reference'        => $payment_id, //EDD()->session->get( 'edd_resume_payment' )
          // 'platform'         => 'woocommerce',
          // 'due'              => $this->get_due_timestamp(),
          'purchase' => [
            // 'total_override' => round( $order->get_total() * 100 ),
            // 'due_strict'     => $this->due_strict == 'yes',
            // 'timezone'       => $this->purchase_tz,
            // 'currency'       => $order->get_currency(),
            // 'language'       => $this->get_language(),
            'products'       => $purchase_data['cart_details'], // compulsory
          ],
          'brand_id' => $this->brand_id, // compulsory
          'client' => [
            'email'                   => $profile['email'],
            // 'phone'                   => substr( $order->get_billing_phone(), 0, 32 ), // compulsory
            // 'full_name'               => $this->filter_customer_full_name( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            // 'street_address'          => substr( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(), 0, 128 ) ,
            // 'country'                 => substr( $order->get_billing_country(), 0, 2 ),
            // 'city'                    => substr( $order->get_billing_city(), 0, 128 ) ,
            // 'zip_code'                => substr( $order->get_billing_postcode(), 0, 32 ),
            // 'state'                   => substr( $order->get_billing_state(), 0, 128 ),
            // 'shipping_street_address' => substr( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2(), 0, 128 ) ,
            // 'shipping_country'        => substr( $order->get_shipping_country(), 0, 2 ),
            // 'shipping_city'           => substr( $order->get_shipping_city(), 0, 128 ),
            // 'shipping_zip_code'       => substr( $order->get_shipping_postcode(), 0, 32 ),
            // 'shipping_state'          => substr( $order->get_shipping_state(), 0, 128 ),
          ],
        ];

    $chip = $this->api(); // call CHIP API
 
    $purchase = $chip->create_payment($params); // create payment for purchase

    // Redirect to CHIP checkout_url
    wp_redirect($purchase['checkout_url']);
  }

  // Get webhook form CHIP
  public static function edd_chip_listener() {
    // Check for callback and bail out if parameter not exists
    if (! isset( $_GET['payment-confirmation']) || $_GET['payment-confirmation'] !== 'chip') {
      return;
    }

    # bail out if X Signature not exists
    if ( !isset($_SERVER['HTTP_X_SIGNATURE']) ) {
      wp_die('No X Signature received from headers');
    }

    $content = file_get_contents('php://input');

    if (empty($public_key = edd_get_option('chip_public_key'))) {
      $secret_key = edd_get_option('chip_secret_key');
      $chip_api = new Chip_EDD_API($secret_key, '');
      $public_key = $chip_api->public_key();
      edd_update_option('chip_public_key', $public_key);
    }

    $public_key = \str_replace( '\n', "\n", $public_key );

    // Verify the content
    if ( openssl_verify( $content,  base64_decode($_SERVER['HTTP_X_SIGNATURE']), $public_key, 'sha256WithRSAEncryption' ) != 1 ) {
      // header( 'Forbidden', true, 403 );
      wp_die('Invalid X Signature');
    }

    $decoded_content = json_decode($content, true);

    // Check status for paid
    if ($decoded_content['status'] === 'paid') {

      // Change payment status to paid
      $old_status = 'pending'; // get status of payment
      $new_status = 'publish';
      
      edd_update_payment_status($decoded_content['reference'], $new_status, $old_status);

      // Redirect to product page?
      
      // Optionally, add a note to the payment
      // do_action('edd_insert_payment_note', $decoded_content['reference'], 'Payment completed via CHIP payment gateway. Transaction ID: ' . $decoded_content['reference']);
    }
  }

  // Cached API
  public function api() {
    if ( !$this->cached_api ) {
      $this->cached_api = new Chip_EDD_API(
        $this->secret_key,
        $this->brand_id
      );
    }

    return $this->cached_api;
  }

  public function get_public_key() {
    if ( empty( $this->public_key ) ){
      $this->public_key = str_replace( '\n', "\n", $this->api()->public_key() );
      // $this->update_option( 'public_key', $this->public_key );
    }

    return $this->public_key;
  }

  public function filters() {
    add_filter('edd_settings_gateways-chip_sanitize', array($this, 'reset_public_key'));
  }

  // Reset public_key when user save CHIP Secret key
  public function reset_public_key($input) {
    $input['chip_public_key'] = '';

    return $input;
  }
}

add_action('init', array( 'EDD_Chip_Payments', 'edd_chip_listener'));
add_action( 'plugins_loaded', 'load_class_chip_edd' ); 

function load_class_chip_edd() {
  if (function_exists('edd_is_gateway_active')) {
    EDD_Chip_Payments::getInstance();
  }
}

// For logging purpose
// function edd_chip_log( $message ) {
//     if ( WP_DEBUG === true ) {
//         if ( is_array( $message ) || is_object( $message ) ) {
//             error_log( print_r( $message, true ) );
//         } else {  
//             error_log( $message );
//         }
//     }
// }


