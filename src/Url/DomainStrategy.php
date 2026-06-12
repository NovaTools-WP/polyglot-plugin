<?php
/**
 * Custom-domain URL strategy for NovaTools Polyglot.
 *
 * Maps each language to a separate domain:
 *   - example.com/about/ → English (default)
 *   - example.fr/about/  → French
 *   - example.de/kontakt/ → German
 *
 * Language-domain mappings are stored in the option
 * "language_domains" as an associative array: {code => domain}.
 *
 * @package NovaTools\Polyglot\Url
 */

namespace NovaTools\Polyglot\Url;

use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class DomainStrategy implements UrlStrategyInterface {

	/**
	 * Option store for reading URL-related settings.
	 *
	 * @var OptionStore
	 */
	private OptionStore $options;

	/**
	 * Cached language → domain map.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $domainMap = null;

	/**
	 * Cached domain → language reverse map.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $reverseMap = null;

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
		$domains = $this->getDomainMap();

		// If no domain is mapped for this language, fall back to the default domain.
		if ( ! isset( $domains[ $language ] ) ) {
			return $url;
		}

		$targetDomain = $domains[ $language ];
		$scheme       = is_ssl() ? 'https' : 'http';

		$parsed = wp_parse_url( $url );

		// Reconstruct with target domain.
		$path     = $parsed['path'] ?? '/';
		$query    = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
		$fragment = isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';

		return $scheme . '://' . $targetDomain . $path . $query . $fragment;
	}

	/**
	 * {@inheritdoc}
	 */
	public function removeLanguageFromUrl( string $url ): string {
		// For domain strategy, "removing" the language means replacing
		// the domain with the default home domain.
		$defaultHost = wp_parse_url( home_url(), PHP_URL_HOST );
		$scheme      = is_ssl() ? 'https' : 'http';

		$parsed = wp_parse_url( $url );
		if ( ! isset( $parsed['host'] ) ) {
			return $url;
		}

		// Already on the default domain?
		if ( $parsed['host'] === $defaultHost ) {
			return $url;
		}

		$path     = $parsed['path'] ?? '/';
		$query    = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
		$fragment = isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';

		return $scheme . '://' . $defaultHost . $path . $query . $fragment;
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

		$reverse = $this->getReverseMap();

		return $reverse[ $host ] ?? null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDefaultHomeUrl(): string {
		return untrailingslashit( home_url() );
	}

	/**
	 * Get the language → domain mapping from settings.
	 *
	 * @return array<string, string> Language code => domain.
	 */
	public function getDomainMap(): array {
		if ( null === $this->domainMap ) {
			/** @var array<string, string> $map */
			$map = $this->options->get( 'language_domains', array() );
			$this->domainMap = $map;
		}

		return $this->domainMap;
	}

	/**
	 * Get the reverse domain → language mapping.
	 *
	 * @return array<string, string> Domain => language code.
	 */
	private function getReverseMap(): array {
		if ( null === $this->reverseMap ) {
			$this->reverseMap = array_flip( $this->getDomainMap() );
		}

		return $this->reverseMap;
	}
}
