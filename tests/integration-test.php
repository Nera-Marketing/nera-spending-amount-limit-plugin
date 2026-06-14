<?php
/**
 * Integration smoke test with lightweight WordPress/WooCommerce stubs.
 *
 * Loads ALL plugin classes together and exercises the WP-bound glue:
 *   - Settings getters (fallbacks when ACF returns nothing)
 *   - sanitize_input() against the CMS rules (valid + each rejection path)
 *   - get_config()/save_config() round-trip (in-memory user meta)
 *   - evaluate_for_user() with a stubbed paid-order history (wallet inactive)
 *
 * Run:  php tests/integration-test.php
 *
 * @package Nera_Spending_Limit
 */

error_reporting( E_ALL );
date_default_timezone_set( 'UTC' ); // phpcs:ignore

define( 'ABSPATH', __DIR__ . '/' );

/* ----------------------------------------------------------------- */
/* Minimal WP/WC stubs                                               */
/* ----------------------------------------------------------------- */
$GLOBALS['__user_meta'] = array();
$GLOBALS['__orders']    = array(); // array of totals returned by wc_get_orders.

function get_field( $name, $id = false ) { return null; }                 // Force Settings fallbacks.
function __( $s, $d = null ) { return $s; }
function _n( $a, $b, $n, $d = null ) { return 1 === $n ? $a : $b; }
function apply_filters( $tag, $value ) { return $value; }
function get_user_meta( $uid, $key, $single = false ) {
	return isset( $GLOBALS['__user_meta'][ $uid ][ $key ] ) ? $GLOBALS['__user_meta'][ $uid ][ $key ] : '';
}
function update_user_meta( $uid, $key, $value ) {
	$GLOBALS['__user_meta'][ $uid ][ $key ] = $value;
	return true;
}
function wc_get_orders( $args ) {
	$out = array();
	foreach ( $GLOBALS['__orders'] as $total ) {
		$out[] = new Nera_SL_Fake_Order( $total );
	}
	return $out;
}
class Nera_SL_Fake_Order {
	private $total;
	public function __construct( $total ) { $this->total = $total; }
	public function get_total() { return $this->total; }
}

/* ----------------------------------------------------------------- */
require __DIR__ . '/../includes/class-settings.php';
require __DIR__ . '/../includes/class-wallet.php';
require __DIR__ . '/../includes/class-user-limit.php';

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
function check( $label, $got, $expected ) {
	$ok = ( $got === $expected );
	$gs = is_bool( $got ) ? ( $got ? 'true' : 'false' ) : ( null === $got ? 'null' : (string) $got );
	$es = is_bool( $expected ) ? ( $expected ? 'true' : 'false' ) : ( null === $expected ? 'null' : (string) $expected );
	printf( "  [%s] %-46s got=%-18s exp=%s\n", $ok ? 'PASS' : 'FAIL', $label, $gs, $es );
	$ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
}

$S = 'Nera_SL_Settings';
$L = 'Nera_SL_User_Limit';
$W = 'Nera_SL_Wallet';

echo "== Settings fallbacks ==\n";
check( 'is_enabled() false (no field)', $S::is_enabled(), false );
check( 'enabled_types count = 5', count( $S::enabled_types() ), 5 );
check( 'default_type = monthly', $S::default_type(), 'monthly' );

echo "\n== Wallet (inactive) ==\n";
check( 'wallet inactive', $W::is_active(), false );

echo "\n== sanitize_input (enabled path) ==\n";
$ok = $L::sanitize_input( array( 'enabled' => '1', 'amount' => '250', 'type' => 'monthly' ) );
check( 'valid monthly ok', ! empty( $ok['ok'] ), true );
check( 'valid monthly enabled', $ok['config']['enabled'], true );
check( 'valid monthly amount', $ok['config']['amount'], 250.0 );

// No upper cap any more: a large amount is accepted as-is.
$big = $L::sanitize_input( array( 'enabled' => '1', 'amount' => '999999', 'type' => 'daily' ) );
check( 'large amount accepted (no cap)', $big['config']['amount'], 999999.0 );

$badtype = $L::sanitize_input( array( 'enabled' => '1', 'amount' => '50', 'type' => 'fortnightly' ) );
check( 'bad type rejected (enabled)', empty( $badtype['ok'] ), true );

$badamt = $L::sanitize_input( array( 'enabled' => '1', 'amount' => '0', 'type' => 'daily' ) );
check( 'amount < 1 rejected (enabled)', empty( $badamt['ok'] ), true );

$custom_ok = $L::sanitize_input(
	array(
		'enabled'        => '1',
		'amount'         => '80',
		'type'           => 'custom',
		'custom_subtype' => 'day',
		'custom_periods' => array( '2026-06-14', '2026-06-14', 'garbage', '2026-06-20' ),
	)
);
check( 'custom valid ok', ! empty( $custom_ok['ok'] ), true );
check( 'custom dedupe+drop invalid (2 left)', count( $custom_ok['config']['custom_periods'] ), 2 );

echo "\n== sanitize_input (disable / remove path) ==\n";
// Toggle OFF: accepted as a removal, not rejected.
$off = $L::sanitize_input( array( 'enabled' => '0', 'amount' => '250', 'type' => 'monthly' ) );
check( 'disabled -> ok', ! empty( $off['ok'] ), true );
check( 'disabled -> enabled=false', $off['config']['enabled'], false );
check( 'disabled -> cleared flag', ! empty( $off['cleared'] ), true );

// Enabled custom but ALL periods removed: accepted as a removal (per request), not rejected.
$custom_empty = $L::sanitize_input(
	array( 'enabled' => '1', 'amount' => '80', 'type' => 'custom', 'custom_subtype' => 'day', 'custom_periods' => array( 'nope' ) )
);
check( 'custom no periods -> ok (not rejected)', ! empty( $custom_empty['ok'] ), true );
check( 'custom no periods -> cleared flag', ! empty( $custom_empty['cleared'] ), true );
check( 'custom no periods -> 0 stored', count( $custom_empty['config']['custom_periods'] ), 0 );

echo "\n== get_config / save_config round-trip ==\n";
$L::save_config( 42, $custom_ok['config'] );
$loaded = $L::get_config( 42 );
check( 'loaded type custom', $loaded['type'], 'custom' );
check( 'loaded amount 80', $loaded['amount'], 80.0 );
check( 'loaded periods 2', count( $loaded['custom_periods'] ), 2 );
check( 'has_limit true', $L::has_limit( 42 ), true );
check( 'has_limit false (unknown user)', $L::has_limit( 99 ), false );

echo "\n== evaluate_for_user (monthly £200, wallet inactive) ==\n";
$L::save_config( 7, array( 'amount' => 200.0, 'type' => 'monthly' ) );
$ref = gmmktime( 12, 0, 0, 6, 14, 2026 );

$GLOBALS['__orders'] = array( 50.0, 30.0 ); // £80 already spent this month.
$r1 = $L::evaluate_for_user( 7, 40.0, $ref );  // 80 + 40 = 120 <= 200.
check( 'under limit -> ok', $r1['state'], 'ok' );
check( 'spent_before = 80', $r1['spent_before'], 80.0 );

$GLOBALS['__orders'] = array( 150.0, 30.0 ); // £180 spent.
$r2 = $L::evaluate_for_user( 7, 40.0, $ref );  // 180 + 40 = 220 > 200, no wallet.
check( 'over limit, cash -> over_soft', $r2['state'], 'over_soft' );

$L::save_config( 8, array( 'amount' => 0.0, 'type' => 'monthly' ) ); // no limit set.
$r3 = $L::evaluate_for_user( 8, 9999.0, $ref );
check( 'no limit configured -> has_limit false', $r3['has_limit'], false );
check( 'no limit configured -> ok', $r3['state'], 'ok' );

echo "\n== enabled flag gate ==\n";
// Legacy config (no 'enabled' key) with an amount is treated as enabled.
$legacy = $L::normalize( array( 'amount' => 100.0, 'type' => 'daily' ) );
check( 'legacy amount>0 -> enabled true', $legacy['enabled'], true );
$legacy0 = $L::normalize( array( 'amount' => 0.0, 'type' => '' ) );
check( 'legacy amount=0 -> enabled false', $legacy0['enabled'], false );

// Explicitly disabled config: no active window, no enforcement.
$disabled = array( 'enabled' => false, 'amount' => 200.0, 'type' => 'monthly' );
check( 'disabled -> active_window null', $L::active_window( $disabled, $ref ), null );

$GLOBALS['__orders'] = array( 5000.0 );
$L::save_config( 9, array( 'enabled' => false, 'amount' => 200.0, 'type' => 'monthly' ) );
$r4 = $L::evaluate_for_user( 9, 9999.0, $ref );
check( 'disabled user -> has_limit false', $r4['has_limit'], false );
check( 'disabled user -> ok (no enforcement)', $r4['state'], 'ok' );

// has_limit() respects the flag.
$L::save_config( 10, array( 'enabled' => false, 'amount' => 200.0, 'type' => 'monthly' ) );
check( 'has_limit false when disabled', $L::has_limit( 10 ), false );

echo "\n----------------------------------------------------------\n";
printf( "RESULT: %d passed, %d failed\n", $GLOBALS['pass'], $GLOBALS['fail'] );
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
