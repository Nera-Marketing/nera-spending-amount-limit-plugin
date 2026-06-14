<?php
/**
 * Enqueue plain CSS/JS on the edit-account endpoint and checkout, and localize config.
 *
 * @package Nera_Spending_Limit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SL_Assets
 */
class Nera_SL_Assets {

	const STYLE_HANDLE    = 'nera-sl-styles';
	const ACCOUNT_HANDLE  = 'nera-sl-account';
	const CHECKOUT_HANDLE  = 'nera-sl-checkout';

	/**
	 * Init.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	/**
	 * Enqueue per-context.
	 */
	public static function enqueue() {
		if ( ! Nera_SL_Settings::is_enabled() || ! is_user_logged_in() ) {
			return;
		}

		$is_account  = function_exists( 'is_account_page' ) && is_account_page() && is_wc_endpoint_url( 'edit-account' );
		$is_checkout = function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' );

		if ( ! $is_account && ! $is_checkout ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			NERA_SL_PLUGIN_URL . 'assets/css/spending-limit.css',
			array(),
			NERA_SL_VERSION
		);

		if ( $is_account ) {
			self::enqueue_account();
		}
		if ( $is_checkout ) {
			self::enqueue_checkout();
		}
	}

	/**
	 * Currency symbol as a plain string (entities decoded).
	 *
	 * @return string
	 */
	protected static function currency_symbol() {
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			return html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		}
		return '';
	}

	/**
	 * Account-page script + data.
	 */
	protected static function enqueue_account() {
		$user_id = get_current_user_id();
		$config  = Nera_SL_User_Limit::get_config( $user_id );

		$types = array();
		$all   = Nera_SL_Settings::all_types();
		foreach ( Nera_SL_Settings::enabled_types() as $slug ) {
			$types[] = array(
				'value' => $slug,
				'label' => $all[ $slug ],
			);
		}

		$subtypes = array();
		foreach ( Nera_SL_Settings::custom_subtypes() as $slug => $label ) {
			$subtypes[] = array(
				'value' => $slug,
				'label' => $label,
			);
		}

		wp_enqueue_script(
			self::ACCOUNT_HANDLE,
			NERA_SL_PLUGIN_URL . 'assets/js/account-spend-limit.js',
			array(),
			NERA_SL_VERSION,
			true
		);

		wp_localize_script(
			self::ACCOUNT_HANDLE,
			'neraSpendLimit',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'saveAction'     => 'nera_sl_save',
				'nonce'          => wp_create_nonce( 'nera_sl_save' ),
				'sliderMax'      => Nera_SL_Wallet::slider_max( $user_id ),
				'walletActive'   => Nera_SL_Wallet::is_active(),
				'currency'       => self::currency_symbol(),
				'defaultType'    => Nera_SL_Settings::default_type(),
				'enabledTypes'   => $types,
				'customSubtypes' => $subtypes,
				'config'         => $config,
				'i18n'           => array(
					'removeTitle'   => __( 'Remove this period?', 'nera-spending-limit' ),
					'removeBody'    => __( 'This period will no longer have a spending limit applied.', 'nera-spending-limit' ),
					'cancel'        => __( 'Cancel', 'nera-spending-limit' ),
					'remove'        => __( 'Remove', 'nera-spending-limit' ),
					'saving'        => __( 'Saving…', 'nera-spending-limit' ),
					'saved'         => __( 'Your spending limit has been saved.', 'nera-spending-limit' ),
					'genericError'  => __( 'Something went wrong. Please try again.', 'nera-spending-limit' ),
					'selectPeriods' => __( 'Click the calendar to select the periods to limit.', 'nera-spending-limit' ),
				),
			)
		);
	}

	/**
	 * Checkout script + data.
	 */
	protected static function enqueue_checkout() {
		wp_enqueue_script(
			self::CHECKOUT_HANDLE,
			NERA_SL_PLUGIN_URL . 'assets/js/checkout-spend-limit.js',
			array( 'jquery' ),
			NERA_SL_VERSION,
			true
		);

		wp_localize_script(
			self::CHECKOUT_HANDLE,
			'neraSpendLimitCheckout',
			array(
				'ackField' => Nera_SL_Checkout::ACK_FIELD,
				'i18n'     => array(
					'confirmTitle'  => __( 'You are over your spending limit', 'nera-spending-limit' ),
					// Fallback only; the live, amount-substituted message comes from the
					// card's data-confirm-msg (see class-checkout.php). Strip placeholders here.
					'confirmBody'   => trim( str_replace( array( '{limit}', '{spent}', '{total}', '{over}' ), '', Nera_SL_Settings::over_limit_message() ) ),
					'cancel'        => __( 'Cancel', 'nera-spending-limit' ),
					'continue'      => __( 'Continue anyway', 'nera-spending-limit' ),
				),
			)
		);
	}
}
