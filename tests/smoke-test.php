<?php
/**
 * Standalone smoke test for the WP-free spending-limit logic.
 *
 * Run:  php tests/smoke-test.php
 *
 * It stubs the minimal constants the class file needs, forces UTC for
 * deterministic window math, and exercises:
 *   - window_bounds()  (daily / weekly / monthly / yearly)
 *   - token_bounds()   (day / week / month / year, incl. invalid tokens)
 *   - active_window()  (recurring vs custom in/out of period)
 *   - evaluate()       (the full ok / over_soft / over_blocked decision matrix)
 *
 * @package Nera_Spending_Limit
 */

error_reporting( E_ALL );
date_default_timezone_set( 'UTC' ); // phpcs:ignore -- deterministic test math; wp_timezone() is intentionally absent.

define( 'ABSPATH', __DIR__ . '/' ); // Satisfy the file guard.

require __DIR__ . '/../includes/class-user-limit.php';

/* ----------------------------------------------------------------- */
/* Tiny assert harness                                               */
/* ----------------------------------------------------------------- */
$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check( $label, $got, $expected ) {
	$ok   = ( $got === $expected );
	$gs   = is_bool( $got ) ? ( $got ? 'true' : 'false' ) : ( null === $got ? 'null' : (string) $got );
	$es   = is_bool( $expected ) ? ( $expected ? 'true' : 'false' ) : ( null === $expected ? 'null' : (string) $expected );
	$mark = $ok ? 'PASS' : 'FAIL';
	if ( $ok ) {
		$GLOBALS['pass']++;
	} else {
		$GLOBALS['fail']++;
	}
	printf( "  [%s] %-52s got=%-22s exp=%s\n", $mark, $label, $gs, $es );
}

function fmt( $ts ) {
	return gmdate( 'Y-m-d H:i:s', $ts );
}

$L = 'Nera_SL_User_Limit';

/* ----------------------------------------------------------------- */
echo "== unit_for_type ==\n";
check( 'daily->day', $L::unit_for_type( 'daily' ), 'day' );
check( 'weekly->week', $L::unit_for_type( 'weekly' ), 'week' );
check( 'monthly->month', $L::unit_for_type( 'monthly' ), 'month' );
check( 'yearly->year', $L::unit_for_type( 'yearly' ), 'year' );
check( 'custom->null', $L::unit_for_type( 'custom' ), null );

/* ----------------------------------------------------------------- */
echo "\n== window_bounds (ref = 2026-06-14 10:30:00 UTC, a Sunday) ==\n";
$ref = gmmktime( 10, 30, 0, 6, 14, 2026 );

$day = $L::window_bounds( 'day', $ref );
check( 'day start', fmt( $day[0] ), '2026-06-14 00:00:00' );
check( 'day end', fmt( $day[1] ), '2026-06-15 00:00:00' );
check( 'day span=86400', $day[1] - $day[0], 86400 );
check( 'ref within day', ( $ref >= $day[0] && $ref < $day[1] ), true );

$week = $L::window_bounds( 'week', $ref );
check( 'week start (Mon)', fmt( $week[0] ), '2026-06-08 00:00:00' );
check( 'week end', fmt( $week[1] ), '2026-06-15 00:00:00' );
check( 'week span=604800', $week[1] - $week[0], 604800 );
check( 'week start is Monday', gmdate( 'N', $week[0] ), '1' );

$month = $L::window_bounds( 'month', $ref );
check( 'month start', fmt( $month[0] ), '2026-06-01 00:00:00' );
check( 'month end', fmt( $month[1] ), '2026-07-01 00:00:00' );

$year = $L::window_bounds( 'year', $ref );
check( 'year start', fmt( $year[0] ), '2026-01-01 00:00:00' );
check( 'year end', fmt( $year[1] ), '2027-01-01 00:00:00' );

/* ----------------------------------------------------------------- */
echo "\n== token_bounds ==\n";
$td = $L::token_bounds( 'day', '2026-06-14' );
check( 'day token start', fmt( $td[0] ), '2026-06-14 00:00:00' );
check( 'day token span', $td[1] - $td[0], 86400 );

$tw = $L::token_bounds( 'week', '2026-06-08' );
check( 'week token span', $tw[1] - $tw[0], 604800 );

$tm = $L::token_bounds( 'month', '2026-06' );
check( 'month token start', fmt( $tm[0] ), '2026-06-01 00:00:00' );
check( 'month token span (June=30d)', $tm[1] - $tm[0], 30 * 86400 );

$ty = $L::token_bounds( 'year', '2026' );
check( 'year token start', fmt( $ty[0] ), '2026-01-01 00:00:00' );
check( 'year token span (2026=365d)', $ty[1] - $ty[0], 365 * 86400 );

check( 'invalid day token -> null', $L::token_bounds( 'day', '2026-13-40' ), null );
check( 'non-padded month -> null', $L::token_bounds( 'month', '2026-6' ), null );
check( 'invalid year -> null', $L::token_bounds( 'year', 'abcd' ), null );

/* ----------------------------------------------------------------- */
echo "\n== active_window ==\n";
$custom_day = array(
	'amount'         => 50.0,
	'type'           => 'custom',
	'custom_subtype' => 'day',
	'custom_periods' => array( '2026-06-14' ),
);
$in  = $L::active_window( $custom_day, gmmktime( 9, 0, 0, 6, 14, 2026 ) );
$out = $L::active_window( $custom_day, gmmktime( 9, 0, 0, 6, 15, 2026 ) );
check( 'custom in-period limit', $in ? $in['limit'] : null, 50.0 );
check( 'custom in-period unit', $in ? $in['unit'] : null, 'day' );
check( 'custom out-of-period -> null', $out, null );

$recurring = array( 'amount' => 200.0, 'type' => 'monthly' );
$rw        = $L::active_window( $recurring, $ref );
check( 'recurring monthly limit', $rw ? $rw['limit'] : null, 200.0 );
check( 'recurring monthly unit', $rw ? $rw['unit'] : null, 'month' );

$zero = $L::active_window( array( 'amount' => 0, 'type' => 'monthly' ), $ref );
check( 'amount=0 -> null', $zero, null );

/* ----------------------------------------------------------------- */
echo "\n== evaluate (decision matrix) ==\n";
// limit=null -> ok regardless.
check( 'no limit -> ok', $L::evaluate( null, 0, 999, false, 0 )['state'], 'ok' );

// Within limit.
check( 'within -> ok', $L::evaluate( 100, 50, 30, false, 0 )['state'], 'ok' );
// Exactly at limit (epsilon-safe).
check( 'exactly at -> ok', $L::evaluate( 100, 70, 30, false, 0 )['state'], 'ok' );

// Over, no wallet -> soft.
$soft = $L::evaluate( 100, 80, 40, false, 0 );
check( 'over + no wallet -> over_soft', $soft['state'], 'over_soft' );
check( 'over_amount = 20', $soft['over_amount'], 20.0 );

// Over, wallet active and covers -> soft confirm (customer must acknowledge).
$cover = $L::evaluate( 100, 80, 40, true, 50 );
check( 'over + wallet covers -> over_soft', $cover['state'], 'over_soft' );
check( 'wallet_covers true', $cover['wallet_covers'], true );

// Over, wallet active but insufficient -> hard blocked.
$block = $L::evaluate( 100, 80, 40, true, 30 );
check( 'over + wallet short -> over_blocked', $block['state'], 'over_blocked' );
check( 'wallet_covers false', $block['wallet_covers'], false );

/* ----------------------------------------------------------------- */
echo "\n----------------------------------------------------------\n";
printf( "RESULT: %d passed, %d failed\n", $GLOBALS['pass'], $GLOBALS['fail'] );
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
