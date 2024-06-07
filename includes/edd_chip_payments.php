<?php

final class EDD_Chip_Payments {
  private static $instance;
  public $gateway_id = 'chip';
  public $client = null;
  public $purchase;
  public $secret_key;
  public $brand_id;
  public $public_key;
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
    // $this->public_key = 

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
    $default_chip_settings = array(
      'chip_settings'              => array(
        'id'   => 'chip_settings',
        'name' => '<h3>' . __( 'CHIP Settings', 'chip-for-edd' ) . '</h3>',
        'type' => 'header',
        'tooltip_title' => __( 'Connect with CHIP', 'easy-digital-downloads' ),
        'tooltip_desc'  => __( 'Connecting your store with CHIP allows Easy Digital Downloads to automatically configure your store to securely communicate with CHIP.<br \><br \>', 'easy-digital-downloads'),
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
    );

    $default_chip_settings = apply_filters( 'edd_default_chip_settings', $default_chip_settings );
    $gateway_settings[ $this->gateway_id ] = $default_chip_settings;

    return $gateway_settings;
  }

  // Load additional files

  // Load actions
  private function actions() {
    add_action( 'edd_gateway_' . $this->gateway_id, array( $this, 'edd_purchase' ) );
    add_action( 'edd_pre_process_purchase', array( $this, 'check_config' ), 1  );
    add_action( 'edd_pre_process_purchase', array( $this, 'disable_address_requirement' ), 99999 );

    add_action( 'edd_' . $this->gateway_id . '_cc_form', '__return_false');
  }

  // Create purchase
  public function edd_purchase( $payment_data ) {

    $profile   = $payment_data['user_info'];
    $full_name = $profile['first_name'] . ' ' . $profile['last_name'];

    // Loop thru $payment_data['cart_details']
    foreach($payment_data['cart_details'] as $index => $product) {
      $payment_data['cart_details'][$index]['price'] = $product['price'] * 100;
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
    $payment_id = edd_insert_payment($payment);

    // Set callback
    // $success_redirect_url = get_permalink( edd_get_option( 'success_page', false ));
    // $success_callback_url = add_query_arg('payment-confirmation', 'chip', get_permalink( edd_get_option( 'success_page', false )));

    // $failure_redirect = edd_send_back_to_checkout( '?payment-mode=chip' );
    // $failure_redirect_url = get_permalink(edd_get_option('failure_page' ));
    
    // Set callback_url based on meta
    $redirect_url = add_query_arg( [ 'payment-redirect' => 'chip', 'identifier' => $payment_id, 'edd-gateway' => $this->gateway_id ], trailingslashit( home_url() ) );
    $callback_url = add_query_arg( [ 'payment-confirmation' => 'chip'], trailingslashit( home_url() ) );

    // Set Params
    $params = [
          'success_callback' => $callback_url,
          'success_redirect' => $redirect_url,
          'failure_redirect' => $redirect_url,
          'cancel_redirect'  => $redirect_url,
          // 'send_receipt'     => $this->purchase_sr == 'yes',
          'creator_agent'    => 'EDD: ' . EDD_CHIP_MODULE_VERSION,
          'reference'        => $payment_id, //EDD()->session->get( 'edd_resume_payment' )
          'platform'         => 'web', // to be modified later
          'purchase' => [
            // 'total_override' => round( $order->get_total() * 100 ),
            'timezone'       => edd_get_timezone_id(),
            'currency'       => edd_get_currency(),
            'products'       => $payment_data['cart_details'], // compulsory
          ],
          'brand_id' => $this->brand_id,
          'client' => [
            'email' => $profile['email'],
            'full_name' => substr( $full_name, 0, 128 ),
          ],
        ];

    $chip = $this->client; // call CHIP API

    $purchase = $chip->create_payment($params); // create payment for purchase

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
    if ( !isset($_SERVER['HTTP_X_SIGNATURE']) ) {
      edd_debug_log('[INFO] No X Signature received from headers');
      return;
      // wp_die('No X Signature received from headers');
    }

    $content = file_get_contents('php://input');

    if (empty($public_key = edd_get_option('chip_public_key'))) {
      $secret_key = edd_get_option('chip_secret_key');
      // $chip_api = new CHIP\EDD\Chip_EDD_API($secret_key, '');
      // $public_key = $chip_api->public_key();
      // $chip = $this->client;
      $public_key = (new EDD_Chip_Payments())->get_public_key();

      edd_debug_log('[INFO] Public key empty, updating new public key');
      edd_update_option('chip_public_key', $public_key);
    }

    $public_key = \str_replace( '\n', "\n", $public_key );

    edd_debug_log('[INFO] Public Key Set: ' . $public_key);

    // Verify the content
    if ( openssl_verify( $content,  base64_decode($_SERVER['HTTP_X_SIGNATURE']), $public_key, 'sha256WithRSAEncryption' ) != 1 ) {
      // header( 'Forbidden', true, 403 );
      edd_debug_log('[INFO] Invalid X Signature');
      edd_debug_log('[INFO] X-Signature: ' . $_SERVER['HTTP_X_SIGNATURE']);
      // wp_die('Invalid X Signature');
      return;
    }

    $decoded_content = json_decode($content, true);

    // Check status for paid
    if ($decoded_content['status'] === 'paid') {

      // Change payment status to paid
      $old_status = 'pending'; // get status of payment
      $new_status = 'complete';
      
      edd_debug_log('[INFO] Updating payment status for Order ID #' . $decoded_content['reference'] . ' from ' . strtoupper($old_status) . ' to ' . strtoupper($new_status));
      edd_update_payment_status($decoded_content['reference'], $new_status, $old_status);
      
      // Send to success page
      edd_debug_log('[INFO] Sending to success page');
      edd_send_to_success_page();
      // edd_redirect( get_permalink( edd_get_option( 'success_page' ) ) );
      
      // Optionally, add a note to the payment
      // do_action('edd_insert_payment_note', $decoded_content['reference'], 'Payment completed via CHIP payment gateway. Transaction ID: ' . $decoded_content['reference']);
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
    // $old_status = 'pending'; // get status of payment

    edd_debug_log('[INFO] Checking and updating order status (function: update_order_status())');

    // Get payment status in DB
    $payment_db = new EDD_Payment($order_id);
    $previous_payment_status = strtolower($payment_db->status);
    
    edd_debug_log('[TEST] Previous payment status: ' . $previous_payment_status);

    // If Payment Status in DB is not Empty
    if (! empty($previous_payment_status)) {

      // Check status for paid
      if ($payment['status'] === 'paid') {
        // Change payment status to paid
        $new_status = 'complete';
        
        edd_debug_log('[INFO] Updating payment status for Order ID #' . $payment['reference'] . ' from ' . strtoupper($previous_payment_status) . ' to ' . strtoupper($new_status));
        edd_update_payment_status($payment['reference'], $new_status, $previous_payment_status);
        
        // Send to success page
        edd_debug_log('[INFO] Sending to success page');
        
        edd_empty_cart();
        edd_redirect(edd_get_receipt_page_uri( $order_id )); 
      } 
      // Check for the status if error
      elseif ($payment['status'] === 'error') {
        // Change payment status to failed
        $new_status = 'failed';
        
        edd_debug_log('[INFO] Updating payment status for Order ID #' . $payment['reference'] . ' from ' . strtoupper($previous_payment_status) . ' to ' . strtoupper($new_status));
        edd_update_payment_status($payment['reference'], $new_status, $previous_payment_status);

        // Redirect to checkout
        // edd_send_back_to_checkout( '?payment-mode=chip' );

        // Redirect to failed page
        edd_debug_log('[INFO] Redirecting to failure page');
        edd_redirect(get_permalink(edd_get_option('failure_page' )));
      }
      elseif ($payment['status'] === 'viewed') {
        edd_debug_log('[INFO] Payment Status in CHIP API: ' . $payment['status']);
        return;
      }
      elseif ($payment['status'] === 'overdue') {
        edd_debug_log('[INFO] Payment Status in CHIP API: ' . $payment['status']);
        return;
      }
    } else {
        edd_debug_log('[INFO] Payment Status in DB is empty');
        return;
    }
  }

  // Get public key
  public function get_public_key() {
    if ( empty( $this->public_key ) ){
      $this->public_key = str_replace( '\n', "\n", $this->client->public_key() );
      // $this->update_option( 'public_key', $this->public_key );
    }

    return $this->public_key;
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

  public static function load_class_chip_edd() {
    if ( function_exists( 'edd_is_gateway_active' ) ) {
      EDD_Chip_Payments::get_instance();
    }
  }
}