<?php

final class EDD_Chip_Payments {
  private static $instance;
  public $gateway_id = 'chip';
  public $client = null;
  public $purchase;
  public $secret_key;
  public $brand_id;
  public $send_receipt;
  public $success_redirect_switch;
  public $success_callback_switch;
  public $public_key;
  public $payment_method_whitelist;
  public $is_setup = null;

  public static function get_instance() {
    if ( ! isset( self::$instance ) && ! ( self::$instance instanceof EDD_Chip_Payments ) ) {
      self::$instance = new static;
    }

    return self::$instance;
  }

  private function __construct() {
    // Initialize all 
    $this->secret_key = edd_get_option('chip_secret_key');
    $this->brand_id = edd_get_option('chip_brand_id');
    $this->public_key = edd_get_option('chip_public_key');
    $this->payment_method_whitelist = edd_get_option('chip_payment_method_whitelist');
    $this->send_receipt = edd_get_option('chip_send_receipt');
    $this->success_redirect_switch = edd_get_option('chip_disable_redirect');
    $this->success_callback_switch = edd_get_option('chip_disable_callback');

    // Run this separate so we can ditch as early as possible
    $this->register();

    // Check if CHIP Gateway Active
    if ( ! edd_is_gateway_active( $this->gateway_id ) ) {
      return;
    }

    $this->setup_client();

    $this->filters(); // run filters
    $this->actions(); // call the purchase API
  }

  private function register() {
    add_filter( 'edd_payment_gateways', array( $this, 'register_gateway' ), 1, 1 );
    add_filter( 'edd_is_gateway_setup_' . $this->gateway_id, array( $this, 'gateway_setup' ) );

    if ( is_admin() ) {
      add_filter( 'edd_settings_sections_gateways', array( $this, 'register_gateway_section' ), 1, 1 );
      add_filter( 'edd_settings_gateways', array( $this, 'register_gateway_settings' ), 1, 1 );
      add_filter( 'edd_payment_details_transaction_id-' . $this->gateway_id, array( $this, 'link_transaction_id' ), 10, 2 );
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
    $gateway_sections[ $this->gateway_id ] = __( 'CHIP', 'chip-for-edd' );

    return $gateway_sections;
  }

  // Add Chip Setting in EDD Payment Setting
  public function register_gateway_settings( $gateway_settings ) {
    // reference: includes/admin/settings/register-settings.php
    $default_chip_settings = array(
      'chip_settings'              => array(
        'id'   => 'chip_settings',
        'name' => '<h3>' . __( 'CHIP Settings', 'chip-for-edd' ) . '</h3>',
        'type' => 'header',
      ),
      'chip_secret_key' => array(
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
      'chip_payment_method_whitelist' => array(
        'id'   => 'chip_payment_method_whitelist',
        'name' => __( 'Payment Method Whitelist', 'chip-for-edd' ),
        'desc' => __( 'Choose payment method to enforce payment method whitelisting', 'chip-for-edd' ),
        'type' => 'multicheck',
        'options' => ['fpx' => 'FPX', 'fpx_b2b1' => 'FPX B2B1', 'mastercard' => 'Mastercard', 'maestro' => 'Maestro', 'visa' => 'Visa', 'razer_atome' => 'Atome', 'razer_grabpay' => 'Grabpay', 'razer_maybankqr' => 'Maybankqr', 'razer_shopeepay' => 'Shopeepay', 'razer_tng' => 'Tng', 'duitnow_qr' => 'Duitnow QR'],
      ),
      'chip_send_receipt' => array(
        'id'   => 'chip_send_receipt',
        'name' => __( 'Send Receipt', 'chip-for-edd' ),
        'desc' => __( 'Toggle on for CHIP to send receipt upon successful payment. If activated, CHIP will send purchase receipt upon payment completion.', 'chip-for-edd' ),
        'type' => 'checkbox_toggle',
      ),
      'chip_troubleshooting' => array(
        'id'   => 'chip_troubleshooting',
        'name' => '<h3>' . __( 'Troubleshooting', 'chip-for-edd' ) . '</h3>',
        'type' => 'header',
        'tooltip_title' => __( 'Troubleshooting', 'chip-for-edd' ),
        'tooltip_desc'  => __( 'This for troubleshooting where all of the option should be turned off', 'chip-for-edd'),
      ),
      'chip_disable_redirect' => array(
        'id'   => 'chip_disable_redirect',
        'name' => __( 'Disable Success Redirect', 'chip-for-edd' ),
        'desc' => __( 'Toggle on for disabling success redirect. This should be disabled on production environment.', 'chip-for-edd' ),
        'type' => 'checkbox_toggle',
      ),
      'chip_disable_callback' => array(
        'id'   => 'chip_disable_callback',
        'name' => __( 'Disable Success Callback', 'chip-for-edd' ),
        'desc' => __( 'Toggle on for disabling success callback. This should be disabled on production environment.', 'chip-for-edd' ),
        'type' => 'checkbox_toggle',
      ),
    );

    $default_chip_settings = apply_filters( 'edd_default_chip_settings', $default_chip_settings );
    $gateway_settings[ $this->gateway_id ] = $default_chip_settings;

    return $gateway_settings;
  }

  // Load actions
  private function actions() {
    add_action( 'edd_gateway_' . $this->gateway_id, array( $this, 'edd_purchase' ) );
    add_action( 'edd_pre_process_purchase', array( $this, 'check_config' ), 1  );
    add_action( 'edd_pre_process_purchase', array( $this, 'disable_address_requirement' ), 99999 );

    add_action( 'edd_' . $this->gateway_id . '_cc_form', '__return_false' );
  }

  // Create purchase
  public function edd_purchase( $payment_data ) {

    $profile   = $payment_data['user_info'];
    $full_name = $profile['first_name'] . ' ' . $profile['last_name'];

    // Loop thru $payment_data['cart_details']
    foreach( $payment_data['cart_details'] as $index => $product ) {
      $payment_data['cart_details'][$index]['price'] = round( $product['item_price'] * 100 );
      $payment_data['cart_details'][$index]['discount'] = round( $product['discount'] * 100 );
    }

    // Setup payment details
    $payment = array(
      'price' => $payment_data['price'],
      'date' => $payment_data['date'],
      'user_email' => $payment_data['user_email'],
      'purchase_key' => $payment_data['purchase_key'],
      'currency' => edd_get_currency(),
      'downloads' => $payment_data['downloads'],
      'cart_details' => $payment_data['cart_details'],
      'user_info' => $payment_data['user_info'],
      'status' => 'pending',
      'gateway' => $this->gateway_id,
    );

    // Record the pending payment (Insert into database) - Generate order ID
    $payment_id = edd_insert_payment( $payment );
    
    // Set callback_url based on meta
    $redirect_url = add_query_arg( [ 'payment-redirect' => 'chip', 'identifier' => $payment_id, 'edd-gateway' => $this->gateway_id ], trailingslashit( home_url() ) );
    $callback_url = add_query_arg( [ 'payment-confirmation' => 'chip'], trailingslashit( home_url() ) );

    // Set Params
    $params = [
      'success_callback' => $callback_url,
      'success_redirect' => $redirect_url,
      'failure_redirect' => $redirect_url,
      'cancel_redirect'  => $redirect_url,
      'send_receipt'     => $this->send_receipt,
      'creator_agent'    => 'EDD: ' . EDD_CHIP_MODULE_VERSION,
      'reference'        => $payment_id, //EDD()->session->get( 'edd_resume_payment' )
      'platform'         => 'EDD',
      'purchase' => [
        'total_override' => round( $payment['price'] * 100 ),
        'timezone'       => edd_get_timezone_id(),
        'currency'       => edd_get_currency(),
        'products'       => $payment_data['cart_details'], // compulsory
      ],
      'brand_id' => $this->brand_id,
      'client' => [
        'email' => $profile['email'],
        'full_name' => substr( $full_name, 0, 128 ),
      ],
      'payment_method_whitelist' => array_keys( $this->payment_method_whitelist ),
    ];

    foreach ( ['razer_atome', 'razer_grabpay', 'razer_tng', 'razer_shopeepay','razer_maybankqr'] as $ewallet ) {
      if ( in_array($ewallet, $params['payment_method_whitelist'] ) ) {
        if ( !in_array( 'razer', $params['payment_method_whitelist'] ) ) {
          $params['payment_method_whitelist'][]= 'razer';
          break;
        }
      }
    }

    $chip = $this->client; // call CHIP API

    if ( $this->success_redirect_switch ) {
      unset( $params['success_redirect'] );
    }

    if ( $this->success_callback_switch ) {
      unset( $params['success_callback'] );
    }

    $params = apply_filters( 'edd_gateway_' . $this->gateway_id . '_purchase_params', $params, $this );

    $purchase = $chip->create_payment( $params ); // create payment for purchase

    if ( !array_key_exists( 'id', $purchase ) ) {
      edd_set_error( 'chip_failed_to_create_purchase', sprintf(__( 'There is an error to create purchase. %s', 'chip-for-edd' ), print_r($purchase,true) ) );
    }

    $errors = edd_get_errors();
    if ( ! empty( $errors ) ) {
    	edd_send_back_to_checkout( '?payment-mode=chip' );
    }

    edd_set_payment_transaction_id( $payment_id, $purchase['id'], $payment_data['price'] );

    edd_debug_log( '[INFO] Adding meta for edd_chip_redirect, Order ID #' . $payment_id . ' with CHIP ID ' . $purchase['id']);

    // Redirect to CHIP checkout_url
    wp_redirect( $purchase['checkout_url'] );
  }

  // Get webhook form CHIP
  public static function edd_chip_listener() {
    // Bail out if edd not exists
    if ( !function_exists( 'edd_is_gateway_active' ) ) {
      return;
    }

    // Check for callback and bail out if parameter not exists
    if (! isset( $_GET['payment-confirmation']) || $_GET['payment-confirmation'] !== 'chip') {
      return;
    }

    edd_debug_log('[INFO] Payment Confirmation (edd_chip_listener) callback started');

    # bail out if X Signature not exists
    if ( !isset( $_SERVER['HTTP_X_SIGNATURE'] ) ) {
      edd_debug_log('[INFO] No X Signature received from headers');
      return;
      // wp_die('No X Signature received from headers');
    }

    $content = file_get_contents( 'php://input' );

    if ( empty( $public_key = ( self::get_instance() )->public_key ) ) {
      $public_key = ( self::get_instance() )->get_public_key();
    }

    edd_debug_log('[INFO] Public Key Set: ' . $public_key);

    // Verify the content
    if ( openssl_verify( $content,  base64_decode($_SERVER['HTTP_X_SIGNATURE']), $public_key, 'sha256WithRSAEncryption' ) != 1 ) {
      // header( 'Forbidden', true, 403 );
      edd_debug_log('[INFO] Invalid X Signature');
      edd_debug_log('[INFO] X-Signature: ' . $_SERVER['HTTP_X_SIGNATURE']);
      // wp_die('Invalid X Signature');
      return;
    }

    $decoded_content = json_decode( $content, true );

    // Check status for paid
    if ( $decoded_content['status'] === 'paid' ) {

      ( self::get_instance() )->get_lock( $decoded_content['reference'] );

      // Change payment status to paid
      $previous_payment_status = edd_get_payment_status( absint( $decoded_content['reference'] ) );
      $new_status = 'complete';

      if ( $previous_payment_status != $new_status ) {
        edd_debug_log('[INFO] Updating payment status for Order ID #' . $decoded_content['reference'] . ' from ' . strtoupper($previous_payment_status) . ' to ' . strtoupper($new_status));
        edd_update_payment_status( absint( $decoded_content['reference'] ), $new_status );
      }

      ( self::get_instance() )->release_lock( $decoded_content['reference'] );
 
	    edd_die( 'Callback processed successfully', 'CHIP', 200 );
    } 
  }

  // Redirect from CHIP payment gateway
  public static function edd_chip_redirect() {
    // Bail out if edd not exists
    if ( !function_exists( 'edd_is_gateway_active' ) ) {
      return;
    }

    // Check for callback and bail out if parameter not exists
    if (! isset( $_GET['payment-redirect']) || $_GET['payment-redirect'] !== 'chip') {
      return;
    }

    edd_debug_log('[INFO] Parameter payment-redirect or payment-redirect=chip requested');

    // Get order_id from URL
    if (preg_match('/^\d+/', $_GET['identifier'], $matches)) {
      $order_id = $matches[0];
      edd_debug_log('[INFO] Order ID retrieved from URL #' . $order_id);
    } else {
      // No Valid order ID is pass
      edd_debug_log('[INFO] Invalid Order ID format in parameter identifier');
      return;
    }

    $chip_id = edd_get_payment_transaction_id( $order_id );

    // If CHIP ID retrieved from meta
    if (! empty($chip_id)) {
      edd_debug_log('[INFO] CHIP ID retrieved from meta #' . $chip_id);
    } else {
      edd_debug_log('[INFO] CHIP ID of Order ID #' . $order_id . ' does not exist in meta');
      return;
    }

    // Get the ID and check for payment status using CHIP API retrieve payment
    $payment = ( self::get_instance() )->client->get_payment( $chip_id );
    edd_debug_log('[INFO] Sending CHIP API GET request for Payment Status');

    edd_debug_log('[INFO] Payment Info from CHIP API: ' . $payment['status']);


    // Change the order status
    ( self::get_instance() )->update_order_status( $payment, $order_id );
  }

  // Change the EDD order status
  public function update_order_status($payment, $order_id) {
    edd_debug_log('[INFO] Checking and updating order status (function: update_order_status())');

    // Get payment status in DB
    // $payment_db = new EDD_Payment($order_id);
    // $previous_payment_status = strtolower($payment_db->status);

    $this->get_lock( $payment['reference'] );

    $previous_payment_status = edd_get_payment_status( absint( $payment['reference'] ) );
    
    edd_debug_log('[TEST] Previous payment status: ' . $previous_payment_status);

    // If Payment Status in DB is not Empty
    if (! empty($previous_payment_status)) {

      // Check status for paid
      if ($payment['status'] === 'paid') {
        // Change payment status to paid
        $new_status = 'complete';
  
        if ( $previous_payment_status != $new_status ) {
          edd_debug_log( '[INFO] Updating payment status for Order ID #' . $payment['reference'] . ' from ' . strtoupper( $previous_payment_status ) . ' to ' . strtoupper( $new_status ) );
          edd_update_payment_status( $payment['reference'], $new_status );
          $this->release_lock( $payment['reference'] );
        }
        
        // Send to success page
        edd_debug_log('[INFO] Sending to success page');
        
        edd_empty_cart();
        edd_redirect(edd_get_receipt_page_uri( $order_id )); 
      } 
      // Check for the status if error
      elseif ($payment['status'] === 'error') {
        // Change payment status to failed
        $new_status = 'failed';
        
        if ( $previous_payment_status != $new_status ) {
          edd_debug_log('[INFO] Updating payment status for Order ID #' . $payment['reference'] . ' from ' . strtoupper($previous_payment_status) . ' to ' . strtoupper($new_status));
          edd_update_payment_status( $payment['reference'], $new_status );
          $this->release_lock( $payment['reference'] );
        }

        // Redirect to checkout
        // edd_send_back_to_checkout( '?payment-mode=chip' );

        // Redirect to failed page
        edd_debug_log('[INFO] Redirecting to failure page');
      }
      elseif ($payment['status'] === 'viewed') {
        edd_debug_log('[INFO] Payment Status in CHIP API: ' . $payment['status']);
      }
      elseif ($payment['status'] === 'overdue') {
        edd_debug_log('[INFO] Payment Status in CHIP API: ' . $payment['status']);
      }
    } else {
        edd_debug_log('[INFO] Payment Status in DB is empty');
    }

    edd_redirect( get_permalink( edd_get_option( 'failure_page' ) ) );
  }

  // Get public key
  public function get_public_key() {
    if ( empty( $public_key = edd_get_option( 'chip_public_key', '' ) ) ) {
      $public_key = str_replace( '\n', "\n", $this->client->public_key() );

      edd_debug_log('[INFO] Public key empty, updating new public key');
      edd_update_option('chip_public_key', $public_key);
    }

    return $public_key;
  }

  public function filters() {
    add_filter( 'edd_settings_gateways-chip_sanitize', array( $this, 'reset_public_key' ) );
    add_filter( 'edd_purchase_form_required_fields', array( $this, 'purchase_form_required_fields' ), 9999 );
  }

  // Reset public_key when user save CHIP Secret key
  public function reset_public_key($input) {
    $input['chip_public_key'] = '';

    return $input;
  }

  public function gateway_setup($is_setup) {
    $chip_settings = array(
      'chip_secret_key',
      'chip_brand_id',
    );

    foreach ( $chip_settings as $key ) {
      if ( empty( edd_get_option( $key, '' ) ) ) {
        $is_setup = false;
        break;
      }
    }

    return $is_setup;
  }

  public function check_config() {
    $is_enabled = edd_is_gateway_active( $this->gateway_id );
    if ( ( ! $is_enabled || false === $this->is_setup() ) && $this->gateway_id == edd_get_chosen_gateway() ) {
      edd_set_error( 'chip_gateway_not_configured', __( 'There is an error with the CHIP configuration.', 'chip-for-edd' ) );
    }
  }

  public function is_setup() {
    if ( ! is_null( $this->is_setup ) ) {
      return $this->is_setup;
    }

    $this->is_setup = edd_is_gateway_setup( $this->gateway_id );

    return $this->is_setup;
  }

  public function setup_client() {
    if ( ! $this->is_setup() ) {
      return;
    }

    $config = array(
      'secret_key' => edd_get_option( 'chip_secret_key', '' ),
      'brand_id'   => edd_get_option( 'chip_brand_id', '' ),
    );
  
    $config = apply_filters( 'edd_chip_client_config', $config );

    $this->client = new CHIP\EDD\Chip_EDD_API( $config['secret_key'], $config['brand_id'] );
  }

  public function disable_address_requirement() {
    if ( ! empty( $_POST['edd-gateway'] ) && $this->gateway_id == $_REQUEST['edd-gateway'] ) {
      add_filter( 'edd_require_billing_address', '__return_false', 9999 );
    }
  }

  public function purchase_form_required_fields() {
    return array(
      'edd_email' => array(
        'error_id' => 'invalid_email',
        'error_message' => __( 'Please enter a valid email address', 'chip-for-edd' )
      ),
    );
  }

  public function link_transaction_id( $transaction_id, $payment_id ) {
    $url = 'https://gate.chip-in.asia/p/' . $transaction_id . '/receipt/';
    $transaction_url = '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $transaction_id ) . '</a>';

    return apply_filters( 'edd_' . $this->gateway_id . '_link_payment_details_transaction_id', $transaction_url );
  }

  public function get_lock( $order_id ) {
    $GLOBALS['wpdb']->get_results( "SELECT GET_LOCK('edd_chip_payment_$order_id', 15);" );
  }

  public function release_lock( $order_id ) {
    $GLOBALS['wpdb']->get_results( "SELECT RELEASE_LOCK('edd_chip_payment_$order_id');" );
  }

  public static function load_class_chip_edd() {
    if ( function_exists( 'edd_is_gateway_active' ) ) {
      EDD_Chip_Payments::get_instance();
    }
  }
}