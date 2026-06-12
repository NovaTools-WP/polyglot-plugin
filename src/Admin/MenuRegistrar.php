<?php
/**
 * Admin menu registrar for NovaTools Polyglot.
 *
 * Handles the dual-mode menu registration pattern:
 *   - NovaTools integrated: adds Polyglot submenu under the NovaTools parent menu.
 *   - Standalone: registers a top-level "Polyglot" menu with subpages when
 *     NovaTools core is not active.
 *
 * All admin page classes are resolved from the DI container so they receive
 * their dependencies via constructor injection.
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
	 * When NovaTools core is active, menu integration happens through the
	 * filter hooks in the main plugin class. This method handles the
	 * standalone fallback.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( DependencyCheck::is_novatools_active() ) {
			// NovaTools handles menu rendering; register admin pages for
			// REST API / AJAX endpoints used by the React components.
			add_action( 'admin_init', array( $this, 'registerSettings' ) );
			return;
		}

		// Standalone mode: register traditional WordPress admin menus.
		add_action( 'admin_menu', array( $this, 'registerStandaloneMenu' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
	}

	/**
	 * Register standalone WordPress admin menus.
	 *
	 * Creates a top-level "Polyglot" menu with subpages for each section:
	 * Dashboard, Languages, Translations, String Translation, Theme/Plugin
	 * Files, Settings, and Import from WPML.
	 *
	 * @return void
	 */
	public function registerStandaloneMenu(): void {
		$capability = 'manage_options';
		$slug       = 'novatools-polyglot';

		add_menu_page(
			__( 'Polyglot', 'novatools-polyglot' ),
			__( 'Polyglot', 'novatools-polyglot' ),
			$capability,
			$slug,
			array( $this, 'renderDashboard' ),
			'dashicons-translation',
			57
		);

		add_submenu_page(
			$slug,
			__( 'Dashboard', 'novatools-polyglot' ),
			__( 'Dashboard', 'novatools-polyglot' ),
			$capability,
			$slug,
			array( $this, 'renderDashboard' )
		);

		add_submenu_page(
			$slug,
			__( 'Languages', 'novatools-polyglot' ),
			__( 'Languages', 'novatools-polyglot' ),
			$capability,
			$slug . '-languages',
			array( $this, 'renderLanguages' )
		);

		add_submenu_page(
			$slug,
			__( 'Translations', 'novatools-polyglot' ),
			__( 'Translations', 'novatools-polyglot' ),
			$capability,
			$slug . '-translations',
			array( $this, 'renderTranslations' )
		);

		add_submenu_page(
			$slug,
			__( 'String Translation', 'novatools-polyglot' ),
			__( 'String Translation', 'novatools-polyglot' ),
			$capability,
			$slug . '-strings',
			array( $this, 'renderStrings' )
		);

		add_submenu_page(
			$slug,
			__( 'Theme &amp; Plugin Translations', 'novatools-polyglot' ),
			__( 'Theme &amp; Plugin Files', 'novatools-polyglot' ),
			$capability,
			$slug . '-files',
			array( $this, 'renderFiles' )
		);

		add_submenu_page(
			$slug,
			__( 'Settings', 'novatools-polyglot' ),
			__( 'Settings', 'novatools-polyglot' ),
			$capability,
			$slug . '-settings',
			array( $this, 'renderSettings' )
		);

		add_submenu_page(
			$slug,
			__( 'Import from WPML', 'novatools-polyglot' ),
			__( 'Import from WPML', 'novatools-polyglot' ),
			$capability,
			$slug . '-import-wpml',
			array( $this, 'renderImportWpml' )
		);
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

	/**
	 * Render the Dashboard page.
	 *
	 * @return void
	 */
	public function renderDashboard(): void {
		$page = new DashboardPage( $this->plugin );
		$page->render();
	}

	/**
	 * Render the Languages page.
	 *
	 * @return void
	 */
	public function renderLanguages(): void {
		$page = new LanguageSettingsPage( $this->plugin );
		$page->render();
	}

	/**
	 * Render the Translations page.
	 *
	 * @return void
	 */
	public function renderTranslations(): void {
		$page = new TranslationEditorPage( $this->plugin );
		$page->render();
	}

	/**
	 * Render the String Translation page.
	 *
	 * @return void
	 */
	public function renderStrings(): void {
		$page = new TranslationEditorPage( $this->plugin );
		$page->render( 'strings' );
	}

	/**
	 * Render the Theme & Plugin Files page.
	 *
	 * @return void
	 */
	public function renderFiles(): void {
		$page = new ThemePluginPage( $this->plugin );
		$page->render();
	}

	/**
	 * Render the Settings page.
	 *
	 * @return void
	 */
	public function renderSettings(): void {
		$page = new SettingsPage( $this->plugin );
		$page->render();
	}

	/**
	 * Render the Import from WPML page.
	 *
	 * @return void
	 */
	public function renderImportWpml(): void {
		$page = new ImportWpmlPage( $this->plugin );
		$page->render();
	}

	/**
	 * Get the admin page URLs for standalone mode.
	 *
	 * Useful for building navigation links in templates.
	 *
	 * @return array Associative array of page slug => URL.
	 */
	public function getPageUrls(): array {
		$base = 'novatools-polyglot';

		return array(
			'dashboard'    => admin_url( "admin.php?page={$base}" ),
			'languages'    => admin_url( "admin.php?page={$base}-languages" ),
			'translations' => admin_url( "admin.php?page={$base}-translations" ),
			'strings'      => admin_url( "admin.php?page={$base}-strings" ),
			'files'        => admin_url( "admin.php?page={$base}-files" ),
			'settings'     => admin_url( "admin.php?page={$base}-settings" ),
			'import_wpml'  => admin_url( "admin.php?page={$base}-import-wpml" ),
		);
	}
}
