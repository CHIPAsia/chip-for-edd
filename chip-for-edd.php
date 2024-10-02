<?php

/**
 * Plugin Name: CHIP for Easy Digital Downloads
 * Plugin URI: https://wordpress.org/plugins/chip-for-easy-digital-downloads/
 * Description: CHIP - Digital Finance Platform
 * Version: 1.1.0
 * Author: Chip In Sdn Bhd
 * Author URI: https://www.chip-in.asia
 * Requires PHP: 7.1
 * Requires at least: 4.7
 * Requires Plugins: easy-digital-downloads
 *
 *
 * Copyright: © 2024 CHIP
 * License: GNU General Public License v3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

class ChipForEdd {
  private static $instance;

  public static function get_instance() {
    if ( ! isset( self::$instance ) && ! ( self::$instance instanceof ChipForEdd ) ) {
      self::$instance = new static;
    }

    return self::$instance;
  }

  public function __construct()
  {
    $this->config();
    $this->includes();
    $this->add_actions();
  }

  // Setup configuration for file paths
  private function config() {
    // Define CHIP Plugin File
    if ( !defined( 'EDD_CHIP_PLUGIN_FILE' ) ) {
      define( 'EDD_CHIP_PLUGIN_FILE', __FILE__ );
    }
  
    if ( !defined( 'EDD_CHIP_CLASS_DIR' ) ) {
      $path = trailingslashit( plugin_dir_path( EDD_CHIP_PLUGIN_FILE ) ) . 'includes';
      define( 'EDD_CHIP_CLASS_DIR', trailingslashit( $path ) );
    }

    if ( !defined( 'EDD_CHIP_BASENAME' ) ) {
      define( 'EDD_CHIP_BASENAME', plugin_basename( EDD_CHIP_PLUGIN_FILE ) );
    }

    if ( !defined( 'EDD_CHIP_MODULE_VERSION' ) ) {
      define( 'EDD_CHIP_MODULE_VERSION', '1.1.0' );
    }
  }

  // Load additional files
  private function includes() {
    require_once EDD_CHIP_CLASS_DIR . 'API.php';
    require_once EDD_CHIP_CLASS_DIR . 'edd_chip_payments.php';
  } 

  private function add_actions() {
    add_action( 'init', array( 'EDD_Chip_Payments', 'edd_chip_listener' ) );
    add_action( 'init', array( 'EDD_Chip_Payments', 'edd_chip_redirect' ) );

    add_action( 'plugins_loaded', array( 'EDD_Chip_Payments', 'load_class_chip_edd' ) ); 
    add_filter( 'plugin_action_links_' . EDD_CHIP_BASENAME, array( $this, 'setting_link' ) );
  }

  public function setting_link( $links ) {

    $url_params = array( 
      'post_type' => 'download',
      'page'      => 'edd-settings', 
      'tab'       => 'gateways',
      'section'   => 'main',
    );

    $url = add_query_arg( $url_params, admin_url( 'edit.php' ) );

    $new_links = array(
      'settings' => sprintf( '<a href="%1$s">%2$s</a>', $url, esc_html__( 'Settings', 'chip-for-edd' ) )
    );

    return array_merge( $new_links, $links );
  }
}

ChipForEdd::get_instance();
