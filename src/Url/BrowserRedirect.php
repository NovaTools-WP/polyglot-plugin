<?php
/**
 * Browser language redirect for NovaTools Polyglot.
 *
 * Detects the visitor's browser language preference from the
 * Accept-Language header and redirects to the matching active
 * language on the first visit (cookie-based detection).
 *
 * Redirect is only triggered when:
 *   1. The "browser_language_redirect" setting is enabled.
 *   2. The visitor has no "polyglot_lang_resolved" cookie (first visit).
 *   3. The browser's preferred language matches an active language.
 *   4. The visitor is on the frontend (not admin, not AJAX, not REST).
 *
 * @package NovaTools\Polyglot\Url
 */

namespace NovaTools\Polyglot\Url;

use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class BrowserRedirect {

	/**
	 * Cookie name used to remember that the visitor has been redirected.
	 *
	 * @var string
	 */
	const COOKIE_NAME = 'polyglot_lang_resolved';

	/**
	 * Cookie lifetime in seconds (1 year).
	 *
	 * @var int
	 */
	const COOKIE_LIFETIME = 31536000;

	/**
	 * Option store for reading redirect settings.
	 *
	 * @var OptionStore
	 */
	private OptionStore $options;

	/**
	 * URL converter for building language URLs.
	 *
	 * @var UrlConverter
	 */
	private UrlConverter $converter;

	/**
	 * Constructor.
	 *
	 * @param OptionStore  $options   Settings accessor.
	 * @param UrlConverter $converter URL converter service.
	 */
	public function __construct( OptionStore $options, UrlConverter $converter ) {
		$this->options   = $options;
		$this->converter = $converter;
	}

	/**
	 * Attempt a browser-language redirect on the first visit.
	 *
	 * Should be hooked to `template_redirect` with an early priority.
	 *
	 * @return void
	 */
	public function maybeRedirect(): void {
		// Skip if redirect is disabled in settings.
		if ( ! $this->isEnabled() ) {
			return;
		}

		// Only redirect on the frontend.
		if ( is_admin() || wp_doing_ajax() || $this->isRestRequest() ) {
			return;
		}

		// Skip if visitor already has the cookie — they've been here before.
		if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return;
		}

		// Skip if a language is already in the URL.
		$current = $this->converter->getCurrentLanguage();
		$default = $this->options->get( 'default_language', '' );

		if ( $current !== $default ) {
			// A non-default language is already active — visitor arrived
			// with an explicit language. Set the cookie and return.
			$this->setCookie( $current );
			return;
		}

		// Detect browser language.
		$browserLang = $this->detectBrowserLanguage();

		if ( null === $browserLang ) {
			// Could not determine browser language — set cookie with default.
			$this->setCookie( $default );
			return;
		}

		// Match against active languages.
		$matched = $this->matchLanguage( $browserLang );

		if ( null === $matched || $matched === $default ) {
			// No match, or matched the default language — stay on current.
			$this->setCookie( $default );
			return;
		}

		// Redirect to the matched language.
		$this->setCookie( $matched );

		$target = $this->converter->getHomeUrl( $matched );

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect.wp_redirect
		wp_redirect( $target, 302 );
		exit;
	}

	/**
	 * Whether browser language redirect is enabled.
	 *
	 * @return bool
	 */
	private function isEnabled(): bool {
		return (bool) $this->options->get( 'browser_language_redirect', false );
	}

	/**
	 * Parse the Accept-Language header and extract preferred language codes.
	 *
	 * Returns languages sorted by quality score (highest first).
	 *
	 * @return string[] Array of lowercase language codes (e.g. ['fr', 'en', 'de']).
	 */
	private function parseAcceptLanguage(): array {
		if ( ! isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			return array();
		}

		$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) );

		if ( '' === $header ) {
			return array();
		}

		$languages = array();

		// Accept-Language format: "fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7"
		$parts = explode( ',', $header );

		foreach ( $parts as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				continue;
			}

			// Split off the quality score.
			$segments = explode( ';', $part );
			$code     = trim( $segments[0] );
			$quality  = 1.0;

			// Parse q= value.
			for ( $i = 1; $i < count( $segments ); $i++ ) {
				$qPart = trim( $segments[ $i ] );
				if ( str_starts_with( $qPart, 'q=' ) ) {
					$quality = (float) substr( $qPart, 2 );
					break;
				}
			}

			// Normalise: "fr-FR" → "fr", "en-US" → "en".
			$base = strtolower( explode( '-', $code )[0] );

			if ( ! isset( $languages[ $base ] ) || $quality > $languages[ $base ] ) {
				$languages[ $base ] = $quality;
			}
		}

		// Sort by quality descending.
		arsort( $languages );

		return array_keys( $languages );
	}

	/**
	 * Detect the browser's preferred language.
	 *
	 * @return string|null The browser's top-priority language code, or null.
	 */
	private function detectBrowserLanguage(): ?string {
		$codes = $this->parseAcceptLanguage();

		return $codes[0] ?? null;
	}

	/**
	 * Match a browser-detected language code against active languages.
	 *
	 * Checks for exact match first, then falls back to base-code match
	 * (e.g. "pt-br" matches "pt").
	 *
	 * @param string $browserLang The browser-detected language code.
	 * @return string|null Matched language code, or null if no match.
	 */
	private function matchLanguage( string $browserLang ): ?string {
		$browserLang = strtolower( $browserLang );

		/**
		 * Filter the active language codes available for browser matching.
		 *
		 * @param string[] $activeCodes Active language codes.
		 */
		$activeCodes = apply_filters( 'polyglot_active_language_codes', array() );

		// Exact match.
		foreach ( $activeCodes as $code ) {
			if ( strtolower( $code ) === $browserLang ) {
				return $code;
			}
		}

		// Base-code match (e.g. browser sends "pt-br", active language is "pt").
		foreach ( $activeCodes as $code ) {
			$base = explode( '-', strtolower( $code ) )[0];
			if ( $base === $browserLang ) {
				return $code;
			}
		}

		return null;
	}

	/**
	 * Whether this is a REST API request.
	 *
	 * @return bool
	 */
	private function isRestRequest(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Set the resolution cookie so we don't redirect again.
	 *
	 * @param string $language The resolved language code.
	 * @return void
	 */
	private function setCookie( string $language ): void {
		/** This filter is documented in wp-includes/pluggable.php */
		$secure = apply_filters( 'secure_cookie', is_ssl(), '', '' );

		setcookie(
			self::COOKIE_NAME,
			$language,
			array(
				'expires'  => time() + self::COOKIE_LIFETIME,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}
