<?php
/**
 * Public API functions for NovaTools Polyglot.
 *
 * These wrapper functions provide a clean procedural interface to the
 * language management and URL routing systems. They are always available
 * after the plugin has booted and are the recommended way for themes and
 * other plugins to interact with Polyglot.
 *
 * @package NovaTools\Polyglot
 */

defined( 'ABSPATH' ) || exit;

// ── Internal Helpers ──────────────────────────────────────────────────────

/**
 * Resolve a service from the DI container and invoke a callback.
 *
 * Centralises the repeated try/catch + has()/get() pattern used by all
 * public API functions. Returns $fallback when the service is missing
 * or an exception is thrown.
 *
 * @since 1.0.0
 *
 * @param string   $service  Container service key (e.g. 'language.repository').
 * @param callable $fn       Callback receiving the resolved service, returning the result.
 * @param mixed    $fallback Value returned on miss or error.
 * @return mixed
 */
function _polyglot_resolve( string $service, callable $fn, mixed $fallback ): mixed {
	try {
		$plugin = \NovaTools\Polyglot\Core\Plugin::getInstance();
		if ( ! $plugin->has( $service ) ) {
			return $fallback;
		}
		return $fn( $plugin->get( $service ) );
	} catch ( \Throwable $e ) {
		\NovaTools\Polyglot\Support\Logger::error( '_polyglot_resolve(' . $service . '): ' . $e->getMessage() );
		return $fallback;
	}
}

// ── Language Management API ───────────────────────────────────────────────

/**
 * Internal shared store for the current language code.
 *
 * Abstracts the static variable out of polyglot_get_current_language() so
 * that polyglot_set_current_language() can also write to it.
 *
 * @since 1.0.0
 * @param string|null $set Pass a string to set, or null to read.
 * @return string|null The stored language code, or null if unset.
 */
function _polyglot_current_language_store( ?string $set = null ): ?string {
	static $current = null;

	if ( null !== $set ) {
		$current = $set;
	}

	return $current;
}

/**
 * Get the current frontend / admin language code.
 *
 * Returns the language code resolved from the URL (frontend) or the
 * user's admin language preference. Falls back to the site default
 * when no language can be detected.
 *
 * @since 1.0.0
 * @return string Language code (e.g. "en", "fr").
 */
function polyglot_get_current_language(): string {
	$current = _polyglot_current_language_store();

	if ( null !== $current ) {
		return $current;
	}

	// On the frontend, try the URL converter first.
	if ( ! is_admin() ) {
		try {
			$plugin = \NovaTools\Polyglot\Core\Plugin::getInstance();

			if ( $plugin->has( 'url.converter' ) ) {
				/** @var \NovaTools\Polyglot\Url\UrlConverter $converter */
				$converter = $plugin->get( 'url.converter' );
				$lang      = $converter->getCurrentLanguage();

				if ( '' !== $lang ) {
					_polyglot_current_language_store( $lang );
					return $lang;
				}
			}
	} catch ( \Throwable $e ) {
		\NovaTools\Polyglot\Support\Logger::error( 'polyglot_get_current_language: ' . $e->getMessage() );
	}

		/**
		 * Filters the current frontend language code.
		 *
		 * @param string|false $code Language code, or false if not set.
		 */
		$from_url = apply_filters( 'polyglot_current_language', false );

		if ( $from_url && is_string( $from_url ) ) {
			_polyglot_current_language_store( $from_url );
			return $from_url;
		}
	}

	// Fallback to the site default language.
	$default = polyglot_get_default_language();

	_polyglot_current_language_store( $default );
	return $default;
}

/**
 * Override the current language for the remainder of the request.
 *
 * Subsequent calls to polyglot_get_current_language() will return the
 * given code. Pass an empty string or null to clear the override and
 * re-resolve the language on next access.
 *
 * @since 1.0.0
 * @param string|null $code Language code to force, or null to clear override.
 */
function polyglot_set_current_language( ?string $code ): void {
	_polyglot_current_language_store( $code );
}

/**
 * Get the site's default language code.
 *
 * @since 1.0.0
 * @return string Default language code (e.g. "en").
 */
function polyglot_get_default_language(): string {
	return _polyglot_resolve(
		'language.repository',
		fn( $repo ) => ( $default = $repo->getDefault() ) ? $default->code : 'en',
		'en'
	);
}

/**
 * Get all active languages as an array of Language objects.
 *
 * @since 1.0.0
 * @return \NovaTools\Polyglot\Language\Language[] Associative array keyed by language code.
 */
function polyglot_get_active_languages(): array {
	return _polyglot_resolve(
		'language.repository',
		fn( $repo ) => $repo->getActive(),
		array()
	);
}

/**
 * Get the display name of a language.
 *
 * Returns the native name by default. Pass "en" as the $in_language
 * parameter to get the English name instead.
 *
 * @since 1.0.0
 * @param string $code        Language code to look up.
 * @param string $in_language Optional. "native" (default) or "en" for English name.
 * @return string Language display name, or empty string if not found.
 */
function polyglot_get_language_name( string $code, string $in_language = 'native' ): string {
	return _polyglot_resolve(
		'language.repository',
		function ( $repo ) use ( $code, $in_language ) {
			$lang = $repo->getByCode( $code );
			if ( ! $lang ) {
				return '';
			}
			return ( 'en' === $in_language ) ? $lang->englishName : $lang->nativeName;
		},
		''
	);
}

// ── URL Routing API ───────────────────────────────────────────────────────

if ( ! function_exists( 'polyglot_url' ) ) {

	/**
	 * Convert a URL to a specific language using the active URL strategy.
	 *
	 * Usage:
	 *   polyglot_url( '/about/', 'fr' )             → '/fr/about/' (directory)
	 *   polyglot_url( get_permalink( 42 ), 'de' )   → '/de/ueber-uns/'
	 *   polyglot_url( home_url( '/contact/' ) )      → '/en/contact/' (current language)
	 *
	 * @since 1.0.0
	 *
	 * @param string      $url      The URL to convert.
	 * @param string|null $language Target language code. Defaults to current language.
	 * @return string The URL adjusted for the target language.
	 */
	function polyglot_url( string $url, ?string $language = null ): string {
		return _polyglot_resolve(
			'url.converter',
			fn( $converter ) => $converter->convert( $url, $language ),
			$url
		);
	}
}

if ( ! function_exists( 'polyglot_home_url' ) ) {

	/**
	 * Get the home URL for a specific language.
	 *
	 * Returns the site root URL adjusted for the given language
	 * according to the active URL strategy.
	 *
	 * Usage:
	 *   polyglot_home_url( 'fr' )       → '/fr/' (directory)
	 *   polyglot_home_url()             → '/en/' (current language)
	 *   polyglot_home_url( 'de' )       → 'https://de.example.com/' (subdomain)
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $language Language code. Defaults to current language.
	 * @return string The home URL for the given language.
	 */
	function polyglot_home_url( ?string $language = null ): string {
		return _polyglot_resolve(
			'url.converter',
			fn( $converter ) => $converter->getHomeUrl( $language ),
			home_url( '/' )
		);
	}
}

// ── String Translation API ────────────────────────────────────────────────

if ( ! function_exists( 'polyglot_register_string' ) ) {

	/**
	 * Register a string for translation.
	 *
	 * Stores the string in `polyglot_strings` with a unique hash derived
	 * from domain + context + name. Duplicate registrations update the
	 * existing row and flag translations as "needs_update" when the
	 * source value has changed.
	 *
	 * Usage:
	 *   polyglot_register_string( 'mytheme', 'header_title', 'Welcome', 'Header' );
	 *
	 * @since 1.0.0
	 *
	 * @param string $domain  Text domain (e.g. "mytheme", "contact-form-7").
	 * @param string $name    Machine-readable string identifier.
	 * @param string $value   The source string value.
	 * @param string $context Optional grouping context. Default empty.
	 * @param array  $args {
	 *     Optional. Extra registration metadata.
	 *
	 *     @type int    $package_id           Package ID to link the string to.
	 *     @type string $type                 String type: 'LINE', 'TEXTAREA', 'VISUAL'. Default 'LINE'.
	 *     @type string $title                Human-readable label.
	 *     @type string $translation_priority 'optional', 'prioritized', etc.
	 * }
	 * @return int The string ID, or 0 on failure.
	 */
	function polyglot_register_string(
		string $domain,
		string $name,
		string $value,
		string $context = '',
		array $args = array()
	): int {
		return _polyglot_resolve(
			'string.manager',
			fn( $manager ) => $manager->registerString( $domain, $name, $value, $context, $args ),
			0
		);
	}
}

if ( ! function_exists( 'polyglot_translate_string' ) ) {

	/**
	 * Translate a registered string by domain and name for a given language.
	 *
	 * Returns the translated string from `polyglot_string_translations`,
	 * falling back to the original value if no translation exists.
	 *
	 * Usage:
	 *   polyglot_translate_string( 'mytheme', 'header_title', 'fr' );
	 *   // → "Bienvenue" (if French translation exists)
	 *
	 * @since 1.0.0
	 *
	 * @param string $domain   Text domain.
	 * @param string $name     String name/identifier.
	 * @param string $language Target language code. Defaults to current language.
	 * @param string $context  Optional grouping context. Default empty.
	 * @return string Translated value, or original value as fallback. Empty string on error.
	 */
	function polyglot_translate_string( string $domain, string $name, string $language = '', string $context = '' ): string {
		if ( '' === $language ) {
			$language = polyglot_get_current_language();
		}

		return _polyglot_resolve(
			'string.manager',
			fn( $manager ) => $manager->translateString( $domain, $name, $language, $context ),
			''
		);
	}
}

if ( ! function_exists( 'polyglot_t' ) ) {

	/**
	 * Shorthand for registering (if needed) and translating a string.
	 *
	 * If the string is not yet registered, it is registered first using
	 * the provided default value. Then the translated value for the
	 * current language is returned.
	 *
	 * This is the most convenient function for theme/plugin developers:
	 *   echo polyglot_t( 'mytheme', 'footer_text', '© 2024 My Site' );
	 *
	 * @since 1.0.0
	 *
	 * @param string $domain  Text domain.
	 * @param string $name    String name/identifier.
	 * @param string $default Default source value (also used for registration).
	 * @return string Translated value for the current language, or the default value.
	 */
	function polyglot_t( string $domain, string $name, string $default ): string {
		$language = polyglot_get_current_language();

		return _polyglot_resolve(
			'string.manager',
			function ( $manager ) use ( $domain, $name, $default, $language ) {
				$manager->registerString( $domain, $name, $default );
				$translated = $manager->translateString( $domain, $name, $language );
				return '' !== $translated ? $translated : $default;
			},
			$default
		);
	}
}

// ── Content Translation API ──────────────────────────────────────────────

if ( ! function_exists( 'polyglot_translate_object' ) ) {

	/**
	 * Get the ID of a translated content element.
	 *
	 * Looks up the translation group for the given element and returns
	 * the element ID in the target language. Returns null when no
	 * translation exists.
	 *
	 * Usage:
	 *   polyglot_translate_object( 10, 'post_post', 'fr' )  → 25 (or null)
	 *   polyglot_translate_object( 5, 'tax_category', 'de' ) → 12 (or null)
	 *
	 * @since 1.0.0
	 *
	 * @param int         $id       Source element ID (post ID, term ID, etc.).
	 * @param string      $type     Element type (e.g. "post_post", "tax_category").
	 * @param string|null $language Target language code. Defaults to current language.
	 * @return int|null Translated element ID, or null if not found.
	 */
	function polyglot_translate_object( int $id, string $type, ?string $language = null ): ?int {
		if ( null === $language ) {
			$language = polyglot_get_current_language();
		}

		return _polyglot_resolve(
			'content.translator',
			fn( $translator ) => $translator->getTranslatedId( $id, $type, $language ),
			null
		);
	}
}

if ( ! function_exists( 'polyglot_get_object_language' ) ) {

	/**
	 * Get the language code assigned to a content element.
	 *
	 * Returns the language code stored in the `polyglot_translations`
	 * table for the given element.
	 *
	 * Usage:
	 *   polyglot_get_object_language( 25, 'post_post' ) → "fr"
	 *   polyglot_get_object_language( 5, 'tax_category' ) → "en"
	 *
	 * @since 1.0.0
	 *
	 * @param int    $id   Element ID (post ID, term ID, etc.).
	 * @param string $type Element type (e.g. "post_post", "tax_category").
	 * @return string|null Language code, or null if the element has no translation row.
	 */
	function polyglot_get_object_language( int $id, string $type ): ?string {
		return _polyglot_resolve(
			'content.translator',
			fn( $translator ) => $translator->getElementLanguage( $id, $type ),
			null
		);
	}
}

if ( ! function_exists( 'polyglot_get_translation_group' ) ) {

	/**
	 * Get the full translation group for a content element.
	 *
	 * Returns a TranslationGroup value object containing all elements
	 * that share the same trid — the source element and all its
	 * translations across languages.
	 *
	 * Usage:
	 *   $group = polyglot_get_translation_group( 10, 'post_post' );
	 *   $fr_id = $group->getElementId( 'fr' );   // → 25
	 *   $de_id = $group->getElementId( 'de' );   // → null
	 *   $langs = $group->getLanguageCodes();       // → ['en', 'fr']
	 *
	 * @since 1.0.0
	 *
	 * @param int    $id   Element ID (post ID, term ID, etc.).
	 * @param string $type Element type (e.g. "post_post", "tax_category").
	 * @return \NovaTools\Polyglot\Translation\TranslationGroup|null The group, or null.
	 */
	function polyglot_get_translation_group( int $id, string $type ): ?\NovaTools\Polyglot\Translation\TranslationGroup {
		return _polyglot_resolve(
			'content.translator',
			fn( $translator ) => $translator->getTranslationGroup( $id, $type ),
			null
		);
	}
}
