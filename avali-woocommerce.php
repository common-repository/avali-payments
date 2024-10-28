<?php
/*
Plugin Name: Avali Payments Woocommerce
Plugin URI: https://wordpress.org/plugins/avali-payments
Description: Woocommerce payment gateway to support Avali Payments
Version: 1.4
Author: Avali Payments Ltd
Author URI: www.avali.nz
@class		WC_Gateway_Avali
@extends	WC_Payment_Gateway
*/

// Include our Gateway Class and register Payment Gateway with WooCommerce
add_action( 'plugins_loaded', 'avali_wc_payment_init', 0 );



// Hook in
// add_filter( 'woocommerce_checkout_fields' , 'custom_override_checkout_fields' );

// // Our hooked in function - $fields is passed via the filter!
// function custom_override_checkout_fields( $fields ) {
//      $fields['order']['order_comments']['placeholder'] = 'My new placeholder';
//      return $fields;
// }

function avali_wc_payment_init() {
	// If the parent WC_Payment_Gateway class doesn't exist
	// it means WooCommerce is not installed on the site
	// so do nothing
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;
	
	// If we made it this far, then include our Gateway Class
	include_once( 'avali-woocommerce-gateway.php' );

	// Now that we have successfully included our class,
	// Lets add it too WooCommerce
	add_filter( 'woocommerce_payment_gateways', 'avali_add_gateway' );
	function avali_add_gateway( $methods ) {
		$methods[] = 'Avali';
		return $methods;
	}
}

// Add custom action links
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'avali_wc_payment_action_links' );
function avali_wc_payment_action_links( $links ) {
	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout' ) . '">' . __( 'Settings', 'avali_wc_payment' ) . '</a>',
	);

	// Merge our new link with the default ones
	return array_merge( $plugin_links, $links );	
}


?>
