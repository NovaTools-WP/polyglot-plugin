<?php
/**
 * Language settings admin page for NovaTools Polyglot.
 *
 * Provides the UI for adding, removing, activating, deactivating languages,
 * setting the default language, choosing the URL format, and toggling
 * browser language redirect.
 *
 * @package NovaTools\Polyglot\Admin
 */

namespace NovaTools\Polyglot\Admin;

use NovaTools\Polyglot\Core\Plugin;
use NovaTools\Polyglot\Compatibility\DependencyCheck;

defined( 'ABSPATH' ) || exit;

class LanguageSettingsPage {

	use AdminPageTrait;

	/**
	 * Plugin instance for service resolution.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Core plugin singleton.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register admin-post handler for the "Add Language" form.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_polyglot_add_language', array( $this, 'handleAddLanguage' ) );
	}

	/**
	 * Handle the "Add Language" form submission via admin-post.php.
	 *
	 * Validates the nonce, sanitizes input, and delegates to
	 * LanguageManager::add(). Redirects back to the Languages page.
	 *
	 * @return void
	 */
	public function handleAddLanguage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to add languages.', 'novatools-polyglot' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['polyglot_add_language_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'polyglot_add_language' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'novatools-polyglot' ) );
		}

		$code         = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
		$locale       = sanitize_text_field( wp_unslash( $_POST['locale'] ?? '' ) );
		$english_name = sanitize_text_field( wp_unslash( $_POST['english_name'] ?? '' ) );
		$native_name  = sanitize_text_field( wp_unslash( $_POST['native_name'] ?? '' ) );
		$direction    = in_array( $_POST['direction'] ?? '', array( 'ltr', 'rtl' ), true )
			? sanitize_text_field( wp_unslash( $_POST['direction'] ) )
			: 'ltr';
		$flag_code    = sanitize_text_field( wp_unslash( $_POST['flag_code'] ?? '' ) );
		$sort_order   = absint( $_POST['sort_order'] ?? 0 );

		if ( '' === $code || '' === $locale || '' === $english_name ) {
			wp_safe_redirect( add_query_arg(
				array( 'page' => 'novatools-polyglot-languages', 'error' => 'missing_fields' ),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		if ( $this->plugin->has( 'language.manager' ) ) {
			$manager = $this->plugin->get( 'language.manager' );

			$manager->add( array(
				'code'         => $code,
				'locale'       => $locale,
				'englishName'  => $english_name,
				'nativeName'   => $native_name,
				'direction'    => $direction,
				'flagCode'     => '' !== $flag_code ? $flag_code : $code,
				'sortOrder'    => $sort_order,
			) );
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'novatools-polyglot-languages', 'added' => $code ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Render the language settings page.
	 *
	 * Handles form submissions before rendering the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'novatools-polyglot' ) );
		}

		$this->handleActions();
		$this->outputHeader();
		$this->outputCurrentLanguages();
		$this->outputAddLanguageForm();
		$this->outputUrlSettings();
		$this->outputBrowserRedirectSetting();
		$this->outputFooter();
	}

	/**
	 * Handle form submissions for language management actions.
	 *
	 * Processes activate, deactivate, set-default, and remove actions
	 * submitted via GET parameters with a nonce check.
	 *
	 * @return void
	 */
	private function handleActions(): void {
		$action = sanitize_text_field( wp_unslash( $_GET['polyglot_action'] ?? '' ) );
		$nonce  = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
		$code   = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );

		if ( '' === $action || '' === $code ) {
			return;
		}

		if ( ! wp_verify_nonce( $nonce, "polyglot_language_{$action}_{$code}" ) ) {
			wp_die( esc_html__( 'Security check failed.', 'novatools-polyglot' ) );
		}

		if ( ! $this->plugin->has( 'language.manager' ) ) {
			return;
		}

		$manager = $this->plugin->get( 'language.manager' );

		switch ( $action ) {
			case 'activate':
				$manager->activate( $code );
				break;
			case 'deactivate':
				$manager->deactivate( $code );
				break;
			case 'set_default':
				$manager->setDefault( $code );
				break;
		}

		// Redirect to clean URL after processing.
		$redirect = admin_url( 'admin.php' );
		$page     = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );

		wp_safe_redirect( add_query_arg( 'page', $page, $redirect ) );
		exit;
	}

	/**
	 * Output the page header.
	 *
	 * @return void
	 */
	private function outputHeader(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Language Settings', 'novatools-polyglot' ) . '</h1>';
	}

	/**
	 * Output the current languages table with action links.
	 *
	 * @return void
	 */
	private function outputCurrentLanguages(): void {
		$languages = $this->getAllLanguages();
		$default   = $this->getDefaultLanguage();
		$default_code = $default ? $default->code : '';
		$page      = sanitize_text_field( wp_unslash( $_GET['page'] ?? 'novatools-polyglot-languages' ) );

		echo '<h2>' . esc_html__( 'Languages', 'novatools-polyglot' ) . '</h2>';
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>' . esc_html__( 'Language', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Locale', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Direction', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'novatools-polyglot' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		foreach ( $languages as $code => $lang ) {
			$is_default = $code === $default_code;

			echo '<tr>';
			echo '<td>';
			echo '<strong>' . esc_html( $lang->englishName ) . '</strong>';
			if ( $is_default ) {
				echo ' <span class="dashicons dashicons-star-filled" style="color:#dba617;font-size:14px;" title="' . esc_attr__( 'Default', 'novatools-polyglot' ) . '"></span>';
			}
			echo '<br><span style="color:#646970;">' . esc_html( $lang->nativeName ) . '</span>';
			echo '</td>';
			echo '<td>' . esc_html( $lang->locale ) . '</td>';
			echo '<td>' . esc_html( strtoupper( $lang->direction ) ) . '</td>';
			echo '<td>';

			if ( $lang->isActive ) {
				echo '<span style="color:#00a32a;">' . esc_html__( 'Active', 'novatools-polyglot' ) . '</span>';
			} else {
				echo '<span style="color:#646970;">' . esc_html__( 'Inactive', 'novatools-polyglot' ) . '</span>';
			}

			echo '</td>';
			echo '<td>';

			if ( $lang->isActive && ! $is_default ) {
				echo '<a href="' . esc_url( $this->actionUrl( 'deactivate', $code, $page ) ) . '" class="button button-small">' . esc_html__( 'Deactivate', 'novatools-polyglot' ) . '</a> ';
				echo '<a href="' . esc_url( $this->actionUrl( 'set_default', $code, $page ) ) . '" class="button button-small">' . esc_html__( 'Set Default', 'novatools-polyglot' ) . '</a>';
			} elseif ( ! $lang->isActive ) {
				echo '<a href="' . esc_url( $this->actionUrl( 'activate', $code, $page ) ) . '" class="button button-small button-primary">' . esc_html__( 'Activate', 'novatools-polyglot' ) . '</a>';
			} elseif ( $is_default ) {
				echo '<span class="description">' . esc_html__( 'Default language', 'novatools-polyglot' ) . '</span>';
			}

			echo '</td>';
			echo '</tr>';
		}

		if ( empty( $languages ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No languages found.', 'novatools-polyglot' ) . '</td></tr>';
		}

		echo '</tbody>';
		echo '</table>';

		echo '<div class="card" style="margin-top:20px; max-width: 800px; padding: 16px 24px;">';
		echo '<h3>' . esc_html__( 'How to Enable the Language Switcher', 'novatools-polyglot' ) . '</h3>';
		echo '<p>' . esc_html__( 'Once you have configured and activated multiple languages, you can display the language switcher on your site using one of the following methods:', 'novatools-polyglot' ) . '</p>';
		echo '<ol>';
		echo '<li><strong>' . esc_html__( 'Navigation Menu (Recommended)', 'novatools-polyglot' ) . ':</strong><br>';
		echo esc_html__( 'Go to Appearance > Menus, select the checkbox for your target languages in the "PolyGlot Languages" section on the left, and click "Add to Menu".', 'novatools-polyglot' ) . '</li>';
		echo '<li style="margin-top: 10px;"><strong>' . esc_html__( 'Shortcode', 'novatools-polyglot' ) . ':</strong><br>';
		echo sprintf(
			/* translators: %s: shortcode code tag */
			esc_html__( 'Add the shortcode %s to any page, post, or text widget.', 'novatools-polyglot' ),
			'<code>[polyglot_switcher]</code>'
		) . ' ' . esc_html__( 'Supported attributes: format ("list" or "dropdown"), show_flags ("true" or "false"), show_names ("true" or "false"), and exclude (comma-separated codes).', 'novatools-polyglot' ) . '</li>';
		echo '<li style="margin-top: 10px;"><strong>' . esc_html__( 'Gutenberg Block', 'novatools-polyglot' ) . ':</strong><br>';
		echo esc_html__( 'Insert the "PolyGlot Language Switcher" block in the block editor (found under the Widgets category) and customize settings in the sidebar.', 'novatools-polyglot' ) . '</li>';
		echo '<li style="margin-top: 10px;"><strong>' . esc_html__( 'Classic Widget', 'novatools-polyglot' ) . ':</strong><br>';
		echo esc_html__( 'Go to Appearance > Widgets and drag the "PolyGlot Language Switcher" widget into any widget area.', 'novatools-polyglot' ) . '</li>';
		echo '<li style="margin-top: 10px;"><strong>' . esc_html__( 'PHP Template Code', 'novatools-polyglot' ) . ':</strong><br>';
		echo esc_html__( 'To render the switcher programmatically in your theme files (e.g. header.php), use this code:', 'novatools-polyglot' ) . '<br>';
		echo '<pre style="background:#f0f0f1; padding:8px; border-radius:4px; font-size:12px; overflow-x:auto; font-family:Consolas, Monaco, monospace;">';
		echo esc_html( "if ( class_exists( '\NovaTools\Polyglot\Core\Plugin' ) ) {\n" .
			"    \$switcher = \NovaTools\Polyglot\Core\Plugin::getInstance()->get( 'language_switcher' );\n" .
			"    echo \$switcher->render( array( 'format' => 'list' ) );\n" .
			"}" );
		echo '</pre></li>';
		echo '</ol>';
		echo '</div>';
	}

	/**
	 * Output the "Add Language" form.
	 *
	 * Provides a form with fields for language code, locale, English name,
	 * native name, text direction, and sort order.
	 *
	 * @return void
	 */
	private function outputAddLanguageForm(): void {
		$options = $this->plugin->has( 'options' ) ? $this->plugin->get( 'options' ) : null;

		echo '<h2>' . esc_html__( 'Add Language', 'novatools-polyglot' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'polyglot_add_language', 'polyglot_add_language_nonce' );
		echo '<input type="hidden" name="action" value="polyglot_add_language" />';

		echo '<table class="form-table">';
		echo '<tr>';
		echo '<th scope="row"><label for="polyglot_lang_code">' . esc_html__( 'Language Code', 'novatools-polyglot' ) . '</label></th>';
		echo '<td><input type="text" id="polyglot_lang_code" name="code" value="" maxlength="7" class="regular-text" placeholder="e.g. fr" required />';
		echo '<p class="description">' . esc_html__( 'ISO 639-1 two-letter code (e.g. "en", "fr", "de").', 'novatools-polyglot' ) . '</p>';
		echo '</td></tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="polyglot_lang_locale">' . esc_html__( 'WordPress Locale', 'novatools-polyglot' ) . '</label></th>';
		echo '<td><input type="text" id="polyglot_lang_locale" name="locale" value="" maxlength="35" class="regular-text" placeholder="e.g. fr_FR" required />';
		echo '<p class="description">' . esc_html__( 'Full WordPress locale code (e.g. "fr_FR", "de_DE").', 'novatools-polyglot' ) . '</p>';
		echo '</td></tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="polyglot_lang_english_name">' . esc_html__( 'English Name', 'novatools-polyglot' ) . '</label></th>';
		echo '<td><input type="text" id="polyglot_lang_english_name" name="english_name" value="" maxlength="128" class="regular-text" placeholder="e.g. French" required /></td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="polyglot_lang_native_name">' . esc_html__( 'Native Name', 'novatools-polyglot' ) . '</label></th>';
		echo '<td><input type="text" id="polyglot_lang_native_name" name="native_name" value="" maxlength="128" class="regular-text" placeholder="e.g. Fran&ccedil;ais" required /></td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="polyglot_lang_direction">' . esc_html__( 'Text Direction', 'novatools-polyglot' ) . '</label></th>';
		echo '<td><select id="polyglot_lang_direction" name="direction">';
		echo '<option value="ltr">' . esc_html__( 'Left to Right (LTR)', 'novatools-polyglot' ) . '</option>';
		echo '<option value="rtl">' . esc_html__( 'Right to Left (RTL)', 'novatools-polyglot' ) . '</option>';
		echo '</select></td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="polyglot_lang_flag_code">' . esc_html__( 'Flag Code', 'novatools-polyglot' ) . '</label></th>';
		echo '<td><input type="text" id="polyglot_lang_flag_code" name="flag_code" value="" maxlength="7" class="regular-text" placeholder="e.g. fr" />';
		echo '<p class="description">' . esc_html__( 'ISO 3166-1 alpha-2 country code for the flag icon. Defaults to language code.', 'novatools-polyglot' ) . '</p>';
		echo '</td></tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="polyglot_lang_sort_order">' . esc_html__( 'Sort Order', 'novatools-polyglot' ) . '</label></th>';
		echo '<td><input type="number" id="polyglot_lang_sort_order" name="sort_order" value="0" min="0" class="small-text" /></td>';
		echo '</tr>';
		echo '</table>';

		submit_button( __( 'Add Language', 'novatools-polyglot' ), 'primary', 'polyglot_submit_add_language' );
		echo '</form>';
	}

	/**
	 * Output the URL format settings form.
	 *
	 * @return void
	 */
	private function outputUrlSettings(): void {
		$options = $this->plugin->has( 'options' ) ? $this->plugin->get( 'options' ) : null;

		$current_method   = $options ? $options->get( 'url_strategy.method', 'directory' ) : 'directory';
		$hide_default     = $options ? $options->get( 'url_strategy.hide_default', true ) : true;
		$domain_mapping   = $options ? $options->get( 'url_strategy.domain_mapping', array() ) : array();
		$active_languages = $this->getActiveLanguages();

		echo '<h2>' . esc_html__( 'URL Format', 'novatools-polyglot' ) . '</h2>';
		echo '<form method="post" action="options.php">';

		// Use the registered settings group for standalone mode.
		if ( DependencyCheck::is_novatools_active() ) {
			// In NovaTools mode, save via AJAX/REST — render as a plain form
			// that posts to admin-post.php for standalone fallback.
			echo '<input type="hidden" name="action" value="polyglot_save_url_settings" />';
			wp_nonce_field( 'polyglot_save_url_settings', '_wpnonce' );
		} else {
			settings_fields( 'polyglot_settings_group' );
		}

		echo '<table class="form-table">';

		$strategies = array(
			'directory'   => __( 'Directory prefix (e.g. /en/, /fr/)', 'novatools-polyglot' ),
			'subdomain'   => __( 'Subdomain (e.g. en.example.com)', 'novatools-polyglot' ),
			'domain'      => __( 'Custom domain per language', 'novatools-polyglot' ),
			'query_param' => __( 'Query parameter (e.g. ?lang=en)', 'novatools-polyglot' ),
		);

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'URL Strategy', 'novatools-polyglot' ) . '</th>';
		echo '<td><fieldset>';
		foreach ( $strategies as $value => $label ) {
			printf(
				'<label style="display:block;margin-bottom:6px;"><input type="radio" name="polyglot_settings[url_strategy][method]" value="%s" %s /> %s</label>',
				esc_attr( $value ),
				checked( $current_method, $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset></td>';
		echo '</tr>';

		// Hide default language prefix (only for directory strategy).
		echo '<tr id="polyglot-hide-default-row">';
		echo '<th scope="row">' . esc_html__( 'Default Language Prefix', 'novatools-polyglot' ) . '</th>';
		echo '<td><label><input type="checkbox" name="polyglot_settings[url_strategy][hide_default]" value="1" ' . checked( $hide_default, true, false ) . ' /> ' . esc_html__( 'Hide URL prefix for the default language', 'novatools-polyglot' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When enabled, the default language URL does not include the language prefix (e.g. example.com instead of example.com/en/).', 'novatools-polyglot' ) . '</p>';
		echo '</td></tr>';

		// Domain mapping (only for domain strategy).
		echo '<tr id="polyglot-domain-mapping-row">';
		echo '<th scope="row">' . esc_html__( 'Domain Mapping', 'novatools-polyglot' ) . '</th>';
		echo '<td>';
		foreach ( $active_languages as $code => $lang ) {
			$mapped = $domain_mapping[ $code ] ?? '';
			echo '<label style="display:block;margin-bottom:4px;">';
			echo '<span style="display:inline-block;width:80px;">' . esc_html( $lang->englishName ) . ':</span> ';
			printf(
				'<input type="text" name="polyglot_settings[url_strategy][domain_mapping][%s]" value="%s" class="regular-text" placeholder="e.g. %s.example.com" />',
				esc_attr( $code ),
				esc_attr( $mapped ),
				esc_attr( $code )
			);
			echo '</label>';
		}
		echo '</td></tr>';

		echo '</table>';

		submit_button( __( 'Save URL Settings', 'novatools-polyglot' ), 'primary', 'polyglot_submit_url_settings' );
		echo '</form>';
	}

	/**
	 * Output the browser language redirect setting.
	 *
	 * @return void
	 */
	private function outputBrowserRedirectSetting(): void {
		$options = $this->plugin->has( 'options' ) ? $this->plugin->get( 'options' ) : null;
		$enabled = $options ? $options->get( 'browser_redirect.enabled', false ) : false;

		echo '<h2>' . esc_html__( 'Browser Language Redirect', 'novatools-polyglot' ) . '</h2>';
		echo '<form method="post" action="options.php">';

		if ( ! DependencyCheck::is_novatools_active() ) {
			settings_fields( 'polyglot_settings_group' );
		} else {
			echo '<input type="hidden" name="action" value="polyglot_save_browser_redirect" />';
			wp_nonce_field( 'polyglot_save_browser_redirect', '_wpnonce' );
		}

		echo '<table class="form-table">';
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Auto-Detect Language', 'novatools-polyglot' ) . '</th>';
		echo '<td><label><input type="checkbox" name="polyglot_settings[browser_redirect][enabled]" value="1" ' . checked( $enabled, true, false ) . ' /> ' . esc_html__( 'Automatically redirect first-time visitors based on their browser language', 'novatools-polyglot' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Visitors are redirected only on their first visit. A cookie prevents subsequent redirects.', 'novatools-polyglot' ) . '</p>';
		echo '</td></tr>';
		echo '</table>';

		submit_button( __( 'Save', 'novatools-polyglot' ), 'secondary', 'polyglot_submit_browser_redirect' );
		echo '</form>';
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
	 * Build a nonce-protected action URL for language operations.
	 *
	 * @param string $action Action name (activate, deactivate, set_default).
	 * @param string $code   Language code.
	 * @param string $page   Admin page slug.
	 * @return string
	 */
	private function actionUrl( string $action, string $code, string $page ): string {
		return add_query_arg(
			array(
				'page'            => $page,
				'polyglot_action' => $action,
				'code'            => $code,
				'_wpnonce'        => wp_create_nonce( "polyglot_language_{$action}_{$code}" ),
			),
			admin_url( 'admin.php' )
		);
	}
}
