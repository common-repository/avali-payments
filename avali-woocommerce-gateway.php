<?php

/* Avali Payment Gateway Class v1.4 11 April 2017 */
class Avali extends WC_Payment_Gateway {
	
	//const AVALI_ROOT_URL = 'http://192.168.99.100:5000';
	const AVALI_ROOT_URL = 'https://api.avalipayments.nz';
	const AVALI_STAGING_URL = 'https://sandbox.avalipayments.nz';		
	const AVALI_CALLBACK_HANDLER_NAME = 'avali_payment_callback';
	const AVALI_WORDPRESS_VERSION = '1.4';

	// Setup our Gateway's id, description and other values
	function __construct() {

		// The global ID for this Payment method
		$this->id = "avali_wc_payment";

		// The Title shown on the top of the Payment Gateways Page next to all the other Payment Gateways
		$this->method_title = __( "Avali", 'avali-wc-payment' );

		// The description for this Payment Gateway, shown on the actual Payment options page on the backend
		$this->method_description = __( "Avali Payments Gateway Plug-in for WooCommerce", 'avali-wc-payment' );

		// The title to be used for the vertical tabs that can be ordered top to bottom
		$this->title = __( "Avali", 'avali-wc-payment' );

		// If you want to show an image next to the gateway's name on the frontend, enter a URL to an image.
		// - commented out because we override get_icon public function below
		//$this->icon = 'http://res.cloudinary.com/avali/image/upload/c_scale,w_103,f_auto/Avali_logo_Horizontal_RGB_vcotmq';

		// Bool. Can be set to true if you want payment fields to show on the checkout 
		// if doing a direct integration, which we are doing in this case
		$this->has_fields = true;

		// This basically defines your settings which are then loaded with init_settings()
		$this->init_form_fields();

		// After init_settings() is called, you can get the settings and load them into variables, e.g:
		// $this->title = $this->get_option( 'title' );
		$this->init_settings();
		
		// Turn these settings into variables we can use
		foreach ( $this->settings as $setting_key => $value ) {
			$this->$setting_key = $value;
		}

		/** Initial receipt page
		*/

		add_action('woocommerce_receipt_' .$this->id, array( $this, 'receipt_page'));
		
		/** Update the custom Avali order meta with field value
		 */
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'avali_field_update_order_meta', 10, 2 ));

		/**
		* Show the custom Avali order meta on order admin
		*/
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this,'avali_display_order_data_in_admin') );

		/**
		* Call back URl which is called once Avali iframe notifies of payment
		*/
		add_action( 'woocommerce_api_avali_payment_callback', array( $this, 'avali_callback_handler') );
		
		// Lets check for SSL
		//add_action( 'admin_notices', array( $this,	'do_ssl_check' ) );
		
		// Save settings
		if ( is_admin() ) {
			// Versions over 2.0
			// Save our administration options. Since we are not going to be doing anything special
			// we have not defined 'process_admin_options' in this class so the method in the parent
			// class will be used instead
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}		
	} // End __construct()

    /**
     * Get gateway icon.
     * @return string
     */
    public function get_icon() {
    	$testing = ( $this->environment == "yes" ) ? true : false;
        $icon_html = '<img src="' . esc_attr( 'http://res.cloudinary.com/avali/image/upload/c_scale,w_103,f_auto/Avali_logo_Horizontal_RGB_vcotmq' ) . '" alt="' . esc_attr__( 'Avali Logo', 'woocommerce' ) . '" />'.($testing ? '<span style="margin-left:10px; font-weight: bold; color: #62269e">[TEST MODE]</span>' : '');
        return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );                                                                                                }


	function avali_field_update_order_meta( $order_id) {
		if ($_POST['_avali_payment_code']) {
			update_post_meta( $order_id, '_avali_payment_code', esc_attr($_POST['_avali_payment_code']));
		}
		if ($_POST['_avali_payment_tid']) {
			update_post_meta( $order_id, '_avali_payment_tid', esc_attr($_POST['_avali_payment_tid']));
		}
		if ($_POST['_avali_payment_secure_key']) {
			update_post_meta( $order_id, '_avali_payment_secure_key', esc_attr($_POST['_avali_payment_secure_key']));
		}
		if ($_POST['_avali_payment_url']) {
			update_post_meta( $order_id, '_avali_payment_url', esc_attr($_POST['_avali_payment_url']));
		}
		if ($_POST['_avali_transaction_url']) {
			update_post_meta( $order_id, '_avali_transaction_url', esc_attr($_POST['_avali_transaction_url']));
		}
	}

	// display the extra data in the order admin panel
	function avali_display_order_data_in_admin ($order ){

		if (get_post_meta( $order->id, '_avali_payment_tid', true ) <> "") {
			echo '<br/><br/><div style="word-break: break-all;border: solid 1px #ccc;clear: both;padding: 0px 1em;position: relative;top: 2px;"><p><label><strong>' . __('Avali') . ' Payment Details:</strong></label><br> '
			.'TID: '. get_post_meta($order->id, '_avali_payment_tid', true) . '<br/>'
			// .'URL: '. get_post_meta($order->id, '_avali_payment_url', true) . '<br/>'
			// .'Secure Key: '. get_post_meta($order->id, '_avali_payment_secure_key', true) . '<br/>'
			.'Reference Code: '. get_post_meta($order->id, '_avali_payment_code', true) . '<br/>'
			.'<a class="button button-primary btn btn-primary" target="_avalitransaction" href="'.get_post_meta($order->id, '_avali_transaction_url', true).'" title="check Avali payment status">Check Avali payment status</a><br/>'
			.'</p></div>';
		}
	}

	// This is called from iframe off back of close
	function avali_callback_handler() {

		global $woocommerce;

		$parts = parse_url($_SERVER['REQUEST_URI']);
		parse_str($parts['query'], $query);
		$order = wc_get_order( $query['orderid'] );
		$avaliAction = $query['avaliAction'];

		if ($avaliAction == "paid" || $avaliAction == "continue") {

			// Are we testing right now or is it a real transaction
			$testing = ( $this->environment == "yes" ) ? true : false;

			// Decide which URL to post to
			$environment_url = ( $testing) 
							   ? self::AVALI_STAGING_URL
							   : self::AVALI_ROOT_URL;

			// Avali validate transaction status
			// example URL
			// https://api.avalipayments.nz/v1/secure/transaction/status/IIY5356CXSEWWP
			$paymentstatus_url = $environment_url ."/v1/secure/transaction/status/" .get_post_meta( $order->id, '_avali_payment_tid', true );

			// Send this payload to Avali for processing
			$response = wp_remote_get( $paymentstatus_url, array(
				'method'    => 'GET',
				//'body'      => http_build_query( $payload ),
				'timeout'   => 90,
				'sslverify' => false,
			) );

			// Retrieve the body's response
			$response_body = wp_remote_retrieve_body( $response );
			$json = json_decode( $response_body );

			$r['payment_status']= $json->payment_status;
			$r['secure_key']= $json->secure_key;

			//if there was an error with the response from Avali
			if ($json == null) {
				$this -> msg['message'] = "Avali Payments error checking final payment status, please contact Avali customer support, http://www.avali.nz/contact/.";
				$this -> msg['class'] = 'woocommerce_message woocommerce_message_error';
				$order->add_order_note( __( $this->msg['message'], 'avali-wc-payment' ) );
				
				// mark order on hold
				$order->update_status('on-hold');
				// empty cart
				$woocommerce->cart->empty_cart();
				return;
			}
			

			// check the secure key we have stored with the order against what Avali returned
			$secure_key = get_post_meta( $order->id, '_avali_payment_secure_key', true );

			if (strcmp($r['secure_key'],$secure_key) == 0) {
				// if we get success back, then finalise order
				if ($r['payment_status'] == 1) { // fully paid

					$this -> msg['message'] = "Avali Payments payment received.";
					$this -> msg['class'] = 'woocommerce_message woocommerce_message_info';
					$order->add_order_note( __( $this->msg['message'], 'avali-wc-payment' ) );
					
					// mark order complete
					$order->payment_complete();
					// empty cart
					$woocommerce->cart->empty_cart();

					//redirect to thank you / order-received page
					wp_redirect($this->get_return_url( $order ));
					
				} else if ($r['payment_status'] == 0) { // partially paid, we should never get here unless hacked as Avali iframe handles this, possibly remove
					$this -> msg['message'] = "Avali Payments partial payment received.";
					$this -> msg['class'] = 'woocommerce_message woocommerce_message_error';
					$order->add_order_note( __( $this->msg['message'], 'avali-wc-payment' ) );
					
					// mark order on hold
					$order->update_status('on-hold');
					// empty cart
					$woocommerce->cart->empty_cart();

					// redirect to receipt  / order-pay page
					wp_redirect($order->get_checkout_payment_url(true));

				} else if ($r['payment_status'] == -1) { // not paid
					$this -> msg['message'] = "Avali Payments payment not received.";
					$this -> msg['class'] = 'woocommerce_message woocommerce_message_error';
					$order->add_order_note( __( $this->msg['message'], 'avali-wc-payment' ) );
					
					// mark order on hold
					$order->update_status('on-hold');
					// empty cart
					$woocommerce->cart->empty_cart();

				}
			} else {
				$this -> msg['message'] = "Avali payments secure key did not match. Avali secure key is: ".$r['secure_key'];
				$this -> msg['class'] = 'woocommerce_message woocommerce_message_error';
				$order->add_order_note( __( $this->msg['message'], 'avali-wc-payment' ) );
				
				// mark order on hold
				$order->update_status('on-hold');
				// empty cart
				$woocommerce->cart->empty_cart();
			}
		}
		
	}

	// Avali iFrame where the magic happens
	function generate_avali_iframe($order_id){
		global $woocommerce;
    	$checkout_url = $woocommerce->cart->get_checkout_url();
		$order = wc_get_order( $order_id );

		// Are we testing right now or is it a real transaction
		$testing = ( $this->environment == "yes" ) ? true : false;

		$trustOrigin = ( $testing )
                       ? self::AVALI_STAGING_URL
                       : self::AVALI_ROOT_URL;
	    return '<script>
			
			var trustOrigin = "'.$trustOrigin.'";
			
			var avaliLoaded = false;

			function initialiseAvaliCommunication() {
				var popup = window.frames["avali-checkout"];
				var openIframeMessage = JSON.stringify({"target" :"iframe", "action" :"open"});
				var setMerchantOriginMessage = JSON.stringify({"target" :"iframe", "action" :"setOrigin"});
				console.log("Merchant window posting to Avali");
				popup.postMessage(setMerchantOriginMessage, trustOrigin);

				var checkAvaliloaded = setInterval(function() {
					if (avaliLoaded) {
						popup.postMessage(openIframeMessage, trustOrigin);
						clearInterval(checkAvaliloaded);
					}
				},100);
				
			}

			function receiveAvaliMessage(event) {
			  // Do we trust the sender of this message?
			  if (event.origin !== trustOrigin)
			    return;

			  	console.log("Message received on merchant site:" + event.data);

			  	// we dont know the base url for the site, if it has subdirs etc, so we depend on the current
			  	var baseUrl = document.location.href.split("/checkout");
			  	if (baseUrl.length <= 1) {
			  		baseUrl[0] = window.location.origin;
			  	}

				var jsonMessage = JSON.parse(event.data);
				if (jsonMessage.action === "paid") {
					// redirect after 40 secs
					setTimeout(function() {
						window.location = baseUrl[0] + "/wc-api/'.self::AVALI_CALLBACK_HANDLER_NAME.'?orderid='.$order->id.'&avaliAction=paid";
			    	},40000);
				} else if (jsonMessage.action === "continue") {
					window.location = baseUrl[0] + "/wc-api/'.self::AVALI_CALLBACK_HANDLER_NAME.'?orderid='.$order->id.'&avaliAction=continue";
				} else if (jsonMessage.action === "cancel") {
					window.location = "'.$checkout_url.'";
				} else if (jsonMessage.action === "loaded") {
					avaliLoaded = true;
				}
			}
			
	    	( function ( $ ) {
				"use strict";

				window.addEventListener("message", receiveAvaliMessage, false);

				$(document).ready(function(){
					$(".avali-checkout").show();
				});	

			}( jQuery ));
		</script>

	    <iframe frameborder="0" 
    	allowtransparency="true" 
    	src="'.get_post_meta( $order->id, '_avali_payment_url', true ).'" 
    	name="avali-checkout" 
    	class="avali-checkout" 
    	style="z-index: 2147483647; display: none; border: 0px none transparent; overflow-x: hidden; overflow-y: auto; visibility: visible; margin: 0px; padding: 0px; -webkit-tap-highlight-color: transparent; position: fixed; left: 0px; top: 0px; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.00392157);"
    	onload="initialiseAvaliCommunication()"
    	></iframe>';
	}

	// receipt page returns genreated iframe
	public function receipt_page($order_id){
		echo $this->generate_avali_iframe($order_id);
	}

	// Build the administration fields for this specific Gateway
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'		=> __( 'Enable / Disable', 'avali-wc-payment' ),
				'label'		=> __( 'Enable this payment gateway', 'avali-wc-payment' ),
				'type'		=> 'checkbox',
				'default'	=> 'no',
			),
			'title' => array(
				'title'		=> __( 'Title', 'avali-wc-payment' ),
				'type'		=> 'text',
				'desc_tip'	=> __( 'Payment title the customer will see during the checkout process.', 'avali-wc-payment' ),
				'default'	=> __( 'Avali', 'avali-wc-payment' ),
			),
			'description' => array(
				'title'		=> __( 'Description', 'avali-wc-payment' ),
				'type'		=> 'textarea',
				'desc_tip'	=> __( 'Payment description the customer will see during the checkout process.', 'avali-wc-payment' ),
				'default'	=> __( 'Pay securely using your bank', 'avali-wc-payment' ),
				'css'		=> 'max-width:350px;'
			),
			'merchant_key' => array(
				'title'		=> __( 'Avali Merchant Key', 'avali-wc-payment' ),
				'type'		=> 'text',
				'desc_tip'	=> __( 'This is the Merchant Key provided by Avali when you signed up for an account.', 'avali-wc-payment' ),
			),
			'environment' => array(
				'title'		=> __( 'Avali Test Mode', 'avali-wc-payment' ),
				'label'		=> __( 'Enable Test Mode', 'avali-wc-payment' ),
				'type'		=> 'checkbox',
				'description' => __( 'Place the payment gateway in test mode.', 'avali-wc-payment' ),
				'default'	=> 'no',
			)
		);		
	}

	// Custom Avali fields
	public function payment_fields() {

        if ( $description = $this->get_description() ) {
            echo wpautop( wptexturize( $description ) );
        }

       ?>

       <script>
jQuery('input.mutuallyExclusive').click(function () { 
                 checkedState = jQuery(this).attr('checked');
              
                  jQuery(this).closest('p').find('input.mutuallyExclusive:checked').each(function () {
                      jQuery(this).attr('checked', false);
                  });
                  jQuery(this).attr('checked', checkedState);
});
	    </script>
        <div class="form-horizontal" role="form" id="avali-form">
		<p>Avali is approved by leading banks as the safe, secure way to pay instantly online - direct from your bank account. There's no need to register or share your internet banking details.</p>
		<p>Find out more at <a href="http://www.avali.nz/" target="_blank">www.avali.nz</a></p>
    		<p>Currently Avali supports payments from BNZ and Westpac banks only. Please confirm which bank you would like to make the payment from.</p>
    		<!-- UNCOMMENT BELOW TO ADD VALIDATION TO POST -->
    		<p class="form-row form-row validate-required">
	    		<label class="" for="avali-bank-bnz" style="float: left; margin-right: 30px;">
	    			<input class="validate-required mutuallyExclusive" type="checkbox" title="BNZ bank" name="avali-bank-bnz" id="avali-bank-bnz" />Pay with BNZ
	    		</label>

	    		<label class="" for="avali-bank-westpac">
	    			<input class="validate-required mutuallyExclusive" type="checkbox" title="Westpac bank" name="avali-bank-westpac" id="avali-bank-westpac" />Pay with Westpac
	    		</label>
    		</p>
    	</div>
    	<?
    }

	// Submit payment and handle response
	public function process_payment( $order_id) {
		global $woocommerce;

		// return array(
		// 		'result'   => 'success',
		// 		'redirect' => 'http://www.google.com',
		// 		'refresh' => false,
		// 		'reload' => false
		// 	);

		$is_new_order = true;


		// Are we testing right now or is it a real transaction
		$testing = ( $this->environment == "yes" ) ? true : false;

		// Get order, either create new one or fetch existing one which is pending
		$customer_order = new WC_Order( $order_id );

		// if order has avali payment tid field, then it was partially completed, but never received
		// payment status update, most probably because window was shut before it received a payment status update
		// or user had returned to url via browser history or browser restart
		// therefore just redirect to 'order-pay' page and let iframe hanlde current status
		if (get_post_meta( $customer_order->id, '_avali_payment_tid', true ) <> "") {
			$is_new_order = false;
			return array(
				'result'   => 'success',
				'redirect' => $customer_order->get_checkout_payment_url(true),
				'refresh' => true,
				'reload' => false
			);
		} else {
			// Decide which URL to post to
			$environment_url = ( $testing ) 
							   ? self::AVALI_STAGING_URL
							   : self::AVALI_ROOT_URL;

			$selected_bank = null;

			if (!empty($_POST['avali-bank-bnz'])) {
				$selected_bank = "02";
			} else if (!empty($_POST['avali-bank-westpac'])) {
				$selected_bank = "03";
			}

			// Capture form info
			$payload = array(

				// Avali Credentials and API Info, key order info
				"seed"        	=> $customer_order->billing_first_name,
				"amount"       	=> $customer_order->order_total,
				"bank"        	=> $selected_bank,
				"callback"	=> self::getCallbackUrl().'?orderid='.$customer_order->id.'&avaliAction=paid',
				"customer_ip"  	=> $_SERVER['REMOTE_ADDR']
				
			);

			// Avali create transaction
			// example URL
			// https://api.avalipayments.nz/v1/secure/transaction/create/IIY5356CXSEWWP
			$createpayment_url = $environment_url ."/v1/secure/transaction/create/" .$this->merchant_key;

			// Send this payload to Avali for processing
			$response = wp_remote_post( $createpayment_url, array(
				'method'    => 'POST',
				'body'      => http_build_query( $payload ),
				'timeout'   => 90,
				'sslverify' => false,
			) );


			// var_dump($response);
			// die();

			if ( is_wp_error( $response ) ) {
				throw new Exception( __( 'We are currently experiencing problems connecting to Avali Payments services. Please try another payment method for now or try again shortly. You can also go to www.avali.nz to find out how to contact Avali for support there.', 'avali-wc-payment' ) );
			}

			if ( empty( $response['body'] ) ) {
				throw new Exception( __( 'Avali\'s Response was empty.', 'avali-wc-payment' ) );
			}
			
			$response_body = wp_remote_retrieve_body( $response );
			
			// if we receive 400 from Avali, i.e. over limit, throw error
			if ($response['response']['code'] == 400) {
				$errorMessage = json_decode( $response_body, true); // true converts to array instead of object, allowing us to get message	
				throw new Exception( __( $errorMessage['message'], 'avali-wc-payment' ) );
			}		

			// Retrieve the body's resopnse if no errors found
			$json = json_decode( $response_body);				 

			/* Example Avali create transaction response
			"code": "myname43", 
	  		"tid": 2347687373, 
			"secure_key": "c0e74c98a6c0dgsd7sdgugsug", 
			"url": "https://api.avalipayments.nz/v1/embeddedgateway/2347687373"
			 */

			// Get the values we need
			$r['code']			= $json->code;
			$r['tid']			= $json->tid;
			$r['secure_key']	= $json->secure_key;
			$r['url']			= $json->url;

			// Update the POST object, this is the only way that I could find to make the response variables
			// accessible from post order functions
			update_post_meta( $order_id, '_avali_payment_code', $r['code']);
			update_post_meta( $order_id, '_avali_payment_tid', $r['tid']);
			update_post_meta( $order_id, '_avali_payment_secure_key', $r['secure_key']);
			update_post_meta( $order_id, '_avali_payment_url', $r['url']);
			update_post_meta( $order_id, '_avali_transaction_url', $environment_url ."/v1/transaction/" .$r['tid'] );

			// Test the code to know if the transaction went through or not.
			// if we get a TID back, then successful
			if ( ( $r['tid'] <> "" ) ) {
				// Payment is pending
				
				$this -> msg['message'] = "Avali payment requested. TID: ".$r['tid'].". Waiting payment from bank.";
				$this -> msg['class'] = 'woocommerce_message woocommerce_message_info';
				$customer_order->add_order_note( __( $this->msg['message'], 'avali-wc-payment' ) );
				$customer_order -> update_status('pending');

				// Redirect after order is created in woocommerce
				return array(
					'result'   => 'success',
					//'redirect' => $this->get_return_url( $customer_order ),
					'redirect' => $customer_order->get_checkout_payment_url(true),
					'refresh' => true,
					'reload' => false
				);

			} else {
				// Transaction was not succesful
				// Add notice to the cart
				wc_add_notice( $r['response_reason_text'], 'error' );
				if ($json == null) {
					$customer_order->add_order_note( 'Error:  Avali issue, please contact Avali merchant support.');
				} else {
					$customer_order->add_order_note( 'Error: '. $r['response_reason_text'] );	
				}
			}
		}
	}

	// Validate Avali fields
	public function validate_fields() {

		$validate_message = 'Please select below the bank you\'ll be making the payment from.';

		if ( empty($_POST['avali-bank-bnz']) and empty($_POST['avali-bank-westpac'])) {
			if ( function_exists ( 'wc_add_notice' ) ) {
				// Replace deprecated $woocommerce_add_error() function.
				wc_add_notice ( __ ( $validate_message, 'avali_wc_payment' ), 'error' );
			} else {
				WC()->add_error( __( $validate_message, 'avali_wc_payment' ) );
			}
			return false;
		} else {
			return true;
		}
	}

	// Get base URL
	public function getBaseUrl() {
		return $current_base_url = ($_SERVER['HTTPS'] == null ? 'http://' : 'https://').$_SERVER['HTTP_HOST'];
	}

	// Get callback URL
	public function getCallbackUrl() {
		return self::getBaseUrl().'/wc-api/'.self::AVALI_CALLBACK_HANDLER_NAME;
	}
	
	// Check if we are forcing SSL on checkout pages
	// Custom function not required by the Gateway
	public function do_ssl_check() {
		if( $this->enabled == "yes" ) {
			if( get_option( 'woocommerce_force_ssl_checkout' ) == "no" ) {
				echo "<div class=\"error\"><p>". sprintf( __( "<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>" ), $this->method_title, admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) ."</p></div>";	
			}
		}		
	}
	

} // End of Avali

?>
