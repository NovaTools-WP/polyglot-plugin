<?php
/**
 * Directory-based URL strategy for NovaTools Polyglot.
 *
 * Prepends a language directory segment to the URL path:
 *   - /en/about/  → English
 *   - /fr/a-propos/ → French
 *
 * Supports hiding the default language prefix when the
 * "hide_default_language_prefix" setting is enabled.
 *
 * @package NovaTools\Polyglot\Url
 */

namespace NovaTools\Polyglot\Url;

use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class DirectoryStrategy implements UrlStrategyInterface {

	/**
	 * Option store for reading URL-related settings.
	 *
	 * @var OptionStore
	 */
	private OptionStore $options;

	/**
	 * Cached list of active language codes.
	 *
	 * @var string[]|null
	 */
	private ?array $activeLanguages = null;

	/**
	 * Cached raw home URL (without language prefix filter).
	 *
	 * @var string|null
	 */
	private ?string $rawHomeUrl = null;

	/**
	 * Constructor.
	 *
	 * @param OptionStore $options Settings accessor.
	 */
	public function __construct( OptionStore $options ) {
		$this->options = $options;
	}

	/**
	 * {@inheritdoc}
	 */
	public function addLanguageToUrl( string $url, string $language ): string {
		// Don't prefix the default language when hiding is enabled.
		if ( $this->hideDefaultPrefix() && $language === $this->getDefaultLanguage() ) {
			return $url;
		}

		$home = $this->getRawHomeUrl();

		// Handle absolute URLs belonging to this site.
		if ( str_starts_with( $url, $home ) ) {
			// Already prefixed with a language directory?
			$path = substr( $url, strlen( $home ) );
			foreach ( $this->getActiveLanguages() as $code ) {
				$prefix = '/' . $code . '/';
				if ( str_starts_with( $path, $prefix ) || $path === '/' . $code ) {
					// Replace existing language prefix.
					$path = '/' . $language . substr( $path, strlen( '/' . $code ) );
					return $home . $path;
				}
			}

			// No existing prefix — inject one.
			$path = '/' . $language . $path;

			return $home . $path;
		}

		// Relative URL — prepend language directory.
		$path = '/' . ltrim( $url, '/' );
		$path = '/' . $language . $path;

		return $path;
	}

	/**
	 * Get the raw home URL without language prefix filtering.
	 *
	 * Uses get_option('home') directly to avoid triggering the home_url
	 * filter, which would cause infinite recursion.
	 *
	 * @return string Untrailingslashed home URL.
	 */
	private function getRawHomeUrl(): string {
		if ( null === $this->rawHomeUrl ) {
			$this->rawHomeUrl = untrailingslashit( get_option( 'home' ) );
		}

		return $this->rawHomeUrl;
	}

	/**
	 * {@inheritdoc}
	 */
	public function removeLanguageFromUrl( string $url ): string {
		foreach ( $this->getActiveLanguages() as $code ) {
			$prefix = '/' . $code;
			// Match /{code}/... or exactly /{code}
			if ( preg_match( '#^' . preg_quote( $prefix, '#' ) . '(/|$)#', $url, $m, PREG_OFFSET_CAPTURE ) ) {
				return substr( $url, 0, $m[0][1] ) . substr( $url, $m[0][1] + strlen( $prefix ) );
			}
		}

		return $url;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getLanguageFromUrl(): ?string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		// Strip query string and leading slash.
		$path = trim( wp_parse_url( $request_uri, PHP_URL_PATH ) ?? '', '/' );

		// Root path with no language segment.
		if ( '' === $path ) {
			// When hiding default prefix, root URL serves the default language.
			if ( $this->hideDefaultPrefix() ) {
				return $this->getDefaultLanguage();
			}

			return null;
		}

		// The first segment is the potential language code.
		$segments = explode( '/', $path );
		$first    = $segments[0];

		// Skip admin paths.
		if ( in_array( $first, array( 'wp-admin', 'wp-login.php', 'wp-content', 'wp-includes' ), true ) ) {
			return null;
		}

		foreach ( $this->getActiveLanguages() as $code ) {
			if ( $first === $code ) {
				return $code;
			}
		}

		// No language directory found, but the URL has a path.
		// When hiding default prefix, treat unmatched paths as default language.
		if ( $this->hideDefaultPrefix() ) {
			return $this->getDefaultLanguage();
		}

		return null;
	}

	/**
	 * Generate language-prefixed rewrite rules for directory strategy.
	 *
	 * Creates a copy of every WordPress rewrite rule for each active
	 * non-default language (when default prefix is shown) or for every
	 * active language (when default prefix is hidden and the root page
	 * needs explicit routing).
	 *
	 * @param array $rules Existing WordPress rewrite rules.
	 * @return array Rewrite rules with language prefixes prepended.
	 */
	public function generateRewriteRules( array $rules ): array {
		$activeLanguages = $this->getActiveLanguages();
		$defaultLang     = $this->getDefaultLanguage();
		$newRules        = array();

		foreach ( $activeLanguages as $code ) {
			// When hiding the default prefix, skip generating rules for it
			// — the unprefixed rules already handle the default language.
			if ( $this->hideDefaultPrefix() && $code === $defaultLang ) {
				continue;
			}

			foreach ( $rules as $pattern => $redirect ) {
				$prefixedPattern = $code . '/' . $pattern;

				// Inject the lang query var into the redirect.
				if ( str_contains( $redirect, '?' ) ) {
					$redirect .= '&lang=' . $code;
				} else {
					$redirect .= '?lang=' . $code;
				}

				$newRules[ $prefixedPattern ] = $redirect;
			}
		}

		// Language-prefixed rules must come BEFORE the original rules so
		// WordPress matches /fr/about/ before /about/.
		return array_merge( $newRules, $rules );
	}

	/**
	 * Check whether a URL path starts with a language directory segment.
	 *
	 * @param string $path URL path (e.g. '/fr/about/').
	 * @return bool
	 */
	public function pathHasLanguagePrefix( string $path ): bool {
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return false;
		}

		$first = explode( '/', $path )[0];

		return in_array( $first, $this->getActiveLanguages(), true );
	}

	/**
	 * Redirect URLs that use the default language prefix to the prefix-less
	 * version when "hide default language prefix" is enabled.
	 *
	 * Prevents duplicate content: /en/about/ → /about/
	 *
	 * @return void
	 */
	public function maybeRedirectDefaultPrefix(): void {
		if ( ! $this->hideDefaultPrefix() || is_admin() ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		$default = $this->getDefaultLanguage();
		$path    = trim( wp_parse_url( $request_uri, PHP_URL_PATH ) ?? '', '/' );

		// Check if path starts with the default language prefix.
		if ( str_starts_with( $path, $default . '/' ) || $path === $default ) {
			$clean = substr( $path, strlen( $default ) );
			$clean = '/' . ltrim( $clean, '/' );

			// Preserve query string.
			$query = wp_parse_url( $request_uri, PHP_URL_QUERY );
			if ( $query ) {
				$clean .= '?' . $query;
			}

			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect.wp_redirect
			wp_redirect( home_url( $clean ), 301 );
			exit;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDefaultHomeUrl(): string {
		return $this->getRawHomeUrl();
	}

	/**
	 * Whether to hide the default language prefix in URLs.
	 *
	 * @return bool
	 */
	public function hideDefaultPrefix(): bool {
		return (bool) $this->options->get( 'hide_default_language_prefix', false );
	}

	/**
	 * Get the default language code from settings.
	 *
	 * @return string
	 */
	public function getDefaultLanguage(): string {
		return $this->options->get( 'default_language', 'en' );
	}

	/**
	 * Get active language codes, cached per request.
	 *
	 * @return string[]
	 */
	private function getActiveLanguages(): array {
		if ( null === $this->activeLanguages ) {
			/**
			 * Filter the list of active language codes for URL resolution.
			 *
			 * @param string[] $languages Active language codes.
			 */
			$this->activeLanguages = apply_filters( 'polyglot_active_language_codes', array() );
		}

		return $this->activeLanguages;
	}
}
