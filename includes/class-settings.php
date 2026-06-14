<?php
/**
 * Admin settings: "Nera Features" options sub-page under Theme Settings, with the
 * "Spend Limit" section, plus typed getters with safe fallbacks.
 *
 * @package Nera_Spending_Limit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SL_Settings
 */
class Nera_SL_Settings {

	const OPTIONS_SLUG    = 'nera-features';
	const OPTIONS_POST_ID = 'nera-features'; // ACF "post_id" for get_field() on this options page.
	const FIELD_GROUP_KEY = 'group_nera_sl_spend_limit';

	const DEFAULT_MAX_LIMIT = 10000;
	const DEFAULT_TYPE      = 'monthly';

	const DEFAULT_OVER_LIMIT_MESSAGE = 'This order will take you over the spending limit you set. Do you want to continue anyway?';

	/**
	 * All limit types the feature understands.
	 *
	 * @return array<string,string> slug => label
	 */
	public static function all_types() {
		return array(
			'daily'   => __( 'Daily', 'nera-spending-limit' ),
			'weekly'  => __( 'Weekly', 'nera-spending-limit' ),
			'monthly' => __( 'Monthly', 'nera-spending-limit' ),
			'yearly'  => __( 'Yearly', 'nera-spending-limit' ),
			'custom'  => __( 'Custom', 'nera-spending-limit' ),
		);
	}

	/**
	 * Custom sub-types.
	 *
	 * @return array<string,string>
	 */
	public static function custom_subtypes() {
		return array(
			'day'   => __( 'Day', 'nera-spending-limit' ),
			'week'  => __( 'Week', 'nera-spending-limit' ),
			'month' => __( 'Month', 'nera-spending-limit' ),
			'year'  => __( 'Year', 'nera-spending-limit' ),
		);
	}

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'acf/init', array( __CLASS__, 'register_options_page' ) );
		add_action( 'acf/init', array( __CLASS__, 'register_fields' ) );
	}

	/**
	 * Register the "Nera Features" sub-page beneath the theme's "Theme Settings" parent.
	 * Mirrors the idiom in the theme's inc/acf/*.php files.
	 */
	public static function register_options_page() {
		if ( ! function_exists( 'acf_add_options_sub_page' ) ) {
			return;
		}

		// Ensure the shared Theme Settings parent exists (the theme normally creates it).
		if ( function_exists( 'acf_add_options_page' ) &&
			( ! function_exists( 'acf_get_options_page' ) || ! acf_get_options_page( 'theme-settings' ) ) ) {
			acf_add_options_page(
				array(
					'page_title' => 'Theme Settings',
					'menu_title' => 'Theme Settings',
					'menu_slug'  => 'theme-settings',
					'capability' => 'edit_posts',
					'redirect'   => false,
				)
			);
		}

		acf_add_options_sub_page(
			array(
				'page_title'  => __( 'Nera Features', 'nera-spending-limit' ),
				'menu_title'  => __( 'Nera Features', 'nera-spending-limit' ),
				'menu_slug'   => self::OPTIONS_SLUG,
				'parent_slug' => 'theme-settings',
				'capability'  => 'manage_options',
				'post_id'     => self::OPTIONS_POST_ID,
			)
		);
	}

	/**
	 * Register the Spend Limit field group.
	 */
	public static function register_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		$type_choices = array();
		foreach ( self::all_types() as $slug => $label ) {
			$type_choices[ $slug ] = $label;
		}

		acf_add_local_field_group(
			array(
				'key'      => self::FIELD_GROUP_KEY,
				'title'    => __( 'Spend Limit', 'nera-spending-limit' ),
				'fields'   => array(
					array(
						'key'           => 'field_nera_sl_section',
						'label'         => __( 'Spend Limit', 'nera-spending-limit' ),
						'name'          => '',
						'type'          => 'message',
						'message'       => __( 'Configure the customer spending-limit feature. When enabled, logged-in customers can set their own limit on the Account Details page, and the limit is enforced at checkout.', 'nera-spending-limit' ),
						'new_lines'     => 'wpautop',
						'esc_html'      => 0,
					),
					array(
						'key'           => 'field_nera_sl_enabled',
						'label'         => __( 'Enable Spend Limit', 'nera-spending-limit' ),
						'name'          => 'nera_sl_enabled',
						'type'          => 'true_false',
						'instructions'  => __( 'Master switch. When off, the feature is hidden on the frontend and not enforced.', 'nera-spending-limit' ),
						'ui'            => 1,
						'ui_on_text'    => __( 'Yes', 'nera-spending-limit' ),
						'ui_off_text'   => __( 'No', 'nera-spending-limit' ),
						'default_value' => 0,
						'wrapper'       => array( 'width' => '100' ),
					),
					array(
						'key'               => 'field_nera_sl_limit_types',
						'label'             => __( 'Limit Types', 'nera-spending-limit' ),
						'name'              => 'nera_sl_limit_types',
						'type'              => 'select',
						'instructions'      => __( 'Which limit types customers may choose on the frontend.', 'nera-spending-limit' ),
						'choices'           => $type_choices,
						'multiple'          => 1,
						'ui'                => 1,
						'allow_null'        => 0,
						'return_format'     => 'value',
						'default_value'     => array( 'daily', 'weekly', 'monthly', 'yearly', 'custom' ),
						'conditional_logic' => array(
							array(
								array(
									'field'    => 'field_nera_sl_enabled',
									'operator' => '==',
									'value'    => '1',
								),
							),
						),
						'wrapper'           => array( 'width' => '50' ),
					),
					array(
						'key'               => 'field_nera_sl_default_type',
						'label'             => __( 'Default Limit Type', 'nera-spending-limit' ),
						'name'              => 'nera_sl_default_type',
						'type'              => 'select',
						'instructions'      => __( 'Pre-selected type on the frontend. Must be one of the enabled Limit Types.', 'nera-spending-limit' ),
						'choices'           => $type_choices,
						'multiple'          => 0,
						'ui'                => 1,
						'allow_null'        => 0,
						'return_format'     => 'value',
						'default_value'     => self::DEFAULT_TYPE,
						'conditional_logic' => array(
							array(
								array(
									'field'    => 'field_nera_sl_enabled',
									'operator' => '==',
									'value'    => '1',
								),
							),
						),
						'wrapper'           => array( 'width' => '50' ),
					),
					array(
						'key'               => 'field_nera_sl_max_limit',
						'label'             => __( 'Max Limit Amount', 'nera-spending-limit' ),
						'name'              => 'nera_sl_max_limit',
						'type'              => 'number',
						'instructions'      => __( 'Maximum value customers can set. Also used as the slider maximum when no wallet plugin is active.', 'nera-spending-limit' ),
						'default_value'     => self::DEFAULT_MAX_LIMIT,
						'min'               => 1,
						'step'              => 1,
						'conditional_logic' => array(
							array(
								array(
									'field'    => 'field_nera_sl_enabled',
									'operator' => '==',
									'value'    => '1',
								),
							),
						),
						'wrapper'           => array( 'width' => '50' ),
					),
					array(
						'key'               => 'field_nera_sl_over_limit_message',
						'label'             => __( 'Over-limit Confirmation Message', 'nera-spending-limit' ),
						'name'              => 'nera_sl_over_limit_message',
						'type'              => 'textarea',
						'instructions'      => __( 'Shown in the confirmation dialog at checkout when an order would exceed the customer\'s spending limit. Available placeholders: {limit}, {spent}, {total}, {over}.', 'nera-spending-limit' ),
						'default_value'     => self::DEFAULT_OVER_LIMIT_MESSAGE,
						'rows'              => 3,
						'new_lines'         => '',
						'conditional_logic' => array(
							array(
								array(
									'field'    => 'field_nera_sl_enabled',
									'operator' => '==',
									'value'    => '1',
								),
							),
						),
						'wrapper'           => array( 'width' => '100' ),
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'options_page',
							'operator' => '==',
							'value'    => self::OPTIONS_SLUG,
						),
					),
				),
				'menu_order'            => 0,
				'position'              => 'normal',
				'style'                 => 'default',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'active'                => true,
				'description'           => '',
			)
		);
	}

	/**
	 * Read a field from this options page with a fallback (safe before ACF is present/saved).
	 *
	 * @param string $name     Field name.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	protected static function get( $name, $fallback ) {
		if ( ! function_exists( 'get_field' ) ) {
			return $fallback;
		}
		$value = get_field( $name, self::OPTIONS_POST_ID );
		if ( null === $value || '' === $value || false === $value ) {
			// true_false legitimately returns false; callers that need it handle separately.
			return $fallback;
		}
		return $value;
	}

	/**
	 * Whether the feature is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( ! function_exists( 'get_field' ) ) {
			return false;
		}
		return (bool) get_field( 'nera_sl_enabled', self::OPTIONS_POST_ID );
	}

	/**
	 * Enabled limit types (validated against known types). Never empty.
	 *
	 * @return string[]
	 */
	public static function enabled_types() {
		$raw   = self::get( 'nera_sl_limit_types', array_keys( self::all_types() ) );
		$raw   = is_array( $raw ) ? $raw : array( $raw );
		$valid = array_values( array_intersect( array_keys( self::all_types() ), $raw ) );
		if ( empty( $valid ) ) {
			$valid = array_keys( self::all_types() );
		}
		return $valid;
	}

	/**
	 * Default type for the frontend, guaranteed to be one of the enabled types.
	 *
	 * @return string
	 */
	public static function default_type() {
		$enabled = self::enabled_types();
		$default = (string) self::get( 'nera_sl_default_type', self::DEFAULT_TYPE );
		if ( ! in_array( $default, $enabled, true ) ) {
			$default = $enabled[0];
		}
		return $default;
	}

	/**
	 * Max limit amount (CMS), used as the slider max when no wallet.
	 *
	 * @return float
	 */
	public static function max_limit() {
		$max = (float) self::get( 'nera_sl_max_limit', self::DEFAULT_MAX_LIMIT );
		if ( $max < 1 ) {
			$max = self::DEFAULT_MAX_LIMIT;
		}
		return $max;
	}

	/**
	 * Over-limit confirmation message (raw, may contain {limit} {spent} {total} {over} placeholders).
	 *
	 * @return string
	 */
	public static function over_limit_message() {
		$msg = (string) self::get( 'nera_sl_over_limit_message', self::DEFAULT_OVER_LIMIT_MESSAGE );
		return '' !== trim( $msg ) ? $msg : self::DEFAULT_OVER_LIMIT_MESSAGE;
	}
}
