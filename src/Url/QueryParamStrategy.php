<?php
/**
 * Query-parameter URL strategy for NovaTools Polyglot.
 *
 * Appends a ?lang=xx query parameter to the URL:
 *   - /about/?lang=en  → English
 *   - /about/?lang=fr  → French
 *
 * This is the simplest strategy and requires no rewrite rules.
 * Useful for development or when server configuration prevents
 * directory/subdomain/domain strategies.
 *
 * @package NovaTools\Polyglot\Url
 */

namespace NovaTools\Polyglot\Url;

defined( 'ABSPATH' ) || exit;

class QueryParamStrategy implements UrlStrategyInterface {

	/**
	 * The query parameter name used to indicate language.
	 *
	 * @var string
	 */
	const LANG_PARAM = 'lang';

	/**
	 * {@inheritdoc}
	 */
	public function addLanguageToUrl( string $url, string $language ): string {
		// Remove any existing lang parameter.
		$url = $this->removeLanguageFromUrl( $url );

		// Parse URL to handle query string and fragment correctly.
		$parsed = wp_parse_url( $url );

		$path     = $parsed['path'] ?? '/';
		$query    = $parsed['query'] ?? '';
		$fragment = isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';

		// Build new query string.
		$langParam = self::LANG_PARAM . '=' . urlencode( $language );

		if ( '' !== $query ) {
			$query = $query . '&' . $langParam;
		} else {
			$query = $langParam;
		}

		// Rebuild — keep scheme/host if present.
		if ( isset( $parsed['scheme'] ) && isset( $parsed['host'] ) ) {
			$scheme   = $parsed['scheme'];
			$host     = $parsed['host'];
			$port     = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
			$user     = isset( $parsed['user'] ) ? $parsed['user'] : '';
			$pass     = isset( $parsed['pass'] ) ? ':' . $parsed['pass'] : '';
			$creds    = ( '' !== $user ) ? $user . $pass . '@' : '';

			return $scheme . '://' . $creds . $host . $port . $path . '?' . $query . $fragment;
		}

		return $path . '?' . $query . $fragment;
	}

	/**
	 * {@inheritdoc}
	 */
	public function removeLanguageFromUrl( string $url ): string {
		// Handle fragment — parse_url can't reliably separate query and
		// fragment when the query already contains '&', so work with the
		// raw URL string.

		// Split off fragment first.
		$parts     = explode( '#', $url, 2 );
		$main      = $parts[0];
		$fragment  = isset( $parts[1] ) ? '#' . $parts[1] : '';

		// Split query from path.
		$queryParts = explode( '?', $main, 2 );
		$base       = $queryParts[0];

		if ( ! isset( $queryParts[1] ) ) {
			return $url; // No query string at all.
		}

		// Remove the lang parameter from the query string.
		$params = array();
		parse_str( $queryParts[1], $params );
		unset( $params[ self::LANG_PARAM ] );

		// Rebuild.
		if ( ! empty( $params ) ) {
			return $base . '?' . http_build_query( $params ) . $fragment;
		}

		return $base . $fragment;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getLanguageFromUrl(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::LANG_PARAM ] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = sanitize_text_field( wp_unslash( $_GET[ self::LANG_PARAM ] ) );

		if ( '' === $code ) {
			return null;
		}

		/**
		 * Filter to validate the detected language code from the query param.
		 *
		 * @param string|null $valid The validated language code, or null if invalid.
		 * @param string      $code  The raw language code from the query param.
		 */
		return apply_filters( 'polyglot_validate_language_code', $code, $code );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDefaultHomeUrl(): string {
		return untrailingslashit( home_url() );
	}
}
