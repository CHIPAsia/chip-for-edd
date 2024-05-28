<?php

namespace CHIP\EDD;

// This is CHIP API URL Endpoint as per documented in: https://docs.chip-in.asia
define('EDD_CHIP_ROOT_URL', 'https://gate.chip-in.asia/api');

class Chip_EDD_API
{
  public $secret_key;
  public $brand_id;
  public $logger;
  public $debug;
  public $params;
  public $response;
  public $payment;
  public $payment_id;

  public function __construct( $secret_key, $brand_id) {
    $this->secret_key = $secret_key;
    $this->brand_id   = $brand_id;
  }

  public function create_payment( $params ) {
    // $this->log_info( 'creating purchase' );

    return $this->call( 'POST', '/purchases/?time=' . time(), $params );
  }

  private function call( $method, $route, $params = [] ) {
    $secret_key = $this->secret_key;
    if ( !empty( $params ) ) {
      $params = json_encode( $params );
    }

    $response = $this->request(
      $method,
      sprintf( '%s/v1%s', EDD_CHIP_ROOT_URL, $route ),
      $params,
      [
        'Content-type' => 'application/json',
        'Authorization' => "Bearer {$secret_key}",
      ]
    );
    
    $result = json_decode( $response, true );
    
    if ( !$result ) {
      // $this->log_error( 'JSON parsing error/NULL API response' );
      return null;
    }

    if ( !empty( $result['errors'] ) ) {
      // $this->log_error( 'API error', $result['errors'] );
      return null;
    }

    return $result;
  }

  private function request( $method, $url, $params = [], $headers = [] ) {
    // $this->log_info( sprintf(
    //   '%s `%s`\n%s\n%s',
    //   $method,
    //   $url,
    //   var_export( $params, true ),
    //   var_export( $headers, true )
    // ));

    $wp_request = wp_remote_request( $url, array(
      'method'    => $method,
      'sslverify' => !defined( 'WC_CHIP_SSLVERIFY_FALSE' ),
      'headers'   => $headers,
      'body'      => $params,
      'timeout'   => 10, // charge card require longer timeout
    ));

    $response = wp_remote_retrieve_body( $wp_request );

    switch ( $code = wp_remote_retrieve_response_code( $wp_request ) ) {
      case 200:
      case 201:
        break;
      default:
        // $this->log_error(
        //   sprintf( '%s %s: %d', $method, $url, $code ),
        //   $response
        // );
    }

    if ( is_wp_error( $response ) ) {
      // $this->log_error( 'wp_remote_request', $response->get_error_message() );
    }
    
    return $response;
  }
}