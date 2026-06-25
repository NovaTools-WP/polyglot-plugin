<?php
/**
 * Admin bar language switcher for NovaTools Polyglot.
 *
 * Adds a language switcher dropdown to the WordPress admin bar, allowing
 * quick switching between languages during content editing. Uses the
 * `admin_bar_menu` hook to inject the switcher node.
 *
 * When a language is selected, the page reloads with a `polyglot_lang`
 * query parameter that sets the admin editing language for the session.
 *
 * @package NovaTools\Polyglot\LanguageSwitcher
 */

namespace NovaTools\Polyglot\LanguageSwitcher;

use NovaTools\Polyglot\Language\FlagResolver;
use NovaTools\Polyglot\Language\LanguageRepository;

defined( 'ABSPATH' ) || exit;

class AdminBarSwitcher {

	/**
	 * The central language switcher renderer.
	 *
	 * @var LanguageSwitcher
	 */
	private LanguageSwitcher $switcher;

	/**
	 * Language repository for fetching active languages.
	 *
	 * @var LanguageRepository
	 */
	private LanguageRepository $languageRepository;

	/**
	 * Query parameter used to switch the admin language.
	 *
	 * @var string
	 */
	const QUERY_PARAM = 'polyglot_lang';

	/**
	 * Cookie name for persisting the admin language choice.
	 *
	 * @var string
	 */
	const COOKIE_NAME = 'polyglot_admin_lang';

	/**
	 * Constructor.
	 *
	 * @param LanguageSwitcher   $switcher           The render service.
	 * @param LanguageRepository $languageRepository Language data access.
	 */
	public function __construct( LanguageSwitcher $switcher, LanguageRepository $languageRepository ) {
		$this->switcher           = $switcher;
		$this->languageRepository = $languageRepository;
	}

	/**
	 * Register WordPress hooks for the admin bar switcher.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'addToAdminBar' ), 100 );
		add_action( 'admin_init', array( $this, 'handleLanguageSwitch' ) );
		add_action( 'init', array( $this, 'handleFrontendLanguageSwitch' ) );
	}

	/**
	 * Handle the language switch request in the admin area.
	 *
	 * Reads the `polyglot_lang` query parameter, validates it against
	 * active languages, and sets a cookie for persistence.
	 *
	 * @return void
	 */
	public function handleLanguageSwitch(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET[ self::QUERY_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_PARAM ] ) ) : '';

		if ( '' === $code ) {
			return;
		}

		// Validate that the language is active.
		$lang = $this->languageRepository->getByCode( $code );

		if ( ! $lang || ! $lang->isActive ) {
			return;
		}

		// Set a cookie to persist the admin language for 30 days.
		$secure = is_ssl();
		setcookie( self::COOKIE_NAME, $code, time() + ( 30 * DAY_IN_SECONDS ), ADMIN_COOKIE_PATH, COOKIE_DOMAIN, $secure, true );

		// Update the current language immediately.
		polyglot_set_current_language( $code );

		// Redirect to remove the query parameter and avoid re-processing.
		$redirect = remove_query_arg( self::QUERY_PARAM );

		if ( wp_redirect( $redirect ) ) {
			exit;
		}
	}

	/**
	 * Handle the language switch request on the frontend.
	 *
	 * Allows the admin bar switcher to also work on the frontend
	 * for logged-in users with admin bar visible.
	 *
	 * @return void
	 */
	public function handleFrontendLanguageSwitch(): void {
		if ( is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET[ self::QUERY_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_PARAM ] ) ) : '';

		if ( '' === $code ) {
			return;
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$lang = $this->languageRepository->getByCode( $code );

		if ( ! $lang || ! $lang->isActive ) {
			return;
		}

		// Set cookie and redirect.
		$secure = is_ssl();
		setcookie( self::COOKIE_NAME, $code, time() + ( 30 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN, $secure, true );

		polyglot_set_current_language( $code );

		$redirect = remove_query_arg( self::QUERY_PARAM );

		if ( wp_redirect( $redirect ) ) {
			exit;
		}
	}

	/**
	 * Add the language switcher to the admin bar.
	 *
	 * Creates a parent node with the current language and child nodes
	 * for each active language. Only shown to users who can edit posts.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 * @return void
	 */
	public function addToAdminBar( \WP_Admin_Bar $wp_admin_bar ): void {
		// Only show to users who can edit content.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$languages = $this->languageRepository->getActive();

		if ( count( $languages ) < 2 ) {
			return;
		}

		$current = $this->getCurrentAdminLanguage();
		$current_lang = $this->languageRepository->getByCode( $current );

		// Build the parent node showing the current language.
		$parent_title = $current_lang
			? $current_lang->nativeName
			: strtoupper( $current );

		$parent_icon = $current_lang
			? FlagResolver::emoji( $current_lang->code )
			: '🌐';

		$wp_admin_bar->add_node( array(
			'id'     => 'polyglot-switch',
			'title'  => sprintf(
				'<span class="ab-icon">%s</span> <span class="ab-label">%s</span>',
				$parent_icon,
				esc_html( $parent_title )
			),
			'href'   => '#',
			'meta'   => array(
				'class' => 'polyglot-admin-bar-switcher',
				'title' => __( 'Switch language', 'novatools-polyglot' ),
			),
		) );

		// Add child nodes for each active language.
		foreach ( $languages as $lang ) {
			if ( $lang->code === $current ) {
				continue;
			}

			$flag = FlagResolver::emoji( $lang->code );
			$switch_url = $this->getSwitchUrl( $lang->code );

			$wp_admin_bar->add_node( array(
				'id'     => 'polyglot-switch-' . $lang->code,
				'parent' => 'polyglot-switch',
				'title'  => sprintf(
					'%s %s',
					$flag,
					esc_html( $lang->nativeName )
				),
				'href'   => esc_url( $switch_url ),
				'meta'   => array(
					'class' => 'polyglot-admin-bar-lang polyglot-admin-bar-lang-' . $lang->code,
					'title' => sprintf(
						/* translators: %s: Language native name */
						__( 'Switch to %s', 'novatools-polyglot' ),
						$lang->nativeName
					),
				),
			) );
		}

		// Inject minimal CSS for the admin bar switcher.
		$this->injectAdminBarStyles();
	}

	/**
	 * Get the current admin language code.
	 *
	 * Checks in order: cookie, current language, default.
	 *
	 * @return string Language code.
	 */
	private function getCurrentAdminLanguage(): string {
		// Check cookie first.
		if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$code = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
			$lang = $this->languageRepository->getByCode( $code );

			if ( $lang && $lang->isActive ) {
				return $code;
			}
		}

		// Fall back to current language.
		return polyglot_get_current_language();
	}

	/**
	 * Build the URL for switching to a different language.
	 *
	 * Appends the `polyglot_lang` query parameter to the current URL.
	 *
	 * @param string $code Target language code.
	 * @return string The switch URL.
	 */
	private function getSwitchUrl( string $code ): string {
		global $pagenow, $wp;

		// Build URL from the current request.
		if ( is_admin() ) {
			$url = admin_url( $pagenow );
		} else {
			$url = home_url( add_query_arg( [], $wp->request ?? '/' ) );
		}

		return add_query_arg( self::QUERY_PARAM, $code, $url );
	}

	/**
	 * Inject minimal CSS for the admin bar language switcher.
	 *
	 * @return void
	 */
	private function injectAdminBarStyles(): void {
		static $styles_injected = false;

		if ( $styles_injected ) {
			return;
		}

		$styles_injected = true;

		add_action( is_admin() ? 'admin_footer' : 'wp_footer', function () {
			?>
			<style>
			#wpadminbar .polyglot-admin-bar-switcher .ab-icon {
				font-size: 16px;
				line-height: 1;
				margin-right: 4px;
			}
			#wpadminbar .polyglot-admin-bar-switcher .ab-label {
				font-size: 13px;
			}
			#wpadminbar .polyglot-admin-bar-lang {
				display: flex;
				align-items: center;
				gap: 6px;
			}
			</style>
			<?php
		} );
	}
}
