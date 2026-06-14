<?php
/**
 * Plugin Name: Nera – Spending Limit
 * Description: Lets logged-in customers set a voluntary spending limit (daily/weekly/monthly/yearly/custom). Admins enable and configure the feature under Theme Settings → Nera Features. The limit is surfaced and enforced at checkout, with TeraWallet awareness.
 * Version: 1.0.0
 * Author: Nera
 * Text Domain: nera-spending-limit
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package Nera_Spending_Limit
 */

defined( 'ABSPATH' ) || exit;

define( 'NERA_SL_VERSION', '1.0.0' );
define( 'NERA_SL_PLUGIN_FILE', __FILE__ );
define( 'NERA_SL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NERA_SL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once NERA_SL_PLUGIN_DIR . 'includes/class-settings.php';
require_once NERA_SL_PLUGIN_DIR . 'includes/class-wallet.php';
require_once NERA_SL_PLUGIN_DIR . 'includes/class-user-limit.php';
require_once NERA_SL_PLUGIN_DIR . 'includes/class-assets.php';
require_once NERA_SL_PLUGIN_DIR . 'includes/class-account.php';
require_once NERA_SL_PLUGIN_DIR . 'includes/class-checkout.php';

/**
 * Bootstrap plugin.
 */
function nera_sl_init() {
	load_plugin_textdomain( 'nera-spending-limit', false, dirname( plugin_basename( NERA_SL_PLUGIN_FILE ) ) . '/languages' );

	// Settings (ACF options page) loads regardless of WooCommerce so the admin can configure early.
	Nera_SL_Settings::init();

	// The customer-facing feature requires WooCommerce.
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	Nera_SL_Assets::init();
	Nera_SL_Account::init();
	Nera_SL_Checkout::init();
}
add_action( 'plugins_loaded', 'nera_sl_init', 20 );

/**
 * WooCommerce HPOS (custom order tables) compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
