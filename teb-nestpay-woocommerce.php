<?php
/**
 * Plugin Name: TEB NestPay (3D Pay Hosting) for WooCommerce
 * Description: Accept card payments through the TEB Kosovo NestPay gateway using the 3D Pay Hosting model and Hash version 3 (SHA-512). Redirects the customer to the bank's secure page, so card data never touches your server.
 * Version:     1.0.0
 * Author:      GENTIAN NUKA
 * License:     GPL-2.0-or-later
 * Requires PHP: 7.2
 * WC requires at least: 4.0
 * Text Domain: teb-nestpay
 *
 * Unofficial, community-built WooCommerce gateway for the TEB Kosova NestPay
 * platform. Not affiliated with, endorsed by, or supported by TEB Kosova or
 * Asseco. "TEB" and "NestPay" are trademarks of their respective owners.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

// Register the gateway with WooCommerce.
add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
	$gateways[] = 'WC_Gateway_TEB_NestPay';
	return $gateways;
} );

// Declare HPOS (High-Performance Order Storage) compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
} );

// Define the gateway only after WooCommerce has loaded its base class.
add_action( 'plugins_loaded', function () {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return; // WooCommerce not active.
	}

	class WC_Gateway_TEB_NestPay extends WC_Payment_Gateway {

		public function __construct() {
			$this->id                 = 'teb_nestpay';
			$this->method_title       = 'TEB NestPay (3D Pay Hosting)';
			$this->method_description = 'Card payments via TEB Kosovo NestPay. The customer is redirected to the bank\'s 3D Secure hosted page (Hash v3 / SHA-512).';
			$this->has_fields         = false;

			$this->init_form_fields();
			$this->init_settings();

			$this->title       = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			$this->enabled     = $this->get_option( 'enabled' );

			// Save admin settings.
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			// Output the auto-submitting form on the WooCommerce "pay" page.
			add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );

			// Handle the bank's callback (okUrl / failUrl point here).
			add_action( 'woocommerce_api_' . $this->id, array( $this, 'handle_callback' ) );
		}

		/**
		 * Admin settings fields.
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title'   => 'Enable/Disable',
					'type'    => 'checkbox',
					'label'   => 'Enable TEB NestPay',
					'default' => 'no',
				),
				'title' => array(
					'title'       => 'Title',
					'type'        => 'text',
					'description' => 'Payment method name shown to the customer at checkout.',
					'default'     => 'Credit / Debit Card',
					'desc_tip'    => true,
				),
				'description' => array(
					'title'   => 'Description',
					'type'    => 'textarea',
					'default' => 'Pay securely with your card. You will be redirected to the bank\'s 3D Secure page.',
				),
				'testmode' => array(
					'title'       => 'Test mode',
					'type'        => 'checkbox',
					'label'       => 'Enable test mode (uses the Test gateway URL)',
					'default'     => 'yes',
					'description' => 'When enabled, the "Test gateway URL" is used instead of the live one.',
				),
				'client_id' => array(
					'title'       => 'Client ID (Merchant ID)',
					'type'        => 'text',
					'description' => 'The merchant / client ID assigned to you by TEB.',
					'desc_tip'    => true,
				),
				'store_key' => array(
					'title'       => 'Store Key',
					'type'        => 'password',
					'description' => 'The store key assigned by TEB. Used to compute the SHA-512 hash. Keep it secret.',
					'desc_tip'    => true,
				),
				'store_type' => array(
					'title'       => 'Store type',
					'type'        => 'text',
					'default'     => '3D_PAY_HOSTING',
					'description' => 'As given by TEB / the v3 sample: 3D_PAY_HOSTING.',
					'desc_tip'    => true,
				),
				'transaction_type' => array(
					'title'   => 'Transaction type',
					'type'    => 'select',
					'default' => 'Auth',
					'options' => array(
						'Auth'    => 'Auth (charge immediately)',
						'PreAuth' => 'PreAuth (authorize, capture later)',
					),
				),
				'currency_code' => array(
					'title'       => 'Currency code (ISO 4217 numeric)',
					'type'        => 'select',
					'default'     => '978',
					'description' => 'Kosovo uses EUR (978). Must match the currency your merchant account is configured for.',
					'desc_tip'    => true,
					'options'     => array(
						'978' => 'EUR (978)',
						'840' => 'USD (840)',
						'826' => 'GBP (826)',
						'949' => 'TRY (949)',
						'008' => 'ALL - Albanian Lek (008)',
					),
				),
				'lang' => array(
					'title'   => 'Language',
					'type'    => 'select',
					'default' => 'en',
					'options' => array(
						'en' => 'English',
						'tr' => 'Turkish',
						'sq' => 'Albanian',
					),
				),
				'gateway_url_test' => array(
					'title'       => 'Test gateway URL',
					'type'        => 'text',
					'default'     => '',
					'description' => 'The 3D gate (est3Dgate) URL for the test environment. TEB provides this.',
					'desc_tip'    => true,
				),
				'gateway_url_live' => array(
					'title'       => 'Live gateway URL',
					'type'        => 'text',
					'default'     => '',
					'description' => 'The production 3D gate URL. TEB must give you this (e.g. https://<host>/servlet/est3Dgate).',
					'desc_tip'    => true,
				),
			);
		}

		/**
		 * Currently selected gateway endpoint.
		 */
		protected function get_gateway_url() {
			return 'yes' === $this->get_option( 'testmode' )
				? trim( $this->get_option( 'gateway_url_test' ) )
				: trim( $this->get_option( 'gateway_url_live' ) );
		}

		/**
		 * Step 1: send the customer to the "pay" page which auto-posts to the bank.
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( true ),
			);
		}

		/**
		 * Step 2: render the hidden, auto-submitting form on the pay page.
		 */
		public function receipt_page( $order_id ) {
			$order = wc_get_order( $order_id );

			$callback_url = WC()->api_request_url( $this->id );

			// Unique order id for the bank. Append the attempt time so retries stay unique.
			$oid = $order->get_id() . '-' . time();
			$order->update_meta_data( '_teb_nestpay_oid', $oid );
			$order->save();

			// Standard NestPay ver3 request field set.
			// Note: `oid` is optional (the gateway auto-generates one if omitted),
			// but it is a supported NestPay field, and we send it
			// so the bank's response can be mapped back to this WooCommerce order.
			$fields = array(
				'clientid'      => $this->get_option( 'client_id' ),
				'storetype'     => $this->get_option( 'store_type' ),
				'hashAlgorithm' => 'ver3',
				'oid'           => $oid,
				'amount'        => number_format( (float) $order->get_total(), 2, '.', '' ),
				'currency'      => $this->get_option( 'currency_code' ),
				'okUrl'         => $callback_url,
				'failUrl'       => $callback_url,
				'shopurl'       => $callback_url, // Cancel/return URL (TEB guide mandatory field).
				'callbackUrl'   => $callback_url,
				'TranType'      => $this->get_option( 'transaction_type' ),
				'Instalment'    => '',
				'rnd'           => bin2hex( random_bytes( 16 ) ),
				'lang'          => $this->get_option( 'lang' ),
				'refreshtime'   => '5',
				'encoding'      => 'utf-8', // Excluded from the hash by spec; ensures correct char handling.
				'BillToName'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'BillToCompany' => $order->get_billing_company(),
			);

			// Compute the Hash v3 (SHA-512) signature over all fields.
			$fields['hash'] = $this->calculate_hash_v3( $fields, $this->get_option( 'store_key' ) );

			$gateway_url = esc_url( $this->get_gateway_url() );

			echo '<p>' . esc_html__( 'Redirecting you to the secure payment page…', 'teb-nestpay' ) . '</p>';
			echo '<form action="' . $gateway_url . '" method="post" id="teb_nestpay_form" accept-charset="UTF-8">';
			foreach ( $fields as $name => $value ) {
				printf(
					'<input type="hidden" name="%s" value="%s" />',
					esc_attr( $name ),
					esc_attr( $value )
				);
			}
			echo '<input type="submit" value="' . esc_attr__( 'Pay now', 'teb-nestpay' ) . '" />';
			echo '</form>';
			echo '<script>document.getElementById("teb_nestpay_form").submit();</script>';
		}

		/**
		 * Step 3: the bank redirects the customer's browser (POST) back here.
		 */
		public function handle_callback() {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- external gateway POST, verified by HASH.
			$post = wp_unslash( $_POST );
			// phpcs:enable

			if ( empty( $post ) ) {
				wp_die( 'Empty response from payment gateway.', 'TEB NestPay', array( 'response' => 400 ) );
			}

			$order = $this->find_order_from_response( $post );
			if ( ! $order ) {
				wp_die( 'Order not found for payment response.', 'TEB NestPay', array( 'response' => 404 ) );
			}

			// 3a: verify the signature the bank sent us.
			if ( ! $this->verify_response_hash( $post, $this->get_option( 'store_key' ) ) ) {
				$order->update_status( 'failed', 'TEB NestPay: response hash mismatch (possible tampering).' );
				$this->redirect_to( $order->get_checkout_payment_url(), 'Payment could not be verified. Please try again.' );
			}

			$md_status  = isset( $post['mdStatus'] ) ? $post['mdStatus'] : '';
			$response   = isset( $post['Response'] ) ? $post['Response'] : '';
			$proc_code  = isset( $post['ProcReturnCode'] ) ? $post['ProcReturnCode'] : '';
			$auth_code  = isset( $post['AuthCode'] ) ? $post['AuthCode'] : '';
			$trans_id   = isset( $post['TransId'] ) ? $post['TransId'] : '';
			$err_msg    = isset( $post['ErrMsg'] ) ? $post['ErrMsg'] : '';

			// 3b: 3D authentication result. 1-4 are successful, everything else is not.
			$auth_ok = in_array( (string) $md_status, array( '1', '2', '3', '4' ), true );

			// 3c: financial approval.
			$payment_ok = $auth_ok
				&& ( strcasecmp( $response, 'Approved' ) === 0 || $proc_code === '00' );

			// Store gateway references on the order regardless of outcome.
			$order->update_meta_data( '_teb_nestpay_transid', $trans_id );
			$order->update_meta_data( '_teb_nestpay_authcode', $auth_code );
			$order->update_meta_data( '_teb_nestpay_procreturncode', $proc_code );
			$order->update_meta_data( '_teb_nestpay_mdstatus', $md_status );
			$order->save();

			if ( $payment_ok ) {
				if ( ! $order->is_paid() ) {
					$order->payment_complete( $trans_id );
					$order->add_order_note( sprintf(
						'TEB NestPay payment approved. TransId: %s, AuthCode: %s, mdStatus: %s.',
						$trans_id,
						$auth_code,
						$md_status
					) );
				}
				WC()->cart->empty_cart();
				$this->redirect_to( $this->get_return_url( $order ) );
			}

			// Failure path.
			$reason = $err_msg ? $err_msg : ( 'mdStatus=' . $md_status . ', ProcReturnCode=' . $proc_code );
			$order->update_status( 'failed', 'TEB NestPay declined. ' . $reason );
			$this->redirect_to(
				$order->get_checkout_payment_url(),
				'Your payment was not approved. ' . ( $err_msg ? esc_html( $err_msg ) : '' )
			);
		}

		/**
		 * Match the returned "oid" back to a WooCommerce order.
		 */
		protected function find_order_from_response( array $post ) {
			if ( empty( $post['oid'] ) ) {
				return false;
			}
			$oid = $post['oid'];

			// oid is "<order_id>-<timestamp>"; the order id is the leading segment.
			$order_id = (int) strtok( $oid, '-' );
			$order    = $order_id ? wc_get_order( $order_id ) : false;

			// Confirm the stored oid matches, to be safe.
			if ( $order && $order->get_meta( '_teb_nestpay_oid' ) === $oid ) {
				return $order;
			}
			return $order ?: false;
		}

		protected function redirect_to( $url, $notice = '' ) {
			if ( $notice && function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $notice, 'error' );
			}
			wp_safe_redirect( $url );
			exit;
		}

		/* -----------------------------------------------------------------
		 * Hash version 3 (SHA-512) — a re-implementation of the standard
		 * NestPay ver3 request/response hashing scheme.
		 * ----------------------------------------------------------------- */

		/**
		 * Build the request hash: case-insensitive sort of parameter NAMES,
		 * escaped values joined by "|", store key appended, SHA-512, base64.
		 */
		protected function calculate_hash_v3( array $params, $store_key ) {
			$keys = array_keys( $params );
			natcasesort( $keys );

			$hashval = '';
			foreach ( $keys as $param ) {
				$lower = strtolower( $param );
				if ( 'hash' === $lower || 'encoding' === $lower ) {
					continue;
				}
				$hashval .= $this->escape_hash_value( (string) $params[ $param ] ) . '|';
			}
			$hashval .= $this->escape_hash_value( (string) $store_key );

			return base64_encode( pack( 'H*', hash( 'sha512', $hashval ) ) );
		}

		/**
		 * Verify the response: recompute over ALL posted params (except HASH
		 * and encoding) using the values the bank sent, and compare.
		 */
		protected function verify_response_hash( array $post, $store_key ) {
			if ( empty( $post['HASH'] ) ) {
				return false;
			}
			$retrieved = $post['HASH'];

			$keys = array_keys( $post );
			natcasesort( $keys );

			$hashval = '';
			foreach ( $keys as $param ) {
				$lower = strtolower( $param );
				if ( 'hash' === $lower || 'encoding' === $lower ) {
					continue;
				}
				$hashval .= $this->escape_hash_value( (string) $post[ $param ] ) . '|';
			}
			$hashval .= $this->escape_hash_value( (string) $store_key );

			$calculated = base64_encode( pack( 'H*', hash( 'sha512', $hashval ) ) );

			return hash_equals( $calculated, $retrieved );
		}

		/**
		 * Escape "\" then "|" as required by the NestPay ver3 scheme:
		 * str_replace("|", "\|", str_replace("\", "\\", $value)).
		 */
		protected function escape_hash_value( $value ) {
			return str_replace( '|', '\\|', str_replace( '\\', '\\\\', $value ) );
		}
	}
} );
