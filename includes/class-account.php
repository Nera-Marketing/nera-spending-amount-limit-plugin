<?php
/**
 * Account Details: render the Spending Limit card and handle its AJAX save.
 *
 * @package Nera_Spending_Limit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SL_Account
 */
class Nera_SL_Account {

	/**
	 * Init.
	 */
	public static function init() {
		// Render at the top of the account form, above the Personal Information
		// card (woocommerce_edit_account_form_start fires right after the <form>
		// opens, below the page header). The card uses AJAX and carries no
		// <form>, so it is safe inside the account form.
		add_action( 'woocommerce_edit_account_form_start', array( __CLASS__, 'render_card' ) );
		add_action( 'wp_ajax_nera_sl_save', array( __CLASS__, 'ajax_save' ) );
	}

	/**
	 * Render the standalone card after the edit-account form.
	 */
	public static function render_card() {
		if ( ! Nera_SL_Settings::is_enabled() || ! is_user_logged_in() ) {
			return;
		}

		$config = Nera_SL_User_Limit::get_config( get_current_user_id() );
		?>
		<div class="nera-sl" id="nera-sl-root">
			<div class="bg-surface rounded-2xl border border-gray-100 shadow-sm p-6 mb-6">

				<!-- Header (with the enable switch docked to the right) -->
				<div class="flex items-center justify-between gap-4 mb-6 pb-4 border-b border-gray-200">
					<div class="flex items-center gap-3 min-w-0">
						<div class="w-10 h-10 bg-gradient-to-br from-primary to-primary rounded-lg flex items-center justify-center shrink-0">
							<span class="material-symbols-outlined text-white text-xl">savings</span>
						</div>
						<div class="min-w-0">
							<h3 class="text-xl font-bold text-gray-900"><?php esc_html_e( 'Spending limit', 'nera-spending-limit' ); ?></h3>
							<p class="text-sm text-gray-600"><?php esc_html_e( 'Set a voluntary cap on how much you spend.', 'nera-spending-limit' ); ?></p>
						</div>
					</div>
					<label class="nera-sl-switch shrink-0" for="nera-sl-enabled-toggle">
						<input type="checkbox" id="nera-sl-enabled-toggle" <?php checked( ! empty( $config['enabled'] ) ); ?> />
						<span class="nera-sl-switch-track"><span class="nera-sl-switch-thumb"></span></span>
					</label>
				</div>

				<!-- Status -->
				<div class="nera-sl-status mb-6" id="nera-sl-status">
					<?php echo self::status_html( $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<!-- Configuration fields (hidden when the feature is turned off) -->
				<div class="nera-sl-fields space-y-6 <?php echo empty( $config['enabled'] ) ? 'hidden' : ''; ?>">

					<!-- Amount -->
					<div class="form-row">
						<label for="nera-sl-amount" class="block text-sm font-semibold text-gray-700 mb-2">
							<?php esc_html_e( 'Amount limit', 'nera-spending-limit' ); ?>
						</label>
						<div class="nera-sl-amount-box">
							<span class="nera-sl-amount-prefix" aria-hidden="true"><?php echo esc_html( self::currency_symbol() ); ?></span>
							<input type="text" inputmode="decimal" id="nera-sl-amount" name="nera_sl_amount"
								autocomplete="off"
								value="<?php echo esc_attr( $config['amount'] > 0 ? $config['amount'] : '' ); ?>"
								placeholder="0"
								class="nera-sl-amount-input" />
						</div>
						<p class="text-xs text-gray-500 mt-2">
							<?php esc_html_e( 'Enter the maximum amount you want to spend in the selected period.', 'nera-spending-limit' ); ?>
						</p>
					</div>

					<!-- Type -->
					<div class="form-row" id="nera-sl-type-row">
						<label for="nera-sl-type" class="block text-sm font-semibold text-gray-700 mb-2">
							<?php esc_html_e( 'Limit type', 'nera-spending-limit' ); ?>
						</label>
						<div class="nera-sl-select-wrap">
							<span class="material-symbols-outlined nera-sl-select-icon" aria-hidden="true">tune</span>
							<select id="nera-sl-type" class="nera-sl-select"></select>
						</div>
					</div>

					<!-- Custom subtype (hidden unless type=custom) -->
					<div class="form-row nera-sl-custom-subtype hidden">
						<label for="nera-sl-subtype" class="block text-sm font-semibold text-gray-700 mb-2">
							<?php esc_html_e( 'Custom period', 'nera-spending-limit' ); ?>
						</label>
						<div class="nera-sl-select-wrap">
							<span class="material-symbols-outlined nera-sl-select-icon" aria-hidden="true">date_range</span>
							<select id="nera-sl-subtype" class="nera-sl-select"></select>
						</div>
					</div>

					<!-- Calendar (hidden unless type=custom) -->
					<div class="form-row nera-sl-custom-calendar hidden">
						<p class="text-sm text-gray-600 mb-3 nera-sl-calendar-hint"></p>
						<div class="nera-sl-calendar" id="nera-sl-calendar"></div>
						<div class="nera-sl-chips mt-4 flex flex-wrap gap-2" id="nera-sl-chips"></div>
					</div>

				</div><!-- /.nera-sl-fields -->

				<!-- Messages -->
				<div class="nera-sl-message hidden mt-6" id="nera-sl-message" role="status"></div>

				<!-- Save -->
				<div class="mt-6">
					<button type="button" id="nera-sl-save"
						class="woocommerce-Button button !inline-flex items-center justify-center gap-2 px-8 py-4 bg-gradient-to-r from-primary to-primary text-white font-semibold rounded-xl hover:opacity-90 transition-all shadow-sm hover:shadow-md w-full sm:w-auto">
						<span class="material-symbols-outlined text-xl">save</span>
						<?php esc_html_e( 'Save', 'nera-spending-limit' ); ?>
					</button>
				</div>

				<!-- Remove-period confirmation dialog -->
				<dialog id="nera-sl-confirm" class="nera-sl-dialog">
					<div class="nera-sl-dialog-inner">
						<div class="nera-sl-dialog-head">
							<span class="nera-sl-dialog-badge is-danger"><span class="material-symbols-outlined">delete</span></span>
							<h4 class="nera-sl-dialog-title nera-sl-confirm-title-text"></h4>
						</div>
						<div class="nera-sl-dialog-body"><p class="nera-sl-confirm-body"></p></div>
						<div class="nera-sl-dialog-actions">
							<button type="button" class="nera-sl-btn nera-sl-btn-ghost nera-sl-confirm-cancel"></button>
							<button type="button" class="nera-sl-btn nera-sl-btn-danger nera-sl-confirm-ok"></button>
						</div>
					</div>
				</dialog>

			</div>
		</div>
		<?php
	}

	/**
	 * Build the status block markup for a config (shared by initial render + AJAX).
	 *
	 * @param array $config Normalized config.
	 * @return string
	 */
	public static function status_html( array $config ) {
		// Helper for the neutral/info state.
		$info = static function ( $msg ) {
			return '<div class="nera-sl-status-empty flex items-center gap-2 text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3">'
				. '<span class="material-symbols-outlined text-gray-400">info</span>'
				. '<span>' . esc_html( $msg ) . '</span>'
				. '</div>';
		};

		// Feature turned off by the customer.
		if ( empty( $config['enabled'] ) ) {
			return $info( __( 'Your spending limit is turned off.', 'nera-spending-limit' ) );
		}

		if ( $config['amount'] <= 0 || '' === $config['type'] ) {
			return $info( __( 'You have not set a spending limit yet.', 'nera-spending-limit' ) );
		}

		// Custom type with no periods selected = enabled but nothing to enforce.
		if ( 'custom' === $config['type'] && empty( $config['custom_periods'] ) ) {
			return $info( __( 'Select one or more periods on the calendar to activate your limit.', 'nera-spending-limit' ) );
		}

		$amount = self::format_amount( $config['amount'] );
		$label  = Nera_SL_User_Limit::type_label( $config );

		if ( 'custom' === $config['type'] && $config['custom_subtype'] && ! empty( $config['custom_periods'] ) ) {
			$count = count( $config['custom_periods'] );
			/* translators: 1: amount, 2: period label (Day/Week/Month/Year), 3: count. */
			$text = sprintf(
				_n(
					'Your limit is %1$s per selected %2$s — %3$d period configured.',
					'Your limit is %1$s per selected %2$s — %3$d periods configured.',
					$count,
					'nera-spending-limit'
				),
				$amount,
				strtolower( Nera_SL_Settings::custom_subtypes()[ $config['custom_subtype'] ] ),
				$count
			);
		} else {
			/* translators: 1: amount, 2: type label. */
			$text = sprintf( __( 'Your limit is %1$s (%2$s).', 'nera-spending-limit' ), $amount, $label );
		}

		return '<div class="nera-sl-status-set flex items-center gap-2 text-sm font-medium text-success bg-success/10 border border-success/20 rounded-xl px-4 py-3">'
			. '<span class="material-symbols-outlined">check_circle</span>'
			. '<span>' . esc_html( $text ) . '</span>'
			. '</div>';
	}

	/**
	 * Store currency symbol as a plain string (entities decoded).
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
	 * Format an amount with the store currency.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	protected static function format_amount( $amount ) {
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $amount ) );
		}
		return number_format_i18n( $amount, 2 );
	}

	/**
	 * AJAX: save the user's spending limit.
	 */
	public static function ajax_save() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'nera-spending-limit' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'nera_sl_save', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'nera-spending-limit' ) ), 400 );
		}
		if ( ! Nera_SL_Settings::is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'This feature is not available.', 'nera-spending-limit' ) ), 400 );
		}

		$input = array(
			'enabled'        => isset( $_POST['enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) : '0',
			'amount'         => isset( $_POST['amount'] ) ? wc_clean( wp_unslash( $_POST['amount'] ) ) : '',
			'type'           => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '',
			'custom_subtype' => isset( $_POST['custom_subtype'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_subtype'] ) ) : '',
			'custom_periods' => isset( $_POST['custom_periods'] ) && is_array( $_POST['custom_periods'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['custom_periods'] ) )
				: array(),
		);

		$result = Nera_SL_User_Limit::sanitize_input( $input );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ), 422 );
		}

		Nera_SL_User_Limit::save_config( get_current_user_id(), $result['config'] );

		if ( empty( $result['config']['enabled'] ) ) {
			$message = __( 'Your spending limit has been turned off.', 'nera-spending-limit' );
		} elseif ( ! empty( $result['cleared'] ) ) {
			$message = __( 'Your spending limit has been removed. Select periods to set a new one.', 'nera-spending-limit' );
		} else {
			$message = __( 'Your spending limit has been saved.', 'nera-spending-limit' );
		}

		wp_send_json_success(
			array(
				'message'    => $message,
				'statusHtml' => self::status_html( $result['config'] ),
			)
		);
	}
}
