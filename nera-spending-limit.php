<?php
/**
 * Plugin Name: Nera – Spending Limit
 * Plugin URI: https://github.com/Nera-Marketing/nera-spending-amount-limit-plugin
 * Description: Lets logged-in customers set a voluntary spending limit (daily/weekly/monthly/yearly/custom). Admins enable and configure the feature under Theme Settings → Nera Features. The limit is surfaced and enforced at checkout, with TeraWallet awareness.
 * Version: 1.0.1
 * Author: Nera
 * Text Domain: nera-spending-limit
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package Nera_Spending_Limit
 */

use YahnisElsts\PluginUpdateChecker\v5p5\Vcs\GitHubApi;

defined( 'ABSPATH' ) || exit;

define( 'NERA_SL_VERSION', '1.0.1' );
define( 'NERA_SL_PLUGIN_FILE', __FILE__ );
define( 'NERA_SL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NERA_SL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * GitHub updates (Plugin Update Checker v5.5). On by default when `lib/plugin-update-checker/load-v5p5.php` exists.
 * Parity with nera-instant-win-threshold / nera-spin-to-win.
 *
 * Disable:      define( 'NERA_SL_DISABLE_GITHUB_UPDATES', true );
 * Private repo: define( 'NERA_SL_GITHUB_TOKEN', 'ghp_...' );
 * Custom URL:   define( 'NERA_SL_GITHUB_REPO_URL', 'https://github.com/Owner/repo/' );  (or filter nera_sl_github_repo_url)
 *
 * PUC reads the `Version` header from the GitHub ref it selects. Bump `Version` + `NERA_SL_VERSION` for every
 * release, then tag/push to match (release.sh does this). A custom setReleaseFilter (always true) + maxReleases > 1
 * makes GitHubApi use the paginated /releases endpoint instead of /latest (which 404s without a GitHub "latest"
 * release). enableReleaseAssets() prefers the attached `nera-spending-limit-<version>.zip` over the tag tarball.
 *
 * @link https://github.com/YahnisElsts/plugin-update-checker
 */
if ( ! defined( 'NERA_SL_DISABLE_GITHUB_UPDATES' ) || ! NERA_SL_DISABLE_GITHUB_UPDATES ) {
	$nera_sl_github_repo_default = 'https://github.com/Nera-Marketing/nera-spending-amount-limit-plugin/';
	if ( defined( 'NERA_SL_GITHUB_REPO_URL' ) && is_string( NERA_SL_GITHUB_REPO_URL ) && NERA_SL_GITHUB_REPO_URL !== '' ) {
		$nera_sl_github_repo_default = NERA_SL_GITHUB_REPO_URL;
	}
	$nera_sl_github_repo = apply_filters( 'nera_sl_github_repo_url', $nera_sl_github_repo_default );

	$nera_sl_puc_loader = NERA_SL_PLUGIN_DIR . 'lib/plugin-update-checker/load-v5p5.php';
	if ( is_readable( $nera_sl_puc_loader ) ) {
		require_once $nera_sl_puc_loader;
		// Fourth argument: check period in hours (PUC default is 12).
		$nera_sl_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			$nera_sl_github_repo,
			__FILE__,
			'nera-spending-limit',
			6
		);
		$nera_sl_update_checker->setBranch( 'main' );

		if ( defined( 'NERA_SL_GITHUB_TOKEN' ) && is_string( NERA_SL_GITHUB_TOKEN ) && NERA_SL_GITHUB_TOKEN !== '' ) {
			$nera_sl_update_checker->setAuthentication( NERA_SL_GITHUB_TOKEN );
		}

		$nera_sl_puc_vcs = $nera_sl_update_checker->getVcsApi();
		if ( $nera_sl_puc_vcs instanceof GitHubApi ) {
			$nera_sl_puc_vcs->setReleaseFilter(
				static function ( $version_number, $release_object ) {
					unset( $version_number, $release_object );
					return true;
				},
				\YahnisElsts\PluginUpdateChecker\v5p5\Vcs\Api::RELEASE_FILTER_SKIP_PRERELEASE,
				20
			);
			$nera_sl_puc_vcs->enableReleaseAssets();
		}
	}
}

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
