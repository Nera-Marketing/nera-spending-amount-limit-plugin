<?php
/**
 * Per-user spending-limit model + the (WP-free, unit-testable) limit logic.
 *
 * Storage: user meta `_nera_sl_config` =>
 *   array(
 *     'amount'         => float,
 *     'type'           => 'daily'|'weekly'|'monthly'|'yearly'|'custom',
 *     'custom_subtype' => 'day'|'week'|'month'|'year',   // only when type=custom
 *     'custom_periods' => string[],                       // tokens, see token_bounds()
 *     'updated_at'     => int (unix ts),
 *   )
 *
 * @package Nera_Spending_Limit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SL_User_Limit
 */
class Nera_SL_User_Limit {

	const META_KEY = '_nera_sl_config';
	const EPS       = 0.0001;

	/* ---------------------------------------------------------------------
	 * Pure logic (no WordPress/WooCommerce dependencies — safe to unit test)
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve the calendar unit for a recurring type.
	 *
	 * @param string $type daily|weekly|monthly|yearly.
	 * @return string|null day|week|month|year, or null if not a recurring type.
	 */
	public static function unit_for_type( $type ) {
		$map = array(
			'daily'   => 'day',
			'weekly'  => 'week',
			'monthly' => 'month',
			'yearly'  => 'year',
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : null;
	}

	/**
	 * Timezone used for all window math. WP timezone when available.
	 *
	 * @return DateTimeZone
	 */
	protected static function tz() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}
		$name = date_default_timezone_get();
		return new DateTimeZone( $name ? $name : 'UTC' );
	}

	/**
	 * Build a timezone-aware immutable date from a unix timestamp.
	 *
	 * @param int $ts Unix timestamp.
	 * @return DateTimeImmutable
	 */
	protected static function dt( $ts ) {
		$d = new DateTimeImmutable( '@' . (int) $ts ); // UTC.
		return $d->setTimezone( self::tz() );
	}

	/**
	 * Calendar window bounds containing $ref_ts for the given unit.
	 *
	 * @param string $unit day|week|month|year.
	 * @param int    $ref_ts Reference unix timestamp.
	 * @return array{0:int,1:int}|null [start_ts, end_ts) or null for unknown unit.
	 */
	public static function window_bounds( $unit, $ref_ts ) {
		$d = self::dt( $ref_ts );

		switch ( $unit ) {
			case 'day':
				$start = $d->setTime( 0, 0, 0 );
				$end   = $start->modify( '+1 day' );
				break;
			case 'week': // ISO-8601, week starts Monday.
				$dow   = (int) $d->format( 'N' ); // 1 (Mon) .. 7 (Sun).
				$start = $d->setTime( 0, 0, 0 )->modify( '-' . ( $dow - 1 ) . ' days' );
				$end   = $start->modify( '+7 days' );
				break;
			case 'month':
				$start = $d->setTime( 0, 0, 0 )->modify( 'first day of this month' );
				$end   = $start->modify( '+1 month' );
				break;
			case 'year':
				$start = $d->setDate( (int) $d->format( 'Y' ), 1, 1 )->setTime( 0, 0, 0 );
				$end   = $start->modify( '+1 year' );
				break;
			default:
				return null;
		}

		return array( $start->getTimestamp(), $end->getTimestamp() );
	}

	/**
	 * Bounds for a single custom-period token.
	 *
	 * Token formats: day/week => 'YYYY-MM-DD' (week = Monday start),
	 *                month => 'YYYY-MM', year => 'YYYY'.
	 *
	 * @param string $subtype day|week|month|year.
	 * @param string $token   Period token.
	 * @return array{0:int,1:int}|null [start_ts, end_ts) or null if unparseable.
	 */
	public static function token_bounds( $subtype, $token ) {
		$tz = self::tz();

		switch ( $subtype ) {
			case 'day':
			case 'week':
				$start = DateTimeImmutable::createFromFormat( 'Y-m-d', $token, $tz );
				if ( ! $start || $start->format( 'Y-m-d' ) !== $token ) {
					return null;
				}
				$start = $start->setTime( 0, 0, 0 );
				$end   = $start->modify( 'day' === $subtype ? '+1 day' : '+7 days' );
				break;
			case 'month':
				$start = DateTimeImmutable::createFromFormat( 'Y-m-d', $token . '-01', $tz );
				if ( ! $start || $start->format( 'Y-m' ) !== $token ) {
					return null;
				}
				$start = $start->setTime( 0, 0, 0 );
				$end   = $start->modify( '+1 month' );
				break;
			case 'year':
				if ( ! preg_match( '/^\d{4}$/', (string) $token ) ) {
					return null;
				}
				$start = DateTimeImmutable::createFromFormat( 'Y-m-d', $token . '-01-01', $tz );
				if ( ! $start ) {
					return null;
				}
				$start = $start->setTime( 0, 0, 0 );
				$end   = $start->modify( '+1 year' );
				break;
			default:
				return null;
		}

		return array( $start->getTimestamp(), $end->getTimestamp() );
	}

	/**
	 * The active window + applicable cap for an order placed at $ref_ts.
	 *
	 * For recurring types this is the calendar window around $ref_ts. For custom
	 * types it is the configured period that contains $ref_ts (null if none).
	 *
	 * @param array $config  User config.
	 * @param int   $ref_ts  Reference unix timestamp.
	 * @return array{start:int,end:int,limit:float,unit:string,token?:string}|null
	 */
	public static function active_window( array $config, $ref_ts ) {
		// Respect the per-user on/off flag.
		if ( isset( $config['enabled'] ) && ! $config['enabled'] ) {
			return null;
		}

		$amount = isset( $config['amount'] ) ? (float) $config['amount'] : 0.0;
		if ( $amount <= 0 ) {
			return null;
		}

		$type = isset( $config['type'] ) ? (string) $config['type'] : '';

		if ( 'custom' === $type ) {
			$sub     = isset( $config['custom_subtype'] ) ? (string) $config['custom_subtype'] : '';
			$periods = isset( $config['custom_periods'] ) && is_array( $config['custom_periods'] ) ? $config['custom_periods'] : array();
			foreach ( $periods as $token ) {
				$b = self::token_bounds( $sub, $token );
				if ( $b && $ref_ts >= $b[0] && $ref_ts < $b[1] ) {
					return array(
						'start' => $b[0],
						'end'   => $b[1],
						'limit' => $amount,
						'unit'  => $sub,
						'token' => $token,
					);
				}
			}
			return null;
		}

		$unit = self::unit_for_type( $type );
		if ( ! $unit ) {
			return null;
		}
		$b = self::window_bounds( $unit, $ref_ts );
		if ( ! $b ) {
			return null;
		}
		return array(
			'start' => $b[0],
			'end'   => $b[1],
			'limit' => $amount,
			'unit'  => $unit,
		);
	}

	/**
	 * Decide the checkout outcome.
	 *
	 * @param float|null $limit         Applicable cap (null = no limit applies).
	 * @param float      $spent_before  Already spent in the window.
	 * @param float      $cart_total    Current order total.
	 * @param bool       $wallet_active Whether a wallet plugin is active.
	 * @param float      $wallet_balance Current wallet balance.
	 * @return array{state:string,projected:float,limit:float|null,over_amount:float,spent_before:float,cart_total:float,wallet_active:bool,wallet_balance:float,wallet_covers:bool}
	 *         state: 'ok' | 'over_soft' | 'over_blocked'.
	 */
	public static function evaluate( $limit, $spent_before, $cart_total, $wallet_active, $wallet_balance ) {
		$spent_before  = (float) $spent_before;
		$cart_total    = (float) $cart_total;
		$wallet_balance = (float) $wallet_balance;
		$projected     = $spent_before + $cart_total;
		$wallet_covers = $wallet_active && ( $wallet_balance + self::EPS >= $cart_total );

		$result = array(
			'state'          => 'ok',
			'projected'      => $projected,
			'limit'          => $limit,
			'over_amount'    => 0.0,
			'spent_before'   => $spent_before,
			'cart_total'     => $cart_total,
			'wallet_active'  => (bool) $wallet_active,
			'wallet_balance' => $wallet_balance,
			'wallet_covers'  => $wallet_covers,
		);

		if ( null === $limit || $projected <= (float) $limit + self::EPS ) {
			return $result; // Within limit (or no limit applies).
		}

		$result['over_amount'] = $projected - (float) $limit;

		// Over the limit. The customer is always asked to confirm (soft block),
		// EXCEPT when a wallet is active but its balance cannot cover the order —
		// then there are no funds to proceed with, so it is hard-blocked.
		if ( $wallet_active && ! $wallet_covers ) {
			$result['state'] = 'over_blocked';
		} else {
			$result['state'] = 'over_soft';
		}

		return $result;
	}

	/* ---------------------------------------------------------------------
	 * WordPress / WooCommerce-bound methods
	 * ------------------------------------------------------------------- */

	/**
	 * Get a user's stored config, normalized.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function get_config( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return self::normalize( $raw );
	}

	/**
	 * Whether the user has an active (amount > 0) limit configured.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function has_limit( $user_id ) {
		$config = self::get_config( $user_id );
		return ! empty( $config['enabled'] ) && $config['amount'] > 0;
	}

	/**
	 * Normalize/repair a config array (does not enforce CMS-enabled types — see sanitize_input()).
	 *
	 * @param array $raw Raw array.
	 * @return array
	 */
	public static function normalize( array $raw ) {
		$amount = isset( $raw['amount'] ) ? max( 0.0, (float) $raw['amount'] ) : 0.0;
		$config = array(
			// Legacy configs (saved before the per-user flag existed) are treated as
			// enabled when they carry an amount.
			'enabled'        => isset( $raw['enabled'] ) ? (bool) $raw['enabled'] : ( $amount > 0 ),
			'amount'         => $amount,
			'type'           => isset( $raw['type'] ) ? (string) $raw['type'] : '',
			'custom_subtype' => isset( $raw['custom_subtype'] ) ? (string) $raw['custom_subtype'] : '',
			'custom_periods' => array(),
			'updated_at'     => isset( $raw['updated_at'] ) ? (int) $raw['updated_at'] : 0,
		);

		if ( ! array_key_exists( $config['type'], Nera_SL_Settings::all_types() ) ) {
			$config['type'] = '';
		}
		if ( ! array_key_exists( $config['custom_subtype'], Nera_SL_Settings::custom_subtypes() ) ) {
			$config['custom_subtype'] = '';
		}

		if ( isset( $raw['custom_periods'] ) && is_array( $raw['custom_periods'] ) && 'custom' === $config['type'] && $config['custom_subtype'] ) {
			foreach ( $raw['custom_periods'] as $token ) {
				$token = (string) $token;
				if ( self::token_bounds( $config['custom_subtype'], $token ) ) {
					$config['custom_periods'][] = $token;
				}
			}
			$config['custom_periods'] = array_values( array_unique( $config['custom_periods'] ) );
		}

		return $config;
	}

	/**
	 * Validate raw (untrusted) input against the CMS configuration.
	 *
	 * @param array $input Raw input.
	 * @return array{ok:bool,config?:array,error?:string}
	 */
	public static function sanitize_input( array $input ) {
		$enabled = ! empty( $input['enabled'] ) && '0' !== (string) $input['enabled'];

		$enabled_types = Nera_SL_Settings::enabled_types();
		$max           = Nera_SL_Settings::max_limit();

		// Parse the provided values best-effort (kept even when disabled, so the
		// form round-trips and toggling back on restores the customer's choices).
		$type   = isset( $input['type'] ) ? (string) $input['type'] : '';
		$amount = isset( $input['amount'] ) ? (float) $input['amount'] : 0.0;
		if ( $amount < 0 ) {
			$amount = 0.0;
		}
		if ( $amount > $max ) {
			$amount = $max; // Clamp to the configured maximum.
		}

		$sub     = isset( $input['custom_subtype'] ) ? (string) $input['custom_subtype'] : '';
		$periods = isset( $input['custom_periods'] ) && is_array( $input['custom_periods'] ) ? $input['custom_periods'] : array();
		$clean   = array();
		if ( array_key_exists( $sub, Nera_SL_Settings::custom_subtypes() ) ) {
			foreach ( $periods as $token ) {
				$token = (string) $token;
				if ( self::token_bounds( $sub, $token ) ) {
					$clean[] = $token;
				}
			}
			$clean = array_values( array_unique( $clean ) );
		} else {
			$sub = '';
		}

		$config = array(
			'enabled'        => $enabled,
			'amount'         => $amount,
			'type'           => in_array( $type, $enabled_types, true ) ? $type : '',
			'custom_subtype' => 'custom' === $type ? $sub : '',
			'custom_periods' => 'custom' === $type ? $clean : array(),
			'updated_at'     => time(),
		);

		// When the feature is turned off, accept it as-is (deactivation/removal).
		if ( ! $enabled ) {
			return array(
				'ok'      => true,
				'config'  => $config,
				'cleared' => true,
			);
		}

		// Enabled: validate the active configuration.
		if ( ! in_array( $type, $enabled_types, true ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Please choose a valid limit type.', 'nera-spending-limit' ),
			);
		}
		if ( $amount < 1 ) {
			return array(
				'ok'    => false,
				'error' => __( 'Please set an amount of at least 1.', 'nera-spending-limit' ),
			);
		}
		if ( 'custom' === $type && '' === $sub ) {
			return array(
				'ok'    => false,
				'error' => __( 'Please choose a valid custom period type.', 'nera-spending-limit' ),
			);
		}

		// Custom with no periods is allowed: it simply leaves no active limit
		// (the customer has removed all their dated limits).
		return array(
			'ok'      => true,
			'config'  => $config,
			'cleared' => ( 'custom' === $type && empty( $clean ) ),
		);
	}

	/**
	 * Persist a (already-sanitized) config for a user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $config  Sanitized config.
	 * @return void
	 */
	public static function save_config( $user_id, array $config ) {
		update_user_meta( (int) $user_id, self::META_KEY, $config );
	}

	/**
	 * Sum the user's paid order totals within [$start, $end).
	 *
	 * @param int $user_id User ID.
	 * @param int $start   Window start (unix ts).
	 * @param int $end     Window end (unix ts, exclusive).
	 * @return float
	 */
	public static function get_spent_in_window( $user_id, $start, $end ) {
		if ( ! function_exists( 'wc_get_orders' ) || $user_id < 1 ) {
			return 0.0;
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => (int) $user_id,
				'status'      => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
				'date_created' => ( (int) $start ) . '...' . ( (int) $end - 1 ),
				'limit'       => -1,
				'return'      => 'objects',
			)
		);

		$total = 0.0;
		foreach ( $orders as $order ) {
			$total += (float) $order->get_total();
		}

		/**
		 * Filter the computed spent amount for a window.
		 *
		 * @param float $total   Spent total.
		 * @param int   $user_id User ID.
		 * @param int   $start   Window start.
		 * @param int   $end     Window end.
		 */
		return (float) apply_filters( 'nera_sl_spent_in_window', $total, $user_id, $start, $end );
	}

	/**
	 * Full evaluation for a user at checkout.
	 *
	 * @param int      $user_id    User ID.
	 * @param float    $cart_total Current cart/order total.
	 * @param int|null $ref_ts     Reference timestamp (defaults to now).
	 * @return array Evaluation result merged with window info; always contains
	 *               keys 'has_limit' and 'state'.
	 */
	public static function evaluate_for_user( $user_id, $cart_total, $ref_ts = null ) {
		$ref_ts = null === $ref_ts ? time() : (int) $ref_ts;
		$config = self::get_config( $user_id );
		$window = self::active_window( $config, $ref_ts );

		$wallet_active  = Nera_SL_Wallet::is_active();
		$wallet_balance = $wallet_active ? Nera_SL_Wallet::get_balance( $user_id ) : 0.0;

		if ( null === $window ) {
			// No applicable limit for this order.
			$result               = self::evaluate( null, 0.0, $cart_total, $wallet_active, $wallet_balance );
			$result['has_limit']  = false;
			$result['config']     = $config;
			$result['window']     = null;
			$result['spent_before'] = 0.0;
			return $result;
		}

		$spent  = self::get_spent_in_window( $user_id, $window['start'], $window['end'] );
		$result = self::evaluate( $window['limit'], $spent, $cart_total, $wallet_active, $wallet_balance );

		$result['has_limit'] = true;
		$result['config']    = $config;
		$result['window']    = $window;
		return $result;
	}

	/**
	 * Human label for a config's recurring type or custom subtype.
	 *
	 * @param array $config Config.
	 * @return string
	 */
	public static function type_label( array $config ) {
		if ( 'custom' === $config['type'] ) {
			$subs = Nera_SL_Settings::custom_subtypes();
			$sub  = isset( $subs[ $config['custom_subtype'] ] ) ? $subs[ $config['custom_subtype'] ] : '';
			/* translators: %s: custom sub-period label (Day/Week/Month/Year). */
			return $sub ? sprintf( __( 'Custom (%s)', 'nera-spending-limit' ), $sub ) : __( 'Custom', 'nera-spending-limit' );
		}
		$types = Nera_SL_Settings::all_types();
		return isset( $types[ $config['type'] ] ) ? $types[ $config['type'] ] : '';
	}
}
