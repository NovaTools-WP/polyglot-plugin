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

		// URL Strategy & Hide Default Prefix
		$clean['url_strategy']                 = $this->sanitizeUrlStrategy( $input['url_strategy'] ?? array() );
		$clean['hide_default_language_prefix'] = $clean['url_strategy']['hide_default_prefix'];

		// Browser redirect setting (handles boolean from React or legacy array/object)
		$clean['browser_redirect']          = $this->sanitizeBrowserRedirect( $input['browser_redirect'] ?? ( $input['browser_language_redirect'] ?? false ) );
		$clean['browser_language_redirect'] = $clean['browser_redirect'];

		// API Settings (Map translation_api to api for providers, and also store translation_api for React settings page)
		$clean['api'] = $this->sanitizeApiKeys( $input['api'] ?? array() );
		if ( isset( $input['translation_api'] ) && is_array( $input['translation_api'] ) ) {
			$transApi = $input['translation_api'];
			if ( isset( $transApi['provider'] ) ) {
				$clean['api']['default_provider'] = sanitize_text_field( $transApi['provider'] );
			}
			if ( isset( $transApi['deepl_key'] ) ) {
				$clean['api']['deepl']['key'] = sanitize_text_field( $transApi['deepl_key'] );
			}
			if ( isset( $transApi['google_key'] ) ) {
				$clean['api']['google']['key'] = sanitize_text_field( $transApi['google_key'] );
			}
			if ( isset( $transApi['openai_key'] ) ) {
				$clean['api']['openai']['key'] = sanitize_text_field( $transApi['openai_key'] );
			}
		}

		// Also save translation_api for the frontend to read back on page load
		$clean['translation_api'] = array(
			'provider'   => $clean['api']['default_provider'] ?? '',
			'deepl_key'  => $clean['api']['deepl']['key'] ?? '',
			'google_key' => $clean['api']['google']['key'] ?? '',
			'openai_key' => $clean['api']['openai']['key'] ?? '',
		);

		// Other configuration lists and options
		$clean['custom_fields']   = $this->sanitizeCustomFields( $input['custom_fields'] ?? array() );
		$clean['post_types']      = $this->sanitizePostTypes( $input['post_types'] ?? array() );
		$clean['taxonomies']      = $this->sanitizeTaxonomies( $input['taxonomies'] ?? array() );
		$clean['media']           = $this->sanitizeMedia( $input['media'] ?? array() );
		$clean['woocommerce']     = $this->sanitizeWooCommerce( $input['woocommerce'] ?? array() );

		// Root-level options
		$clean['default_language']        = sanitize_text_field( $input['default_language'] ?? 'en' );
		$clean['auto_scan_on_activation'] = ! empty( $input['auto_scan_on_activation'] );

		/**
		 * Filter sanitized Polyglot settings before saving.
		 *
		 * @param array $clean  Sanitized settings.
		 * @param array $input  Original raw input.
		 */
		return apply_filters( 'polyglot_sanitize_settings', $clean, $input );
	}

	// ── Domain-specific sanitizers ───────────────────────────────────

	/**
	 * Sanitize URL strategy settings.
	 *
	 * @param array $input Raw URL strategy input.
	 * @return array Sanitized URL strategy settings.
	 */
	private function sanitizeUrlStrategy( array $input ): array {
		$clean = array();

		$clean['method'] = in_array(
			$input['method'] ?? '',
			array( 'directory', 'subdomain', 'domain', 'query_param' ),
			true
		) ? $input['method'] : 'directory';

		// Support both hide_default_prefix (frontend) and hide_default (legacy backend)
		$hide_val = ! empty( $input['hide_default_prefix'] ) || ! empty( $input['hide_default'] );
		$clean['hide_default_prefix'] = $hide_val;
		$clean['hide_default']        = $hide_val;

		if ( isset( $input['domain_mapping'] ) && is_array( $input['domain_mapping'] ) ) {
			foreach ( $input['domain_mapping'] as $code => $domain ) {
				$clean['domain_mapping'][ sanitize_text_field( $code ) ] = sanitize_text_field( $domain );
			}
		}

		return $clean;
	}

	/**
	 * Sanitize browser redirect settings.
	 *
	 * @param mixed $input Raw browser redirect input.
	 * @return bool Sanitized browser redirect settings.
	 */
	private function sanitizeBrowserRedirect( mixed $input ): bool {
		if ( is_array( $input ) ) {
			return ! empty( $input['enabled'] );
		}
		return (bool) $input;
	}

	/**
	 * Sanitize API key settings.
	 *
	 * @param array $input Raw API input.
	 * @return array Sanitized API settings.
	 */
	private function sanitizeApiKeys( array $input ): array {
		$clean = array();

		foreach ( array( 'deepl', 'google', 'openai' ) as $provider ) {
			$clean[ $provider ] = array();
			if ( isset( $input[ $provider ] ) && is_array( $input[ $provider ] ) ) {
				foreach ( $input[ $provider ] as $sub_key => $val ) {
					$clean[ $provider ][ sanitize_text_field( $sub_key ) ] = sanitize_text_field( $val );
				}
			}
			// Ensure 'key' exists at least as an empty string if not set
			if ( ! isset( $clean[ $provider ]['key'] ) ) {
				$clean[ $provider ]['key'] = '';
			}
		}

		if ( isset( $input['default_provider'] ) ) {
			$clean['default_provider'] = sanitize_text_field( $input['default_provider'] );
		}

		return $clean;
	}

	/**
	 * Sanitize custom field translation modes.
	 *
	 * @param array $input Raw custom fields input.
	 * @return array Sanitized custom field settings.
	 */
	private function sanitizeCustomFields( array $input ): array {
		$clean = array();

		if ( is_array( $input ) ) {
			foreach ( $input as $field_key => $mode ) {
				$clean[ sanitize_text_field( $field_key ) ] = in_array(
					$mode,
					array( 'copy', 'translate', 'ignore' ),
					true
				) ? $mode : 'copy';
			}
		}

		return $clean;
	}

	/**
	 * Sanitize post type settings.
	 *
	 * @param array $input Raw post types input.
	 * @return array Sanitized post types.
	 */
	private function sanitizePostTypes( array $input ): array {
		return is_array( $input ) ? array_map( 'sanitize_text_field', $input ) : array();
	}

	/**
	 * Sanitize taxonomy settings.
	 *
	 * @param array $input Raw taxonomies input.
	 * @return array Sanitized taxonomies.
	 */
	private function sanitizeTaxonomies( array $input ): array {
		return is_array( $input ) ? array_map( 'sanitize_text_field', $input ) : array();
	}

	/**
	 * Sanitize media settings.
	 *
	 * @param array $input Raw media input.
	 * @return array Sanitized media settings.
	 */
	private function sanitizeMedia( array $input ): array {
		return array(
			'duplicate_on_upload'    => ! empty( $input['duplicate_on_upload'] ),
			'translate_alt_text'     => ! empty( $input['translate_alt_text'] ),
			'translate_captions'     => ! empty( $input['translate_captions'] ),
			'translate_descriptions' => ! empty( $input['translate_descriptions'] ),
		);
	}

	/**
	 * Sanitize WooCommerce multi-currency settings.
	 *
	 * @param array $input Raw WooCommerce input.
	 * @return array Sanitized WooCommerce settings.
	 */
	private function sanitizeWooCommerce( array $input ): array {
		$clean = array();

		$clean['multi_currency']['enabled'] = ! empty( $input['multi_currency']['enabled'] );
		$clean['multi_currency']['mode'] = in_array(
			$input['multi_currency']['mode'] ?? 'by_language',
			array( 'by_language', 'by_geolocation' ),
			true
		) ? $input['multi_currency']['mode'] : 'by_language';

		if ( isset( $input['multi_currency']['rates'] ) && is_array( $input['multi_currency']['rates'] ) ) {
			foreach ( $input['multi_currency']['rates'] as $currency => $rate ) {
				$clean['multi_currency']['rates'][ sanitize_text_field( $currency ) ] = floatval( $rate );
			}
		}

		return $clean;
	}
}
