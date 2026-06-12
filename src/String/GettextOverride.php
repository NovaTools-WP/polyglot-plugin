<?php
/**
 * Gettext filter override for NovaTools Polyglot.
 *
 * Hooks into the WordPress `gettext` and `gettext_with_context` filters
 * to intercept `__()`, `_e()`, `_x()`, `_ex()`, and related calls.
 * When a database translation exists for the current language, it is
 * returned instead of the MO-file translation.
 *
 * Performance: the override short-circuits immediately when the current
 * language matches the site default (no DB lookup needed). All lookups
 * go through the object cache so repeated calls for the same string
 * are essentially free.
 *
 * @package NovaTools\Polyglot\String
 */

namespace NovaTools\Polyglot\String;

defined( 'ABSPATH' ) || exit;

class GettextOverride {

	/**
	 * String repository for hash-based lookups.
	 *
	 * @var StringRepository
	 */
	private StringRepository $repository;

	/**
	 * Whether the gettext hooks have been registered.
	 *
	 * @var bool
	 */
	private bool $hooked = false;

	/**
	 * Domains that should NOT be overridden.
	 *
	 * Default WordPress and admin domains are excluded because their
	 * translations are managed by core and should not be intercepted.
	 *
	 * @var string[]
	 */
	private array $excludedDomains = array(
		'default',
		'admin',
		'admin-network',
	);

	/**
	 * Constructor.
	 *
	 * @param StringRepository $repository String read access.
	 */
	public function __construct( StringRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register the gettext filter hooks.
	 *
	 * Safe to call multiple times — hooks are only added once.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->hooked ) {
			return;
		}

		$this->hooked = true;

		add_filter( 'gettext', array( $this, 'filterGettext' ), 10, 3 );
		add_filter( 'gettext_with_context', array( $this, 'filterGettextWithContext' ), 10, 4 );
	}

	/**
	 * Unregister the gettext filter hooks.
	 *
	 * Useful during migration or when the override needs to be temporarily
	 * disabled.
	 *
	 * @return void
	 */
	public function unregister(): void {
		if ( ! $this->hooked ) {
			return;
		}

		$this->hooked = false;

		remove_filter( 'gettext', array( $this, 'filterGettext' ), 10 );
		remove_filter( 'gettext_with_context', array( $this, 'filterGettextWithContext' ), 10 );
	}

	/**
	 * Filter callback for `gettext`.
	 *
	 * Intercepts `__()`, `_e()`, and other context-free translation calls.
	 *
	 * @param string $translation The translated text from MO files.
	 * @param string $text        The source text.
	 * @param string $domain      The text domain.
	 * @return string The database translation if available, otherwise the MO translation.
	 */
	public function filterGettext( string $translation, string $text, string $domain ): string {
		return $this->lookup( $text, $domain, '', $translation );
	}

	/**
	 * Filter callback for `gettext_with_context`.
	 *
	 * Intercepts `_x()`, `_ex()`, and other context-aware translation calls.
	 *
	 * @param string $translation The translated text from MO files.
	 * @param string $text        The source text.
	 * @param string $context     The translation context.
	 * @param string $domain      The text domain.
	 * @return string The database translation if available, otherwise the MO translation.
	 */
	public function filterGettextWithContext(
		string $translation,
		string $text,
		string $context,
		string $domain
	): string {
		return $this->lookup( $text, $domain, $context, $translation );
	}

	/**
	 * Look up a database translation for the given text + domain + context.
	 *
	 * This is the core lookup method shared by both gettext filters. It:
	 * 1. Short-circuits if the current language is the default (no override needed).
	 * 2. Short-circuits for excluded domains (default, admin, admin-network).
	 * 3. Computes the hash from domain|context|text.
	 * 4. Queries the repository for the string and its translation.
	 * 5. Returns the database translation if one exists and is complete.
	 *
	 * @param string $text        The source text (used as the "name" in the hash).
	 * @param string $domain      The text domain.
	 * @param string $context     The translation context (empty for gettext, populated for gettext_with_context).
	 * @param string $fallback    The MO-file translation to return if no DB override exists.
	 * @return string The translation string.
	 */
	private function lookup( string $text, string $domain, string $context, string $fallback ): string {
		// Skip if current language is the default — no override needed.
		$current_lang = $this->getCurrentLanguage();

		if ( $current_lang === $this->getDefaultLanguage() ) {
			return $fallback;
		}

		// Skip excluded domains (WordPress core translations).
		if ( in_array( $domain, $this->excludedDomains, true ) ) {
			return $fallback;
		}

		/**
		 * Filters whether gettext override should be applied for this call.
		 *
		 * @param bool   $apply   Whether to apply the override. Default true.
		 * @param string $text    Source text.
		 * @param string $domain  Text domain.
		 * @param string $context Translation context.
		 */
		if ( ! apply_filters( 'polyglot_gettext_override', true, $text, $domain, $context ) ) {
			return $fallback;
		}

		// Compute hash the same way StringManager does: domain|context|name.
		// For gettext calls, the source $text IS the name/identifier.
		$hash = md5( $domain . '|' . $context . '|' . $text );

		$string_row = $this->repository->findByHash( $hash );

		if ( ! $string_row ) {
			return $fallback;
		}

		$translation_row = $this->repository->getTranslation(
			(int) $string_row['id'],
			$current_lang
		);

		// Only return DB translation when it's complete.
		if (
			$translation_row
			&& StringManager::STATUS_TRANSLATED === (int) $translation_row['status']
			&& null !== $translation_row['value']
		) {
			return $translation_row['value'];
		}

		return $fallback;
	}

	/**
	 * Get the current language code.
	 *
	 * Uses the polyglot API function when available, falls back to
	 * determining from the URL or WordPress locale.
	 *
	 * @return string Language code.
	 */
	private function getCurrentLanguage(): string {
		if ( function_exists( 'polyglot_get_current_language' ) ) {
			return polyglot_get_current_language();
		}

		// Fallback: derive from the WordPress locale.
		$locale = determine_locale();
		$parts  = explode( '_', $locale );

		return strtolower( $parts[0] );
	}

	/**
	 * Get the default language code.
	 *
	 * @return string Default language code.
	 */
	private function getDefaultLanguage(): string {
		if ( function_exists( 'polyglot_get_default_language' ) ) {
			return polyglot_get_default_language();
		}

		return 'en';
	}

	/**
	 * Add a domain to the exclusion list.
	 *
	 * Plugins that manage their own translations can opt out of the
	 * gettext override.
	 *
	 * @param string $domain Text domain to exclude.
	 */
	public function addExcludedDomain( string $domain ): void {
		if ( ! in_array( $domain, $this->excludedDomains, true ) ) {
			$this->excludedDomains[] = $domain;
		}
	}

	/**
	 * Remove a domain from the exclusion list.
	 *
	 * @param string $domain Text domain to re-enable.
	 */
	public function removeExcludedDomain( string $domain ): void {
		$key = array_search( $domain, $this->excludedDomains, true );

		if ( false !== $key ) {
			array_splice( $this->excludedDomains, $key, 1 );
		}
	}
}
