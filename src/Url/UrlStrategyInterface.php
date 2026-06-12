<?php
/**
 * URL strategy interface for NovaTools Polyglot.
 *
 * Defines the contract for all URL negotiation strategies. Each strategy
 * handles how language information is encoded in and extracted from URLs.
 *
 * Implemented by:
 *   - DirectoryStrategy  (e.g. /en/about/)
 *   - SubdomainStrategy  (e.g. en.example.com/about/)
 *   - DomainStrategy     (e.g. example.fr/about/)
 *   - QueryParamStrategy (e.g. /about/?lang=en)
 *
 * @package NovaTools\Polyglot\Url
 */

namespace NovaTools\Polyglot\Url;

defined( 'ABSPATH' ) || exit;

interface UrlStrategyInterface {

	/**
	 * Add a language segment to the given URL.
	 *
	 * @param string $url      The original URL (absolute or relative).
	 * @param string $language The target language code (e.g. 'fr').
	 * @return string The URL with the language information applied.
	 */
	public function addLanguageToUrl( string $url, string $language ): string;

	/**
	 * Remove the language segment from the given URL.
	 *
	 * Useful for obtaining the "neutral" version of a language-prefixed URL.
	 *
	 * @param string $url The language-aware URL.
	 * @return string The URL without language information.
	 */
	public function removeLanguageFromUrl( string $url ): string;

	/**
	 * Detect the language code from the current request URL.
	 *
	 * Inspects the request URI, host, or query parameters depending on
	 * the strategy to determine which language the visitor is requesting.
	 *
	 * @return string|null Language code if detected, null otherwise.
	 */
	public function getLanguageFromUrl(): ?string;

	/**
	 * Get the home URL for the default language (without any language prefix).
	 *
	 * @return string
	 */
	public function getDefaultHomeUrl(): string;
}
