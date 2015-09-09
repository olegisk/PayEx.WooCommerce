<?php
/*
Plugin Name: WooCommerce PayEx Payments Gateway
Plugin URI: http://payex.com/
Description: Provides a Credit Card Payment Gateway through PayEx for WooCommerce.
Version: 2.0.0pre2
Author: AAIT Team
Author URI: http://aait.se/
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Requires at least: 3.1
*/

require 'plugin-update-checker/plugin-update-checker.php';
$myUpdateChecker = PucFactory::buildUpdateChecker(
	'http://payex.aait.se/application/meta/check?key=vFoib9ZAZGWmyC205pAidnc',
	__FILE__
);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WC_Payex_Payment {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Actions
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 0 );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'woocommerce_order_status_on-hold_to_processing', array( $this, 'capture_payment' ) );
		add_action( 'woocommerce_order_status_on-hold_to_completed', array( $this, 'capture_payment' ) );
		add_action( 'woocommerce_order_status_on-hold_to_cancelled', array( $this, 'cancel_payment' ) );

		// Payment fee
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_cart_fee' ) );
	}

	/**
	 * Add relevant links to plugins page
	 *
	 * @param  array $links
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_payex_payment' ) . '">' . __( 'PayEx Settings', 'woocommerce-gateway-payex-payment' ) . '</a>'
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Init localisations and files
	 */
	public function init() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		// Localization
		load_plugin_textdomain( 'woocommerce-gateway-payex-payment', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Includes
		include_once( dirname( __FILE__ ) . '/library/Px/Px.php' );
		include_once( dirname( __FILE__ ) . '/includes/wc-compatibility-functions.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-abstract.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-payment.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-bankdebit.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-invoice.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-factoring.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-wywallet.php' );
	}

	/**
	 * Register the gateways for use
	 */
	public function register_gateway( $methods ) {
		$methods[] = 'WC_Gateway_Payex_Payment';
		$methods[] = 'WC_Gateway_Payex_Bankdebit';
		$methods[] = 'WC_Gateway_Payex_Invoice';
		$methods[] = 'WC_Gateway_Payex_Factoring';
		$methods[] = 'WC_Gateway_Payex_Wywallet';

		return $methods;
	}

	/**
	 * Add fee when selected payment method
	 */
	public function add_cart_fee() {
		global $woocommerce;

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// Get Current Payment Method
		$available_gateways = $woocommerce->payment_gateways->get_available_payment_gateways();
		if ( isset( $woocommerce->session->chosen_payment_method ) && isset( $available_gateways[ $woocommerce->session->chosen_payment_method ] ) ) {
			$current_gateway = $available_gateways[ $woocommerce->session->chosen_payment_method ];
		} elseif ( isset( $available_gateways[ get_option( 'woocommerce_default_gateway' ) ] ) ) {
			$current_gateway = $available_gateways[ get_option( 'woocommerce_default_gateway' ) ];
		} else {
			$current_gateway = current( $available_gateways );
		}

		// Fee feature in Invoice and Factoring modules
		if ( ! in_array( $current_gateway->id, array( 'payex_invoice', 'payex_factoring' ) ) ) {
			return;
		}

		// Is Fee is not specified
		if ( abs( $current_gateway->fee ) < 0.01 ) {
			return;
		}

		// Add Fee
		$fee_title = $current_gateway->id === 'payex_invoice' ? __( 'Invoice Fee', 'woocommerce-gateway-payex-payment' ) : __( 'Factoring Fee', 'woocommerce-gateway-payex-payment' );
		$woocommerce->cart->add_fee( $fee_title, $current_gateway->fee, ( $current_gateway->fee_is_taxable === 'yes' ), $current_gateway->fee_tax_class );
	}

	/**
	 * Capture payment when the order is changed from on-hold to complete or processing
	 *
	 * @param  int $order_id
	 */
	public function capture_payment( $order_id ) {
		$order              = wc_get_order( $order_id );
		$transaction_status = get_post_meta( $order_id, '_payex_transaction_status', true );
		if ( empty( $transaction_status ) ) {
			return;
		}

		// Get Payment Gateway
		$gateway  = false;
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		foreach ( $gateways as $id => $tmp ) {
			if ( $id === $order->payment_method ) {
				$gateway = $tmp;
				break;
			}
		}

		if ( $gateway && (string) $transaction_status === '3' ) {
			// Get Additional Values
			$additionalValues = '';
			if ( $gateway->id === 'payex_factoring' ) {
				$additionalValues = 'INVOICESALE_ORDERLINES=' . urlencode( $gateway->getInvoiceExtraPrintBlocksXML( $order ) );
			}

			// Call PxOrder.Capture5
			$params = array(
				'accountNumber'     => '',
				'transactionNumber' => $order->get_transaction_id(),
				'amount'            => round( 100 * $order->order_total ),
				'orderId'           => $order->id,
				'vatAmount'         => 0,
				'additionalValues'  => $additionalValues
			);
			$result = $gateway->getPx()->Capture5( $params );
			if ( $result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK' ) {
				$gateway->log( 'PxOrder.Capture5:' . $result['errorCode'] . '(' . $result['description'] . ')' );

				return;
			}

			update_post_meta( $order->id, '_payex_transaction_status', $result['transactionStatus'] );
			$order->add_order_note( sprintf( __( 'Transaction captured. Transaction Id: %s', 'woocommerce-gateway-payex-payment' ), $result['transactionNumber'] ) );
		}
	}

	/**
	 * Capture payment when the order is changed from on-hold to cancelled
	 *
	 * @param  int $order_id
	 */
	public function cancel_payment( $order_id ) {
		$order              = wc_get_order( $order_id );
		$transaction_status = get_post_meta( $order_id, '_payex_transaction_status', true );
		if ( empty( $transaction_status ) ) {
			return;
		}

		// Get Payment Gateway
		$gateway  = false;
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		foreach ( $gateways as $id => $tmp ) {
			if ( $id === $order->payment_method ) {
				$gateway = $tmp;
				break;
			}
		}

		if ( $gateway && (string) $transaction_status === '3' ) {
			$gateway = new WC_Gateway_Payex_Payment();

			// Call PxOrder.Cancel2
			$params = array(
				'accountNumber'     => '',
				'transactionNumber' => $order->get_transaction_id()
			);
			$result = $gateway->getPx()->Cancel2( $params );
			if ( $result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK' ) {
				$gateway->log( 'PxOrder.Cancel2:' . $result['errorCode'] . '(' . $result['description'] . ')' );

				return;
			}

			update_post_meta( $order->id, '_payex_transaction_status', $result['transactionStatus'] );
			$order->add_order_note( sprintf( __( 'Transaction canceled. Transaction Id: %s', 'woocommerce-gateway-payex-payment' ), $result['transactionNumber'] ) );
		}
	}
}

new WC_Payex_Payment();
