<?php
/**
 * URL converter facade for NovaTools Polyglot.
 *
 * Central service that delegates URL manipulation to the active strategy
 * (directory, subdomain, domain, or query parameter). Hooks into WordPress
 * filters to transparently add language information to all generated URLs.
 *
 * Also orchestrates hook registration for BrowserRedirect and SlugTranslator,
 * keeping all URL-routing wiring in one place.
 *
 * Registered as a singleton in the DI container.
 *
 * @package NovaTools\Polyglot\Url
 */

namespace NovaTools\Polyglot\Url;

use NovaTools\Polyglot\Support\HookManager;
use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class UrlConverter {

	/**
	 * Hook manager for registering WordPress filters.
	 *
	 * @var HookManager
	 */
	private HookManager $hooks;

	/**
	 * Option store for reading URL strategy settings.
	 *
	 * @var OptionStore
	 */
	private OptionStore $options;

	/**
	 * The resolved URL strategy instance.
	 *
	 * @var UrlStrategyInterface|null
	 */
	private ?UrlStrategyInterface $strategy = null;

	/**
	 * The current frontend language, resolved from the URL.
	 *
	 * @var string|null
	 */
	private ?string $currentLanguage = null;

	/**
	 * Re-entry guard for the home_url filter.
	 *
	 * Prevents infinite recursion when addLanguageToUrl() calls home_url()
	 * internally, which would re-trigger this filter.
	 *
	 * @var bool
	 */
	private bool $inHomeUrlFilter = false;

	/**
	 * Browser redirect service, set by Plugin::boot().
	 *
	 * @var BrowserRedirect|null
	 */
	private ?BrowserRedirect $browserRedirect = null;

	/**
	 * Slug translator service, set by Plugin::boot().
	 *
	 * @var SlugTranslator|null
	 */
	private ?SlugTranslator $slugTranslator = null;

	/**
	 * Strategy class map — setting value to FQN.
	 *
	 * @var array<string, class-string<UrlStrategyInterface>>
	 */
	const STRATEGY_MAP = array(
		'directory'   => DirectoryStrategy::class,
		'subdomain'   => SubdomainStrategy::class,
		'domain'      => DomainStrategy::class,
		'query_param' => QueryParamStrategy::class,
	);

	/**
	 * Constructor.
	 *
	 * @param OptionStore $options Settings accessor.
	 * @param HookManager $hooks   Hook manager.
	 */
	public function __construct( OptionStore $options, HookManager $hooks ) {
		$this->options = $options;
		$this->hooks   = $hooks;
	}

	/**
	 * Set the browser redirect service for orchestration.
	 *
	 * Called by Plugin::boot() to wire the dependency without
	 * creating a circular reference in the DI container.
	 *
	 * @param BrowserRedirect $browserRedirect The browser redirect service.
	 * @return void
	 */
	public function setBrowserRedirect( BrowserRedirect $browserRedirect ): void {
		$this->browserRedirect = $browserRedirect;
	}

	/**
	 * Set the slug translator service for orchestration.
	 *
	 * Called by Plugin::boot() to wire the dependency without
	 * creating a circular reference in the DI container.
	 *
	 * @param SlugTranslator $slugTranslator The slug translator service.
	 * @return void
	 */
	public function setSlugTranslator( SlugTranslator $slugTranslator ): void {
		$this->slugTranslator = $slugTranslator;
	}

	/**
	 * Register WordPress hooks for URL conversion and related services.
	 *
	 * Orchestrates hook registration for the URL converter itself,
	 * the browser-language redirect, and the slug translator.
	 * Should be called during plugin boot.
	 *
	 * @return void
	 */
	public function register(): void {
		// Filter all home_url() calls to include language prefix.
		$this->hooks->addFilter( 'home_url', array( $this, 'filterHomeUrl' ), 10, 2, 'url' );

		// Modify rewrite rules to account for language directories.
		$this->hooks->addFilter( 'rewrite_rules_array', array( $this, 'filterRewriteRules' ), 10, 1, 'url' );

		// Detect language from URL on every request.
		$this->hooks->addAction( 'parse_request', array( $this, 'onParseRequest' ), 1, 1, 'url' );

		// Ensure trailing slash consistency on redirected URLs.
		$this->hooks->addFilter( 'redirect_canonical', array( $this, 'filterRedirectCanonical' ), 10, 2, 'url' );

		// Register browser-language redirect on template_redirect.
		if ( null !== $this->browserRedirect ) {
			add_action( 'template_redirect', array( $this->browserRedirect, 'maybeRedirect' ), 1 );
		}

		// Register slug translator permalink filters.
		if ( null !== $this->slugTranslator ) {
			add_filter( 'post_link', array( $this->slugTranslator, 'filterPermalink' ), 10, 3 );
			add_filter( 'post_type_link', array( $this->slugTranslator, 'filterPermalink' ), 10, 3 );
		}
	}

	/**
	 * Get the active URL strategy instance.
	 *
	 * Lazy-resolved from the "url_strategy" setting.
	 *
	 * @return UrlStrategyInterface
	 */
	public function getStrategy(): UrlStrategyInterface {
		if ( null === $this->strategy ) {
			$url_strategy = $this->options->get( 'url_strategy', 'directory' );
			$type = is_array( $url_strategy ) ? ( $url_strategy['method'] ?? 'directory' ) : $url_strategy;

			$class = self::STRATEGY_MAP[ $type ] ?? DirectoryStrategy::class;

			$this->strategy = new $class( $this->options );
		}

		return $this->strategy;
	}

	/**
	 * Override the strategy (useful for testing or admin override).
	 *
	 * @param UrlStrategyInterface $strategy The strategy to use.
	 * @return void
	 */
	public function setStrategy( UrlStrategyInterface $strategy ): void {
		$this->strategy = $strategy;
	}

	// ── Public API helpers ────────────────────────────────────────────────

	/**
	 * Convert a URL to a specific language.
	 *
	 * @param string      $url      The source URL.
	 * @param string|null $language Target language code (defaults to current).
	 * @return string
	 */
	public function convert( string $url, ?string $language = null ): string {
		$language = $language ?? $this->getCurrentLanguage();

		if ( '' === $language ) {
			return $url;
		}

		return $this->getStrategy()->addLanguageToUrl( $url, $language );
	}

	/**
	 * Get the language detected from the current request URL.
	 *
	 * @return string Language code, or the default language if undetected.
	 */
	public function getCurrentLanguage(): string {
		if ( null === $this->currentLanguage ) {
			$this->currentLanguage = $this->getStrategy()->getLanguageFromUrl()
				?? $this->options->get( 'default_language', '' );
		}

		return $this->currentLanguage;
	}

	/**
	 * Set the current language explicitly.
	 *
	 * @param string $language Language code.
	 * @return void
	 */
	public function setCurrentLanguage( string $language ): void {
		$this->currentLanguage = $language;
	}

	/**
	 * Get the home URL for a specific language.
	 *
	 * @param string|null $language Target language (defaults to current).
	 * @return string
	 */
	public function getHomeUrl( ?string $language = null ): string {
		$language = $language ?? $this->getCurrentLanguage();
		$home     = $this->getStrategy()->getDefaultHomeUrl();

		return $this->getStrategy()->addLanguageToUrl( $home, $language );
	}

	// ── WordPress filter / action callbacks ───────────────────────────────

	/**
	 * Filter: home_url — add language prefix to all home URLs.
	 *
	 * @param string      $url   The complete home URL.
	 * @param string|null $path  Path relative to home URL.
	 * @return string
	 */
	public function filterHomeUrl( string $url, ?string $path ): string {
		// Don't modify admin URLs.
		if ( is_admin() ) {
			return $url;
		}

		// Prevent infinite recursion: addLanguageToUrl() may call home_url()
		// internally (e.g. to compare base URLs), which re-triggers this filter.
		if ( $this->inHomeUrlFilter ) {
			return $url;
		}

		$language = $this->getCurrentLanguage();

		if ( '' === $language ) {
			return $url;
		}

		$this->inHomeUrlFilter = true;
		try {
			$result = $this->getStrategy()->addLanguageToUrl( $url, $language );
		} finally {
			$this->inHomeUrlFilter = false;
		}

		return $result;
	}

	/**
	 * Filter: rewrite_rules_array — inject language-directory rewrite rules.
	 *
	 * For directory strategy, delegates to DirectoryStrategy::generateRewriteRules()
	 * to create language-prefixed copies of every WordPress rewrite rule.
	 *
	 * @param array $rules Existing rewrite rules.
	 * @return array
	 */
	public function filterRewriteRules( array $rules ): array {
		$strategy = $this->getStrategy();

		// Only directory strategy needs rewrite rule modifications.
		if ( ! $strategy instanceof DirectoryStrategy ) {
			return $rules;
		}

		$langRules = $strategy->generateRewriteRules( $rules );

		/**
		 * Filter the language-directory rewrite rules.
		 *
		 * @param array             $langRules Language-prefixed rewrite rules.
		 * @param array             $rules     Original WordPress rewrite rules.
		 * @param DirectoryStrategy $strategy  The active directory strategy.
		 */
		return apply_filters( 'polyglot_directory_rewrite_rules', $langRules, $rules, $strategy );
	}

	/**
	 * Action: parse_request — detect and strip language from the request.
	 *
	 * @param \WP $wp The WordPress request object.
	 * @return void
	 */
	public function onParseRequest( \WP $wp ): void {
		$strategy = $this->getStrategy();
		$lang     = $strategy->getLanguageFromUrl();

		if ( null !== $lang ) {
			$this->setCurrentLanguage( $lang );

			// For directory strategy, strip the language segment so WordPress
			// can route to the correct post/page.
			if ( $strategy instanceof DirectoryStrategy ) {
				$request_uri = isset( $_SERVER['REQUEST_URI'] )
					? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
					: '';

				$clean = $strategy->removeLanguageFromUrl( $request_uri );
				// Update the request URI so WordPress processes the path without the lang prefix.
				$wp->request = trim( wp_parse_url( $clean, PHP_URL_PATH ) ?? '', '/' );
			}
		}
	}

	/**
	 * Filter: redirect_canonical — preserve language prefix on redirects.
	 *
	 * @param string $redirect_url  The proposed redirect URL.
	 * @param string $requested_url The originally requested URL.
	 * @return string
	 */
	public function filterRedirectCanonical( string $redirect_url, string $requested_url ): string {
		// Don't interfere with admin redirects.
		if ( is_admin() ) {
			return $redirect_url;
		}

		$language = $this->getCurrentLanguage();

		if ( '' === $language ) {
			return $redirect_url;
		}

		// Ensure the redirect URL includes the language prefix.
		return $this->getStrategy()->addLanguageToUrl( $redirect_url, $language );
	}
}
