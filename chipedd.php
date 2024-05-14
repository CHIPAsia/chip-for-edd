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

// based on edd/includes/gateways/chip-payments.php
// since edd 3.2.12
final class EDD_Chip_Payments {
  private static $instance;
  public $gateway_id      = 'chip';
  public static function getInstance() {
    if ( ! isset( self::$instance ) && ! ( self::$instance instanceof EDD_Chip_Payments ) ) {
      self::$instance = new EDD_Chip_Payments;
    }

    return self::$instance;
  }

  private function __construct() {

    // Run this separate so we can ditch as early as possible
    $this->register();

    if ( ! edd_is_gateway_active( $this->gateway_id ) ) {
      return;
    }

    // $this->config();
    // $this->includes();
    // $this->setup_client();
    // $this->filters();
    // $this->actions();
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

  public function register_gateway_section( $gateway_sections ) {
    $gateway_sections['chip'] = __( 'CHIP', 'chip-for-edd' );

    return $gateway_sections;
  }

  public function register_gateway_settings( $gateway_settings ) {
    $default_chip_settings = array(
      'chip_settings'              => array(
        'id'   => 'chip_settings',
        'name' => '<h3>' . __( 'CHIP Settings', 'chip-for-edd' ) . '</h3>',
        'type' => 'header',
        'tooltip_title' => __( 'Connect with PayPal', 'easy-digital-downloads' ),
        'tooltip_desc'  => __( 'Connecting your store with PayPal allows Easy Digital Downloads to automatically configure your store to securely communicate with PayPal.<br \><br \>You may see "Sandhills Development, LLC", mentioned during the process&mdash;that is the company behind Easy Digital Downloads.', 'easy-digital-downloads'),
      ),
    //   'chip_mode'        => array(
    //     'id'    => 'chip_mode',
    //     'name'  => ( __( 'Developer Mode', 'easy-digital-downloads' ) ),
    //     'check' => ( __( 'Only load Stripe.com hosted assets on pages that specifically utilize Stripe functionality.', 'easy-digital-downloads' ) ),
    //     'type'  => 'checkbox_toggle',
    //     'desc'  => sprintf(
    //         /* translators: 1. opening link tag; 2. closing link tag */
    //         __( 'Stripe advises that their Javascript library be loaded on every page to take advantage of their advanced fraud detection rules. If you are not concerned with this, enable this setting to only load the Javascript when necessary. %1$sLearn more about Stripe\'s recommended setup.%2$s', 'easy-digital-downloads' ),
    //         '<a href="https://stripe.com/docs/web/setup" target="_blank" rel="noopener noreferrer">',
    //         '</a>'
    //     ),
    //   ),
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
}

add_action( 'plugins_loaded', 'load_class_chip_edd' );

function load_class_chip_edd() {
  if (function_exists('edd_is_gateway_active')) {
    EDD_Chip_Payments::getInstance();
  }
}