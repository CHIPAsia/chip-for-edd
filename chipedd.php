<?php

/**
 * Plugin Name: CHIP for Easy Digital Downloads
 * Plugin URI: https://wordpress.org/plugins/<plugin name>
 * Description: CHIP - Digital Finance Platform
 * Author: Chip In Sdn. Bhd.
 * Author URI: http://github.com/
 * Version: 1.0.0
 * License: GPL-3.0-or-later
 * Requires PHP: 5.6
 */

//error_reporting(E_ALL);
//ini_set('display_errors', 'On');
//define('BEDD_DISABLE_DELETE', true);

// Main Reference: https://pippinsplugins.com/create-custom-payment-gateway-for-easy-digital-downloads/

// Filter hook that maybe use for this case
// edd_payment_gateways

// Check if gateway active
// $gateway_id = 'chip';

// if (! edd_is_gateway_active( $this->gateway_id)) {
//     return;
// }

// Add Setting Page for CHIP in Setting > Payment
// Inspired from Stripe EDD
function chip_settings_section( $sections ) {
	$sections['edd-chip'] = __( 'CHIP', 'easy-digital-downloads' );

	return $sections;
}
add_filter( 'edd_settings_sections_gateways', 'chip_settings_section' );

function chip_add_settings( $settings) {

    $chip_settings = array(
        array(
            'id'	=>	'chip_gateway_settings',
            'name'	=>	'<h3>' . __('CHIP Payment Gateway', 'po_paystack') . '</h3>',
            'desc'	=>	'<p>' . __('Paystack.com enables you to securely take payments using local and foreign Mastercard, VISA and Verve cards', 'po_paystack') . '</p>',
            'type'	=>	'header'
        ),
    );

    $settings['edd-chip'] = $chip_settings;
    return $settings;

}
add_filter( 'edd_settings_gateways', 'chip_add_settings' );

// Register payment gateway

