<?php
/**
 * Admin menu registrar for NovaTools Polyglot.
 *
 * Handles the menu registration pattern:
 *   - NovaTools integrated: registers admin settings.
 *
 * @package NovaTools\Polyglot\Admin
 */

namespace NovaTools\Polyglot\Admin;

use NovaTools\Polyglot\Compatibility\DependencyCheck;
use NovaTools\Polyglot\Core\Plugin;

defined( 'ABSPATH' ) || exit;

class MenuRegistrar {

	/**
	 * Plugin instance for service resolution.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin The core plugin singleton.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register hooks based on the current mode.
	 *
	 * When NovaTools core is active, registers admin settings for the REST API.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( DependencyCheck::is_novatools_active() ) {
			add_action( 'admin_init', array( $this, 'registerSettings' ) );
		}
	}

	/**
	 * Register WordPress settings for the polyglot_settings option.
	 *
	 * Uses the Settings API so that standalone pages can leverage
	 * settings_fields() / do_settings_sections().
	 *
	 * @return void
	 */
	public function registerSettings(): void {
		register_setting(
			'polyglot_settings_group',
			'polyglot_settings',
			array( $this, 'sanitizeSettings' )
		);
	}

	/**
	 * Sanitize settings before saving.
	 *
	 * @param array $input Raw input from the settings form.
	 * @return array Sanitized settings.
	 */
	public function sanitizeSettings( array $input ): array {
		$clean = array();

		// URL strategy.
		if ( isset( $input['url_strategy'] ) ) {
			$clean['url_strategy']['method'] = in_array(
				$input['url_strategy']['method'] ?? '',
				array( 'directory', 'subdomain', 'domain', 'query_param' ),
				true
			) ? $input['url_strategy']['method'] : 'directory';

			$clean['url_strategy']['hide_default'] = ! empty( $input['url_strategy']['hide_default'] );

			if ( isset( $input['url_strategy']['domain_mapping'] ) && is_array( $input['url_strategy']['domain_mapping'] ) ) {
				foreach ( $input['url_strategy']['domain_mapping'] as $code => $domain ) {
					$clean['url_strategy']['domain_mapping'][ sanitize_text_field( $code ) ] = sanitize_text_field( $domain );
				}
			}
		}

		// Browser redirect.
		$clean['browser_redirect']['enabled'] = ! empty( $input['browser_redirect']['enabled'] );

		// API keys.
		foreach ( array( 'deepl', 'google', 'openai' ) as $provider ) {
			$key = $input['api'][ $provider ]['key'] ?? '';
			$clean['api'][ $provider ]['key'] = sanitize_text_field( $key );
		}

		if ( isset( $input['api']['default_provider'] ) ) {
			$clean['api']['default_provider'] = sanitize_text_field( $input['api']['default_provider'] );
		}

		// Custom field translation modes.
		if ( isset( $input['custom_fields'] ) && is_array( $input['custom_fields'] ) ) {
			foreach ( $input['custom_fields'] as $field_key => $mode ) {
				$clean['custom_fields'][ sanitize_text_field( $field_key ) ] = in_array(
					$mode,
					array( 'copy', 'translate', 'ignore' ),
					true
				) ? $mode : 'copy';
			}
		}

		// Post types.
		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$clean['post_types'] = array_map( 'sanitize_text_field', $input['post_types'] );
		}

		// Taxonomies.
		if ( isset( $input['taxonomies'] ) && is_array( $input['taxonomies'] ) ) {
			$clean['taxonomies'] = array_map( 'sanitize_text_field', $input['taxonomies'] );
		}

		// Media.
		$clean['media']['duplicate_on_upload'] = ! empty( $input['media']['duplicate_on_upload'] );

		// WooCommerce multi-currency.
		$clean['woocommerce']['multi_currency']['enabled'] = ! empty( $input['woocommerce']['multi_currency']['enabled'] );
		$clean['woocommerce']['multi_currency']['mode'] = in_array(
			$input['woocommerce']['multi_currency']['mode'] ?? 'by_language',
			array( 'by_language', 'by_geolocation' ),
			true
		) ? $input['woocommerce']['multi_currency']['mode'] : 'by_language';

		if ( isset( $input['woocommerce']['multi_currency']['rates'] ) && is_array( $input['woocommerce']['multi_currency']['rates'] ) ) {
			foreach ( $input['woocommerce']['multi_currency']['rates'] as $currency => $rate ) {
				$clean['woocommerce']['multi_currency']['rates'][ sanitize_text_field( $currency ) ] = floatval( $rate );
			}
		}

		/**
		 * Filter sanitized Polyglot settings before saving.
		 *
		 * @param array $clean  Sanitized settings.
		 * @param array $input  Original raw input.
		 */
		return apply_filters( 'polyglot_sanitize_settings', $clean, $input );
	}
}
