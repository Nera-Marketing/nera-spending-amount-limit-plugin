<?php
/**
 * TeraWallet (woo-wallet) integration helpers. All methods degrade gracefully
 * when the wallet plugin is inactive.
 *
 * @package Nera_Spending_Limit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SL_Wallet
 */
class Nera_SL_Wallet {

	/**
	 * Whether a supported wallet plugin is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return function_exists( 'woo_wallet' ) && is_object( woo_wallet() ) && isset( woo_wallet()->wallet );
	}

	/**
	 * Current wallet balance for a user (edit context = raw float).
	 *
	 * @param int $user_id User ID.
	 * @return float
	 */
	public static function get_balance( $user_id ) {
		if ( ! self::is_active() || $user_id < 1 ) {
			return 0.0;
		}
		return (float) woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
	}
}
