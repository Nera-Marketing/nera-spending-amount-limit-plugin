<?php
/**
 * Checkout: render the spending-limit status card, expose it as a refreshable
 * fragment, and enforce the limit server-side.
 *
 * @package Nera_Spending_Limit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SL_Checkout
 */
class Nera_SL_Checkout {

	const ACK_FIELD     = 'nera_sl_ack';
	const CARD_SELECTOR = '#nera-sl-checkout-card';

	/**
	 * Init.
	 */
	public static function init() {
		// Render the card + ack field inside the checkout form, just before the place-order button.
		add_action( 'woocommerce_checkout_before_terms_and_conditions', array( __CLASS__, 'render_card' ) );
		// Keep it fresh on every AJAX update_checkout (core checkout.js replaces matching selectors).
		add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'fragment' ) );
		// Authoritative server-side enforcement.
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
	}

	/**
	 * Current cart total (edit context, float).
	 *
	 * @return float
	 */
	protected static function cart_total() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}
		return (float) WC()->cart->get_total( 'edit' );
	}

	/**
	 * Echo the card wrapper (always present so the fragment has a target).
	 */
	public static function render_card() {
		if ( ! self::active() ) {
			return;
		}
		echo self::card_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Provide the card as an order-review fragment.
	 *
	 * @param array $fragments Existing fragments.
	 * @return array
	 */
	public static function fragment( $fragments ) {
		if ( self::active() ) {
			$fragments[ self::CARD_SELECTOR ] = self::card_html();
		}
		return $fragments;
	}

	/**
	 * Whether enforcement/UI should run for the current request.
	 *
	 * @return bool
	 */
	protected static function active() {
		return Nera_SL_Settings::is_enabled() && is_user_logged_in();
	}

	/**
	 * Build the status card HTML (outer node id matches CARD_SELECTOR).
	 *
	 * @return string
	 */
	public static function card_html() {
		$user_id = get_current_user_id();
		$eval    = Nera_SL_User_Limit::evaluate_for_user( $user_id, self::cart_total() );

		// Hidden ack field is always present so the form can submit it.
		$ack = '<input type="hidden" name="' . esc_attr( self::ACK_FIELD ) . '" value="0" class="nera-sl-ack" />';

		// No applicable limit → render an (almost) empty, inert container.
		if ( empty( $eval['has_limit'] ) ) {
			return '<div id="nera-sl-checkout-card" class="nera-sl-checkout" data-state="none">' . $ack . '</div>';
		}

		$state         = $eval['state'];
		$limit         = (float) $eval['window']['limit'];
		$spent         = (float) $eval['spent_before'];
		$cart          = (float) $eval['cart_total'];
		$remaining     = max( 0.0, $limit - $spent );
		$wallet_active = ! empty( $eval['wallet_active'] );
		$wallet_bal    = (float) $eval['wallet_balance'];

		$config      = $eval['config'];
		$type_label  = Nera_SL_User_Limit::type_label( $config );

		// Resolve the admin-configured confirmation message with live amounts.
		$confirm_msg = strtr(
			Nera_SL_Settings::over_limit_message(),
			array(
				'{limit}' => wp_strip_all_tags( wc_price( $limit ) ),
				'{spent}' => wp_strip_all_tags( wc_price( $spent ) ),
				'{total}' => wp_strip_all_tags( wc_price( $cart ) ),
				'{over}'  => wp_strip_all_tags( wc_price( max( 0.0, (float) $eval['over_amount'] ) ) ),
			)
		);

		// Tone per state.
		if ( 'ok' === $state ) {
			$tone_classes = 'border-success/30 bg-success/5';
			$icon         = 'verified_user';
			$icon_color   = 'text-success';
		} else {
			$tone_classes = 'border-danger-border bg-danger-bg/60';
			$icon         = 'warning';
			$icon_color   = 'text-danger';
		}

		ob_start();
		?>
		<div id="nera-sl-checkout-card"
			class="nera-sl-checkout rounded-xl border <?php echo esc_attr( $tone_classes ); ?> p-4 mb-4"
			data-state="<?php echo esc_attr( $state ); ?>"
			data-wallet-active="<?php echo $wallet_active ? '1' : '0'; ?>"
			data-confirm-msg="<?php echo esc_attr( $confirm_msg ); ?>">

			<?php echo $ack; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="flex items-start gap-3">
				<span class="material-symbols-outlined <?php echo esc_attr( $icon_color ); ?> text-xl"><?php echo esc_html( $icon ); ?></span>
				<div class="flex-1 min-w-0">
					<p class="text-sm font-semibold text-text-primary mb-2">
						<?php
						/* translators: %s: limit type label */
						printf( esc_html__( 'Spending limit (%s)', 'nera-spending-limit' ), esc_html( $type_label ) );
						?>
					</p>

					<ul class="text-xs text-text-secondary space-y-1">
						<li class="flex justify-between gap-4">
							<span><?php esc_html_e( 'Your limit', 'nera-spending-limit' ); ?></span>
							<span class="font-semibold text-text-primary"><?php echo wp_kses_post( wc_price( $limit ) ); ?></span>
						</li>
						<li class="flex justify-between gap-4">
							<span><?php esc_html_e( 'Spent this period', 'nera-spending-limit' ); ?></span>
							<span class="font-semibold text-text-primary"><?php echo wp_kses_post( wc_price( $spent ) ); ?></span>
						</li>
						<li class="flex justify-between gap-4">
							<span><?php esc_html_e( 'Remaining', 'nera-spending-limit' ); ?></span>
							<span class="font-semibold text-text-primary"><?php echo wp_kses_post( wc_price( $remaining ) ); ?></span>
						</li>
						<li class="flex justify-between gap-4">
							<span><?php esc_html_e( 'This order', 'nera-spending-limit' ); ?></span>
							<span class="font-semibold text-text-primary"><?php echo wp_kses_post( wc_price( $cart ) ); ?></span>
						</li>
						<?php if ( $wallet_active ) : ?>
							<li class="flex justify-between gap-4">
								<span><?php esc_html_e( 'Wallet balance', 'nera-spending-limit' ); ?></span>
								<span class="font-semibold text-text-primary"><?php echo wp_kses_post( wc_price( $wallet_bal ) ); ?></span>
							</li>
						<?php endif; ?>
					</ul>

					<?php if ( 'over_blocked' === $state ) : ?>
						<p class="mt-3 text-xs font-medium text-danger nera-sl-checkout-msg">
							<?php esc_html_e( 'This order exceeds your spending limit and your wallet balance does not cover it. Top up your wallet or reduce the order to continue.', 'nera-spending-limit' ); ?>
						</p>
					<?php elseif ( 'over_soft' === $state ) : ?>
						<p class="mt-3 text-xs font-medium text-danger nera-sl-checkout-msg">
							<?php esc_html_e( 'This order will take you over the spending limit you set. You can continue, but you will be asked to confirm.', 'nera-spending-limit' ); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Server-side enforcement.
	 *
	 * @param array     $data   Posted checkout data.
	 * @param WP_Error  $errors Errors object.
	 * @return void
	 */
	public static function validate( $data, $errors ) {
		unset( $data );

		if ( ! self::active() ) {
			return;
		}

		$eval = Nera_SL_User_Limit::evaluate_for_user( get_current_user_id(), self::cart_total() );

		if ( empty( $eval['has_limit'] ) || 'ok' === $eval['state'] ) {
			return;
		}

		if ( 'over_blocked' === $eval['state'] ) {
			$errors->add(
				'nera_sl_over_blocked',
				__( 'This order exceeds your spending limit and your wallet balance does not cover it. Please top up your wallet or reduce your order.', 'nera-spending-limit' )
			);
			return;
		}

		// over_soft — require explicit acknowledgement.
		$ack = isset( $_POST[ self::ACK_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::ACK_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- runs within WC's nonce-verified checkout.
		if ( '1' !== $ack ) {
			$errors->add(
				'nera_sl_over_soft',
				__( 'This order exceeds the spending limit you set. Please confirm you want to continue.', 'nera-spending-limit' )
			);
		}
	}
}
