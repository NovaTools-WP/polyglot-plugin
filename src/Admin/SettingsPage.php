<?php
/**
 * Settings admin page for NovaTools Polyglot.
 *
 * Provides a tabbed settings interface covering:
 *   - API Keys (DeepL, Google, OpenAI)
 *   - Custom Field translation configuration
 *   - Post Type & Taxonomy preferences
 *   - Media settings
 *   - WooCommerce multi-currency (when WooCommerce is active)
 *
 * @package NovaTools\Polyglot\Admin
 */

namespace NovaTools\Polyglot\Admin;

use NovaTools\Polyglot\Core\Plugin;
use NovaTools\Polyglot\Compatibility\DependencyCheck;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	use AdminPageTrait;

	/**
	 * Plugin instance for service resolution.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Active settings tab.
	 *
	 * @var string
	 */
	private string $activeTab;

	/**
	 * Available tabs and their labels.
	 *
	 * @var array<string, string>
	 */
	private array $tabs;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Core plugin singleton.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		$this->tabs = array(
			'api'          => __( 'Translation API', 'novatools-polyglot' ),
			'custom_fields' => __( 'Custom Fields', 'novatools-polyglot' ),
			'post_types'   => __( 'Post Types & Taxonomies', 'novatools-polyglot' ),
			'media'        => __( 'Media', 'novatools-polyglot' ),
		);

		// Add WooCommerce tab when WooCommerce is active.
		if ( class_exists( 'WooCommerce' ) ) {
			$this->tabs['woocommerce'] = __( 'WooCommerce', 'novatools-polyglot' );
		}

		$this->activeTab = sanitize_text_field( wp_unslash( $_GET['tab'] ?? 'api' ) );

		if ( ! isset( $this->tabs[ $this->activeTab ] ) ) {
			$this->activeTab = 'api';
		}
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'novatools-polyglot' ) );
		}

		$this->handleSave();
		$this->outputHeader();
		$this->outputTabs();
		$this->outputTabContent();
		$this->outputFooter();
	}

	/**
	 * Handle settings form submission.
	 *
	 * @return void
	 */
	private function handleSave(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['polyglot_action'] ?? '' ) );

		if ( 'save_settings' !== $action ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) );

		if ( ! wp_verify_nonce( $nonce, 'polyglot_save_settings_' . $this->activeTab ) ) {
			wp_die( esc_html__( 'Security check failed.', 'novatools-polyglot' ) );
		}

		if ( ! $this->plugin->has( 'options' ) ) {
			return;
		}

		$options = $this->plugin->get( 'options' );

		switch ( $this->activeTab ) {
			case 'api':
				$this->saveApiSettings( $options );
				break;
			case 'custom_fields':
				$this->saveCustomFieldSettings( $options );
				break;
			case 'post_types':
				$this->savePostTypeSettings( $options );
				break;
			case 'media':
				$this->saveMediaSettings( $options );
				break;
			case 'woocommerce':
				$this->saveWooCommerceSettings( $options );
				break;
		}

		// Flush provider cache when API keys change.
		if ( 'api' === $this->activeTab && $this->plugin->has( 'provider.registry' ) ) {
			$this->plugin->get( 'provider.registry' )->flush();
		}

		/**
		 * Fires after Polyglot settings have been saved.
		 *
		 * @param string $tab     The active settings tab.
		 * @param object $options The OptionStore instance.
		 */
		do_action( 'polyglot_settings_saved', $this->activeTab, $options );
	}

	/**
	 * Save translation API settings.
	 *
	 * @param object $options OptionStore instance.
	 * @return void
	 */
	private function saveApiSettings( object $options ): void {
		// DeepL.
		$options->set( 'api.deepl.key', sanitize_text_field( wp_unslash( $_POST['deepl_key'] ?? '' ) ) );
		$options->set( 'api.deepl.tier', in_array( $_POST['deepl_tier'] ?? '', array( 'free', 'pro' ), true )
			? sanitize_text_field( wp_unslash( $_POST['deepl_tier'] ) ) : 'free' );

		// Google Translate.
		$options->set( 'api.google.key', sanitize_text_field( wp_unslash( $_POST['google_key'] ?? '' ) ) );

		// OpenAI.
		$options->set( 'api.openai.key', sanitize_text_field( wp_unslash( $_POST['openai_key'] ?? '' ) ) );
		$options->set( 'api.openai.model', sanitize_text_field( wp_unslash( $_POST['openai_model'] ?? 'gpt-4o-mini' ) ) );

		// Default provider.
		$options->set( 'api.default_provider', sanitize_text_field( wp_unslash( $_POST['default_provider'] ?? '' ) ) );
	}

	/**
	 * Save custom field translation settings.
	 *
	 * @param object $options OptionStore instance.
	 * @return void
	 */
	private function saveCustomFieldSettings( object $options ): void {
		$fields_raw = wp_unslash( $_POST['custom_fields'] ?? array() );
		$fields     = array();

		if ( is_array( $fields_raw ) ) {
			foreach ( $fields_raw as $key => $mode ) {
				$key  = sanitize_text_field( $key );
				$mode = in_array( $mode, array( 'copy', 'translate', 'ignore' ), true ) ? $mode : 'copy';
				$fields[ $key ] = $mode;
			}
		}

		$options->set( 'custom_fields', $fields );
	}

	/**
	 * Save post type & taxonomy preferences.
	 *
	 * @param object $options OptionStore instance.
	 * @return void
	 */
	private function savePostTypeSettings( object $options ): void {
		$post_types_raw = wp_unslash( $_POST['post_types'] ?? array() );
		$post_types     = is_array( $post_types_raw )
			? array_map( 'sanitize_text_field', $post_types_raw )
			: array();

		$taxonomies_raw = wp_unslash( $_POST['taxonomies'] ?? array() );
		$taxonomies     = is_array( $taxonomies_raw )
			? array_map( 'sanitize_text_field', $taxonomies_raw )
			: array();

		$options->set( 'post_types', $post_types );
		$options->set( 'taxonomies', $taxonomies );
	}

	/**
	 * Save media settings.
	 *
	 * @param object $options OptionStore instance.
	 * @return void
	 */
	private function saveMediaSettings( object $options ): void {
		$options->set( 'media.duplicate_on_upload', ! empty( $_POST['media_duplicate_on_upload'] ) );
	}

	/**
	 * Save WooCommerce multi-currency settings.
	 *
	 * @param object $options OptionStore instance.
	 * @return void
	 */
	private function saveWooCommerceSettings( object $options ): void {
		$options->set( 'woocommerce.multi_currency.enabled', ! empty( $_POST['wc_multi_currency_enabled'] ) );

		$mode = in_array( $_POST['wc_currency_mode'] ?? '', array( 'by_language', 'by_geolocation' ), true )
			? sanitize_text_field( wp_unslash( $_POST['wc_currency_mode'] ) )
			: 'by_language';
		$options->set( 'woocommerce.multi_currency.mode', $mode );

		// Currency rate overrides.
		$rates_raw = wp_unslash( $_POST['wc_currency_rates'] ?? array() );
		$rates     = array();

		if ( is_array( $rates_raw ) ) {
			foreach ( $rates_raw as $currency => $rate ) {
				$rates[ sanitize_text_field( $currency ) ] = floatval( $rate );
			}
		}

		$options->set( 'woocommerce.multi_currency.rates', $rates );
	}

	// ─── Rendering ────────────────────────────────────────────────────────

	/**
	 * Output the page header.
	 *
	 * @return void
	 */
	private function outputHeader(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Polyglot Settings', 'novatools-polyglot' ) . '</h1>';
	}

	/**
	 * Output the page footer.
	 *
	 * @return void
	 */
	private function outputFooter(): void {
		echo '</div>';
	}

	/**
	 * Output the settings tab navigation.
	 *
	 * @return void
	 */
	private function outputTabs(): void {
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? 'novatools-polyglot-settings' ) );

		echo '<nav class="nav-tab-wrapper wp-clearfix">';
		foreach ( $this->tabs as $tab => $label ) {
			$url   = add_query_arg( array( 'page' => $page, 'tab' => $tab ), admin_url( 'admin.php' ) );
			$class = $tab === $this->activeTab ? 'nav-tab nav-tab-active' : 'nav-tab';
			printf( '<a href="%s" class="%s">%s</a>', esc_url( $url ), esc_attr( $class ), esc_html( $label ) );
		}
		echo '</nav>';
	}

	/**
	 * Output the content for the active tab.
	 *
	 * @return void
	 */
	private function outputTabContent(): void {
		echo '<form method="post">';
		wp_nonce_field( 'polyglot_save_settings_' . $this->activeTab );
		echo '<input type="hidden" name="polyglot_action" value="save_settings" />';

		switch ( $this->activeTab ) {
			case 'api':
				$this->outputApiTab();
				break;
			case 'custom_fields':
				$this->outputCustomFieldsTab();
				break;
			case 'post_types':
				$this->outputPostTypesTab();
				break;
			case 'media':
				$this->outputMediaTab();
				break;
			case 'woocommerce':
				$this->outputWooCommerceTab();
				break;
		}

		submit_button( __( 'Save Changes', 'novatools-polyglot' ) );
		echo '</form>';
	}

	/**
	 * Output the Translation API settings tab.
	 *
	 * @return void
	 */
	private function outputApiTab(): void {
		$options = $this->plugin->has( 'options' ) ? $this->plugin->get( 'options' ) : null;

		$deepl_key     = $options ? $options->get( 'api.deepl.key', '' ) : '';
		$deepl_tier    = $options ? $options->get( 'api.deepl.tier', 'free' ) : 'free';
		$google_key    = $options ? $options->get( 'api.google.key', '' ) : '';
		$openai_key    = $options ? $options->get( 'api.openai.key', '' ) : '';
		$openai_model  = $options ? $options->get( 'api.openai.model', 'gpt-4o-mini' ) : 'gpt-4o-mini';
		$default_prov  = $options ? $options->get( 'api.default_provider', '' ) : '';

		echo '<table class="form-table">';

		// Default provider.
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Default Provider', 'novatools-polyglot' ) . '</th>';
		echo '<td><select name="default_provider">';
		echo '<option value="">' . esc_html__( 'Auto (first configured)', 'novatools-polyglot' ) . '</option>';
		echo '<option value="deepl"' . selected( $default_prov, 'deepl', false ) . '>DeepL</option>';
		echo '<option value="google"' . selected( $default_prov, 'google', false ) . '>Google Translate</option>';
		echo '<option value="openai"' . selected( $default_prov, 'openai', false ) . '>OpenAI</option>';
		echo '</select></td>';
		echo '</tr>';

		// DeepL.
		echo '<tr><td colspan="2"><h3>DeepL</h3><hr></td></tr>';
		echo '<tr>';
		echo '<th scope="row"><label for="deepl_key">' . esc_html__( 'API Key', 'novatools-polyglot' ) . '</label></th>';
		echo '<td><input type="password" id="deepl_key" name="deepl_key" value="' . esc_attr( $deepl_key ) . '" class="regular-text" autocomplete="off" />';
		echo '<p class="description">' . esc_html__( 'Get your API key from deepl.com/account/summary.', 'novatools-polyglot' ) . '</p>';
		echo '</td></tr>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Account Tier', 'novatools-polyglot' ) . '</th>';
		echo '<td><select name="deepl_tier">';
		echo '<option value="free"' . selected( $deepl_tier, 'free', false ) . '>' . esc_html__( 'Free', 'novatools-polyglot' ) . '</option>';
		echo '<option value="pro"' . selected( $deepl_tier, 'pro', false ) . '>' . esc_html__( 'Pro', 'novatools-polyglot' ) . '</option>';
		echo '</select></td>';
		echo '</tr>';

		// Google Translate.
		echo '<tr><td colspan="2"><h3>Google Translate</h3><hr></td></tr>';
		echo '<tr>';
		echo '<th scope="row"><label for="google_key">' . esc_html__( 'API Key', 'novatools-polyglot' ) . '</label></th>';
		echo '<td><input type="password" id="google_key" name="google_key" value="' . esc_attr( $google_key ) . '" class="regular-text" autocomplete="off" />';
		echo '<p class="description">' . esc_html__( 'Google Cloud Translation API key.', 'novatools-polyglot' ) . '</p>';
		echo '</td></tr>';

		// OpenAI.
		echo '<tr><td colspan="2"><h3>OpenAI</h3><hr></td></tr>';
		echo '<tr>';
		echo '<th scope="row"><label for="openai_key">' . esc_html__( 'API Key', 'novatools-polyglot' ) . '</label></th>';
		echo '<td><input type="password" id="openai_key" name="openai_key" value="' . esc_attr( $openai_key ) . '" class="regular-text" autocomplete="off" />';
		echo '<p class="description">' . esc_html__( 'OpenAI platform API key.', 'novatools-polyglot' ) . '</p>';
		echo '</td></tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="openai_model">' . esc_html__( 'Model', 'novatools-polyglot' ) . '</label></th>';
		echo '<td><input type="text" id="openai_model" name="openai_model" value="' . esc_attr( $openai_model ) . '" class="regular-text" placeholder="gpt-4o-mini" />';
		echo '</td></tr>';

		echo '</table>';
	}

	/**
	 * Output the Custom Fields translation settings tab.
	 *
	 * @return void
	 */
	private function outputCustomFieldsTab(): void {
		$options = $this->plugin->has( 'options' ) ? $this->plugin->get( 'options' ) : null;
		$fields  = $options ? $options->get( 'custom_fields', array() ) : array();

		echo '<p class="description">' . esc_html__( 'Configure how each custom field is handled during translation. "Copy" copies the value as-is, "Translate" makes it editable per language, "Ignore" skips it entirely.', 'novatools-polyglot' ) . '</p>';

		echo '<table class="wp-list-table widefat fixed striped" style="margin-top:16px;">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>' . esc_html__( 'Field Key', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Mode', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'novatools-polyglot' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		if ( ! empty( $fields ) ) {
			foreach ( $fields as $key => $mode ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( $key ) . '</code></td>';
				echo '<td>';
				printf(
					'<select name="custom_fields[%s]">', esc_attr( $key ) );
				echo '<option value="copy"' . selected( $mode, 'copy', false ) . '>' . esc_html__( 'Copy', 'novatools-polyglot' ) . '</option>';
				echo '<option value="translate"' . selected( $mode, 'translate', false ) . '>' . esc_html__( 'Translate', 'novatools-polyglot' ) . '</option>';
				echo '<option value="ignore"' . selected( $mode, 'ignore', false ) . '>' . esc_html__( 'Ignore', 'novatools-polyglot' ) . '</option>';
				echo '</select></td>';
				echo '<td><button type="button" class="button button-small polyglot-remove-field" data-key="' . esc_attr( $key ) . '">' . esc_html__( 'Remove', 'novatools-polyglot' ) . '</button></td>';
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="3">' . esc_html__( 'No custom fields configured yet.', 'novatools-polyglot' ) . '</td></tr>';
		}

		echo '</tbody>';
		echo '</table>';

		// Add new field form.
		echo '<h3>' . esc_html__( 'Add Custom Field', 'novatools-polyglot' ) . '</h3>';
		echo '<table class="form-table">';
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Field Key', 'novatools-polyglot' ) . '</th>';
		echo '<td><input type="text" id="new_custom_field_key" class="regular-text" placeholder="e.g. _price_extra" /></td>';
		echo '</tr>';
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Mode', 'novatools-polyglot' ) . '</th>';
		echo '<td><select id="new_custom_field_mode">';
		echo '<option value="copy">' . esc_html__( 'Copy', 'novatools-polyglot' ) . '</option>';
		echo '<option value="translate">' . esc_html__( 'Translate', 'novatools-polyglot' ) . '</option>';
		echo '<option value="ignore">' . esc_html__( 'Ignore', 'novatools-polyglot' ) . '</option>';
		echo '</select></td>';
		echo '</tr>';
		echo '</table>';

		// Inline JS for dynamic field management.
		?>
		<script type="text/javascript">
		jQuery( document ).ready( function( $ ) {
			$( '.polyglot-remove-field' ).on( 'click', function() {
				$( this ).closest( 'tr' ).remove();
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Output the Post Types & Taxonomies settings tab.
	 *
	 * @return void
	 */
	private function outputPostTypesTab(): void {
		$options = $this->plugin->has( 'options' ) ? $this->plugin->get( 'options' ) : null;

		$enabled_types     = $options ? $options->get( 'post_types', array( 'post', 'page' ) ) : array( 'post', 'page' );
		$enabled_taxonomies = $options ? $options->get( 'taxonomies', array( 'category', 'post_tag' ) ) : array( 'category', 'post_tag' );

		$all_post_types = get_post_types( array( 'public' => true ), 'objects' );
		$all_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

		echo '<h2>' . esc_html__( 'Translatable Post Types', 'novatools-polyglot' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Select which post types should support translation.', 'novatools-polyglot' ) . '</p>';
		echo '<fieldset>';

		foreach ( $all_post_types as $name => $pt ) {
			printf(
				'<label style="display:block;margin:4px 0;"><input type="checkbox" name="post_types[]" value="%s" %s /> %s</label>',
				esc_attr( $name ),
				checked( in_array( $name, $enabled_types, true ), true, false ),
				esc_html( $pt->labels->name . ' (' . $name . ')' )
			);
		}

		echo '</fieldset>';

		echo '<h2 style="margin-top:30px;">' . esc_html__( 'Translatable Taxonomies', 'novatools-polyglot' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Select which taxonomies should support translation.', 'novatools-polyglot' ) . '</p>';
		echo '<fieldset>';

		foreach ( $all_taxonomies as $name => $tax ) {
			printf(
				'<label style="display:block;margin:4px 0;"><input type="checkbox" name="taxonomies[]" value="%s" %s /> %s</label>',
				esc_attr( $name ),
				checked( in_array( $name, $enabled_taxonomies, true ), true, false ),
				esc_html( $tax->labels->name . ' (' . $name . ')' )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Output the Media settings tab.
	 *
	 * @return void
	 */
	private function outputMediaTab(): void {
		$options = $this->plugin->has( 'options' ) ? $this->plugin->get( 'options' ) : null;
		$dup     = $options ? $options->get( 'media.duplicate_on_upload', false ) : false;

		echo '<table class="form-table">';
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Automatic Duplication', 'novatools-polyglot' ) . '</th>';
		echo '<td><label><input type="checkbox" name="media_duplicate_on_upload" value="1" ' . checked( $dup, true, false ) . ' /> ' . esc_html__( 'Duplicate uploaded media to all active languages', 'novatools-polyglot' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When enabled, every uploaded media file is automatically duplicated for each active language so it appears in the media library for all languages.', 'novatools-polyglot' ) . '</p>';
		echo '</td></tr>';
		echo '</table>';
	}

	/**
	 * Output the WooCommerce multi-currency settings tab.
	 *
	 * @return void
	 */
	private function outputWooCommerceTab(): void {
		$options = $this->plugin->has( 'options' ) ? $this->plugin->get( 'options' ) : null;

		$enabled = $options ? $options->get( 'woocommerce.multi_currency.enabled', false ) : false;
		$mode    = $options ? $options->get( 'woocommerce.multi_currency.mode', 'by_language' ) : 'by_language';
		$rates   = $options ? $options->get( 'woocommerce.multi_currency.rates', array() ) : array();

		// Get currencies from active languages.
		$active_languages = $this->getActiveLanguages();

		echo '<table class="form-table">';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Multi-Currency', 'novatools-polyglot' ) . '</th>';
		echo '<td><label><input type="checkbox" name="wc_multi_currency_enabled" value="1" ' . checked( $enabled, true, false ) . ' /> ' . esc_html__( 'Enable multi-currency support', 'novatools-polyglot' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Allow customers to view prices and pay in their local currency.', 'novatools-polyglot' ) . '</p>';
		echo '</td></tr>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Currency Detection', 'novatools-polyglot' ) . '</th>';
		echo '<td><select name="wc_currency_mode">';
		echo '<option value="by_language"' . selected( $mode, 'by_language', false ) . '>' . esc_html__( 'By language (each language has its own currency)', 'novatools-polyglot' ) . '</option>';
		echo '<option value="by_geolocation"' . selected( $mode, 'by_geolocation', false ) . '>' . esc_html__( 'By geolocation (detect from visitor IP)', 'novatools-polyglot' ) . '</option>';
		echo '</select></td>';
		echo '</tr>';

		// Per-language currency rates.
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Exchange Rate Overrides', 'novatools-polyglot' ) . '</th>';
		echo '<td>';

		$wc_currencies = function_exists( 'get_woocommerce_currencies' )
			? get_woocommerce_currencies()
			: array( 'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP' );

		foreach ( $active_languages as $code => $lang ) {
			$rate = $rates[ $code ] ?? '';
			printf(
				'<label style="display:block;margin:4px 0;"><span style="display:inline-block;width:100px;">%s:</span> <input type="number" step="0.0001" name="wc_currency_rates[%s]" value="%s" class="small-text" placeholder="1.0000" /></label>',
				esc_html( $lang->englishName ),
				esc_attr( $code ),
				esc_attr( $rate )
			);
		}

		echo '<p class="description">' . esc_html__( 'Override exchange rates per language. Leave empty to use automatic rates.', 'novatools-polyglot' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
	}

	// ─── Helpers ──────────────────────────────────────────────────────────

}

