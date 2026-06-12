<?php
/**
 * Subdomain-based URL strategy for NovaTools Polyglot.
 *
 * Uses a language prefix as a subdomain:
 *   - en.example.com/about/ → English
 *   - fr.example.com/about/ → French
 *
 * @package NovaTools\Polyglot\Url
 */

namespace NovaTools\Polyglot\Url;

use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class SubdomainStrategy implements UrlStrategyInterface {

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
		$parsed = wp_parse_url( $url );
		$host   = $parsed['host'] ?? wp_parse_url( home_url(), PHP_URL_HOST );
		$scheme = $parsed['scheme'] ?? ( is_ssl() ? 'https' : 'http' );

		// Strip any existing language subdomain.
		$host = $this->stripLanguageSubdomain( $host );

		// Prepend language subdomain.
		$languageHost = $language . '.' . $host;

		// Rebuild URL.
		$path     = $parsed['path'] ?? '/';
		$query    = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
		$fragment = isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';

		// Don't prefix the default language when hiding is enabled.
		if ( $this->hideDefaultPrefix() && $language === $this->getDefaultLanguage() ) {
			return $scheme . '://' . $host . $path . $query . $fragment;
		}

		return $scheme . '://' . $languageHost . $path . $query . $fragment;
	}

	/**
	 * {@inheritdoc}
	 */
	public function removeLanguageFromUrl( string $url ): string {
		$parsed = wp_parse_url( $url );
		if ( ! isset( $parsed['host'] ) ) {
			return $url;
		}

		$host   = $this->stripLanguageSubdomain( $parsed['host'] );
		$scheme = $parsed['scheme'] ?? ( is_ssl() ? 'https' : 'http' );

		$path     = $parsed['path'] ?? '/';
		$query    = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
		$fragment = isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';

		return $scheme . '://' . $host . $path . $query . $fragment;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getLanguageFromUrl(): ?string {
		$host = isset( $_SERVER['HTTP_HOST'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
			: '';

		if ( '' === $host ) {
			return null;
		}

		$parts = explode( '.', $host );

		// Need at least subdomain.domain.tld (3 parts).
		if ( count( $parts ) < 3 ) {
			return null;
		}

		$subdomain = $parts[0];

		// Skip www.
		if ( 'www' === $subdomain ) {
			return null;
		}

		foreach ( $this->getActiveLanguages() as $code ) {
			if ( $subdomain === $code ) {
				return $code;
			}
		}

		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDefaultHomeUrl(): string {
		$parsed = wp_parse_url( home_url() );
		$scheme = $parsed['scheme'] ?? ( is_ssl() ? 'https' : 'http' );
		$host   = $parsed['host'] ?? '';
		$host   = $this->stripLanguageSubdomain( $host );

		return $scheme . '://' . $host;
	}

	/**
	 * Remove a language subdomain prefix from a hostname.
	 *
	 * @param string $host The hostname (e.g. "fr.example.com").
	 * @return string Hostname without language subdomain (e.g. "example.com").
	 */
	private function stripLanguageSubdomain( string $host ): string {
		foreach ( $this->getActiveLanguages() as $code ) {
			if ( str_starts_with( $host, $code . '.' ) ) {
				return substr( $host, strlen( $code ) + 1 );
			}
		}

		// Also strip "www." if present.
		if ( str_starts_with( $host, 'www.' ) ) {
			return substr( $host, 4 );
		}

		return $host;
	}

	/**
	 * Whether to hide the default language prefix.
	 *
	 * @return bool
	 */
	private function hideDefaultPrefix(): bool {
		return (bool) $this->options->get( 'hide_default_language_prefix', false );
	}

	/**
	 * Get the default language code.
	 *
	 * @return string
	 */
	private function getDefaultLanguage(): string {
		return $this->options->get( 'default_language', 'en' );
	}

	/**
	 * Get active language codes, cached per request.
	 *
	 * @return string[]
	 */
	private function getActiveLanguages(): array {
		if ( null === $this->activeLanguages ) {
			/** @var string[] $languages */
			$languages = apply_filters( 'polyglot_active_language_codes', array() );
			$this->activeLanguages = $languages;
		}

		return $this->activeLanguages;
	}
}
