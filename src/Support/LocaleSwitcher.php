<?php
/**
 * Frontend locale switcher for NovaTools Polyglot.
 *
 * Makes WordPress core load the correct `.mo` files for the active
 * frontend language. Without this service, `get_locale()` always returns
 * the site default on the frontend, so theme/plugin MO files never reload
 * for the requested language — only the DB `GettextOverride` provided
 * per-language strings.
 *
 * This service does NOT replace `GettextOverride`. It fixes the locale at
 * the WordPress level so that unregistered `__()` calls resolve correctly;
 * `GettextOverride` still wins for DB strings (it runs on the `gettext`
 * filter after the MO value is produced).
 *
 * Reversibility: compatible with `switch_to_locale()` (used by
 * `EmailTranslator`). Our `locale` filter runs at priority 1, so the
 * locale switcher's own `locale` filter (priority 10) overrides us while a
 * switch is active and transparently passes our value through when it ends.
 *
 * Strategy-agnostic: it reads `polyglot_get_current_language()`, which is
 * URL-strategy-independent, so directory/subdomain/domain/query-param all
 * resolve identically.
 *
 * @package NovaTools\Polyglot\Support
 */

namespace NovaTools\Polyglot\Support;

use NovaTools\Polyglot\Language\LocaleMapper;

defined( 'ABSPATH' ) || exit;

class LocaleSwitcher {

	/**
	 * Locale mapper for language-code → WordPress-locale conversion.
	 *
	 * @var LocaleMapper
	 */
	private LocaleMapper $localeMapper;

	/**
	 * The locale most recently applied by this service.
	 *
	 * Used to memoize textdomain reloading: `determine_locale`/`locale`
	 * fire many times per request, but textdomains only need to reload
	 * when the resolved locale actually changes.
	 *
	 * @var string|null
	 */
	private ?string $lastAppliedLocale = null;

	/**
	 * Whether the filter hooks have been registered.
	 *
	 * @var bool
	 */
	private bool $hooked = false;

	/**
	 * WordPress domains whose translations are managed by core and must
	 * never be intercepted, unloaded, or reloaded by this service.
	 *
	 * @var string[]
	 */
	private array $coreDomains = array(
		'default',
		'admin',
		'admin-network',
	);

	/**
	 * Map of text domain → recorded `.mo` file path.
	 *
	 * Populated the first time WordPress loads each non-core domain, so the
	 * locale can be re-applied later by reloading from the recorded path.
	 *
	 * @var array<string, string>
	 */
	private array $textdomainPaths = array();

	/**
	 * Constructor.
	 *
	 * @param LocaleMapper $localeMapper Locale mapper instance.
	 */
	public function __construct( LocaleMapper $localeMapper ) {
		$this->localeMapper = $localeMapper;
	}

	/**
	 * Register the locale-switching hooks.
	 *
	 * Safe to call multiple times — hooks are only added once. Registered
	 * at priority 1 so the locale switcher's own filters (priority 10)
	 * override us while a `switch_to_locale()` is active.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->hooked ) {
			return;
		}

		$this->hooked = true;

		// Record each domain's .mo path the first time WP loads it.
		add_action( 'load_textdomain', array( $this, 'recordTextdomainPath' ), 0, 2 );

		// Drive the resolved locale at both determination points.
		add_filter( 'determine_locale', array( $this, 'filterDetermineLocale' ), 1 );
		add_filter( 'locale', array( $this, 'filterLocale' ), 1 );
	}

	/**
	 * Filter callback for `determine_locale`.
	 *
	 * @param string $locale The locale WordPress would use.
	 * @return string The resolved locale, or the passed locale when switching is skipped.
	 */
	public function filterDetermineLocale( string $locale ): string {
		return $this->resolveLocale( $locale );
	}

	/**
	 * Filter callback for `locale`.
	 *
	 * @param string $locale The locale WordPress would use.
	 * @return string The resolved locale, or the passed locale when switching is skipped.
	 */
	public function filterLocale( string $locale ): string {
		return $this->resolveLocale( $locale );
	}

	/**
	 * Resolve the frontend locale for the current request.
	 *
	 * Skips REST, admin, and cron contexts (each manages its own locale).
	 * Short-circuits to the fallback when the current language equals the
	 * site default — WP already loads the correct `.mo` in that case.
	 * Otherwise maps the language code to a WordPress locale, validates it,
	 * applies the `polyglot_frontend_locale` filter, and reloads textdomains
	 * when the resolved locale changes.
	 *
	 * @param string $fallback The locale WordPress would use.
	 * @return string The resolved locale.
	 */
	private function resolveLocale( string $fallback ): string {
		// Admin, REST, and cron each manage their own locale.
		if ( $this->shouldSkip() ) {
			return $fallback;
		}

		$current = $this->getCurrentLanguage();
		$default = $this->getDefaultLanguage();

		// Default language: WP core already loads the correct .mo.
		if ( '' === $current || $current === $default ) {
			$locale = $fallback;
		} else {
			$mapped = $this->localeMapper->codeToLocale( $current );

			// codeToLocale() returns the code unchanged when unknown; treat
			// that (or any invalid result) as "no change" to stay safe.
			if ( $mapped === $current || ! $this->localeMapper->isValidLocale( $mapped ) ) {
				$locale = $fallback;
			} else {
				$locale = $mapped;
			}
		}

		/**
		 * Filters the resolved frontend locale before it is applied.
		 *
		 * @param string $locale   The resolved WordPress locale.
		 * @param string $fallback The locale WordPress would have used.
		 * @param string $current  The Polyglot current language code.
		 */
		$locale = (string) apply_filters( 'polyglot_frontend_locale', $locale, $fallback, $current );

		// Reload textdomains only when the resolved locale actually changes.
		if ( $locale !== $this->lastAppliedLocale ) {
			$this->reloadLoadedTextdomains( $locale );
			$this->lastAppliedLocale = $locale;

			/**
			 * Fires after the frontend locale has been applied and any
			 * non-core textdomains reloaded.
			 *
			 * Themes/plugins with custom loaders can self-reload here.
			 *
			 * @param string $locale  The newly applied WordPress locale.
			 * @param string $current The Polyglot current language code.
			 */
			do_action( 'polyglot_locale_switched', $locale, $current );
		}

		return $locale;
	}

	/**
	 * Whether locale switching should be skipped for this request.
	 *
	 * Skipped in admin screens, REST requests, and cron runs — each
	 * resolves its own locale independently (admin/user locale, the
	 * locale switcher stack, the site default respectively).
	 *
	 * @return bool True when the current context manages its own locale.
	 */
	private function shouldSkip(): bool {
		if ( is_admin() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}

		return false;
	}

	/**
	 * Record a domain's `.mo` file path the first time it is loaded.
	 *
	 * Hooked on the `load_textdomain` action at priority 0. Core domains
	 * are ignored. Only the first load is recorded so the canonical
	 * registration path is preserved.
	 *
	 * @param string $domain The text domain.
	 * @param string $mofile The path to the loaded MO file.
	 * @return void
	 */
	public function recordTextdomainPath( string $domain, string $mofile ): void {
		if ( in_array( $domain, $this->coreDomains, true ) ) {
			return;
		}

		if ( ! isset( $this->textdomainPaths[ $domain ] ) ) {
			$this->textdomainPaths[ $domain ] = $mofile;
		}
	}

	/**
	 * Reload every recorded non-core textdomain.
	 *
	 * Unloads then re-loads each domain from its recorded path so that
	 * translations reflect the newly resolved locale. Core domains are
	 * skipped. WordPress' `load_textdomain()` is a no-op when the file is
	 * unreadable, so a missing path is harmless. This is a safety net for
	 * domains loaded before this service resolved the locale; domains
	 * registered after our filter is active already load the correct file
	 * via `determine_locale()`.
	 *
	 * @param string $locale The locale being applied.
	 * @return void
	 */
	private function reloadLoadedTextdomains( string $locale ): void {
		foreach ( $this->textdomainPaths as $domain => $mofile ) {
			if ( in_array( $domain, $this->coreDomains, true ) ) {
				continue;
			}

			unload_textdomain( $domain );
			load_textdomain( $domain, $mofile );
		}
	}

	/**
	 * Get the current Polyglot language code.
	 *
	 * @return string Language code, or empty string when unavailable.
	 */
	private function getCurrentLanguage(): string {
		if ( function_exists( 'polyglot_get_current_language' ) ) {
			return polyglot_get_current_language();
		}

		return '';
	}

	/**
	 * Get the site's default Polyglot language code.
	 *
	 * @return string Default language code, or 'en' when unavailable.
	 */
	private function getDefaultLanguage(): string {
		if ( function_exists( 'polyglot_get_default_language' ) ) {
			return polyglot_get_default_language();
		}

		return 'en';
	}

	/**
	 * Add a domain to the core-exclusion list (skips recording/reloading).
	 *
	 * Plugins that fully manage their own translations can opt out of the
	 * reload mechanism here.
	 *
	 * @param string $domain Text domain to treat as core-managed.
	 */
	public function addCoreDomain( string $domain ): void {
		if ( ! in_array( $domain, $this->coreDomains, true ) ) {
			$this->coreDomains[] = $domain;
		}
	}

	/**
	 * Remove a domain from the core-exclusion list.
	 *
	 * @param string $domain Text domain to re-enable.
	 */
	public function removeCoreDomain( string $domain ): void {
		$key = array_search( $domain, $this->coreDomains, true );

		if ( false !== $key ) {
			array_splice( $this->coreDomains, $key, 1 );
		}
	}
}
