<?php
/**
 * Core Plugin bootstrap for NovaTools Polyglot.
 *
 * Singleton that holds the Pimple DI container, provides service access,
 * and orchestrates module loading. The boot() method is hooked into
 * plugins_loaded at priority 1 by the main plugin file.
 *
 * @package NovaTools\Polyglot\Core
 */

namespace NovaTools\Polyglot\Core;

use Pimple\Container;
use NovaTools\Polyglot\Compatibility\DependencyCheck;
use NovaTools\Polyglot\Support\Logger;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var static|null
	 */
	private static ?self $instance = null;

	/**
	 * Pimple DI container.
	 *
	 * @var Container|null
	 */
	private ?Container $container = null;

	/**
	 * Whether the plugin has been booted.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Get the singleton instance.
	 *
	 * @return static
	 */
	public static function getInstance(): static {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	/**
	 * Private constructor — use getInstance().
	 */
	private function __construct() {}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \LogicException
	 */
	public function __wakeup() {
		throw new \LogicException( 'Cannot unserialize singleton.' );
	}

	/**
	 * Set the service provider and initialise the container.
	 *
	 * @param ServiceProvider $provider The service provider to register.
	 * @return void
	 */
	public function setServiceProvider( ServiceProvider $provider ): void {
		$this->container = new Container();
		$this->container->register( $provider );
	}

	/**
	 * Boot the plugin.
	 *
	 * Resolves core services and registers hooks. Safe to call multiple
	 * times — only boots once.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		// Ensure container exists even if setServiceProvider was not called.
		if ( null === $this->container ) {
			$this->setServiceProvider( new ServiceProvider() );
		}

		/**
		 * Fires after the Polyglot plugin has booted.
		 *
		 * @param Container $container The DI container with all registered services.
		 */
		$this->registerServices();

		do_action( 'polyglot_booted', $this->container );
	}

	/**
	 * Resolve and register WordPress hooks for all core services.
	 *
	 * Wires up URL routing (UrlConverter, BrowserRedirect, SlugTranslator),
	 * media translation hooks, and the polyglot_active_language_codes filter
	 * so that URL strategies can discover active languages without tight
	 * coupling to the Language module.
	 *
	 * @return void
	 */
	private function registerServices(): void {
		// Provide active language codes via filter for URL strategies.
		add_filter( 'polyglot_active_language_codes', array( $this, 'filterActiveLanguageCodes' ) );

		// Wire URL routing services.
		if ( $this->has( 'url.converter' ) ) {
			$converter = $this->get( 'url.converter' );

			if ( $this->has( 'url.browser_redirect' ) ) {
				$converter->setBrowserRedirect( $this->get( 'url.browser_redirect' ) );
			}

			if ( $this->has( 'url.slug_translator' ) ) {
				$converter->setSlugTranslator( $this->get( 'url.slug_translator' ) );
			}

			$converter->register();
		}

		// Wire media translation hooks (add_attachment language assignment).
		if ( $this->has( 'media.translator' ) ) {
			$this->get( 'media.translator' )->registerHooks();
		}

		// Wire admin menu registrar (dual-mode: NovaTools / standalone).
		if ( is_admin() && $this->has( 'admin.menu_registrar' ) ) {
			$this->get( 'admin.menu_registrar' )->register();
		}

		// Wire admin list columns for translation status.
		if ( is_admin() && $this->has( 'admin.list_columns' ) ) {
			$this->get( 'admin.list_columns' )->register();
		}

		// Wire product translation handler admin action.
		if ( is_admin() && $this->has( 'admin.product_translation_handler' ) ) {
			$this->get( 'admin.product_translation_handler' )->register();
		}

		// Load optional modules (WooCommerce, …) when their host plugins load.
		$this->loadModules();

		// Register REST API routes.
		if ( $this->has( 'rest_api.registrar' ) ) {
			$this->get( 'rest_api.registrar' )->hook();
		}

		// Wire gettext override for runtime translation substitution.
		if ( $this->has( 'gettext.override' ) ) {
			$this->get( 'gettext.override' )->register();
		}

		// Wire auto-scanner for plugin/theme activation.
		if ( $this->has( 'auto.scanner' ) ) {
			$this->get( 'auto.scanner' )->register();
		}

		// Wire language switcher services.
		if ( $this->has( 'switcher.widget' ) ) {
			add_action( 'widgets_init', function () {
				$this->get( 'switcher.widget' )->register();
			} );
		}

		if ( $this->has( 'switcher.shortcode' ) ) {
			add_action( 'init', function () {
				$this->get( 'switcher.shortcode' )->register();
			} );
		}

		if ( $this->has( 'switcher.block' ) ) {
			add_action( 'init', function () {
				$this->get( 'switcher.block' )->register();
			} );
		}

		if ( $this->has( 'switcher.nav_menu' ) ) {
			$this->get( 'switcher.nav_menu' )->register();
		}

		if ( $this->has( 'switcher.admin_bar' ) ) {
			$this->get( 'switcher.admin_bar' )->register();
		}

		if ( $this->has( 'frontend.query_filter' ) ) {
			$this->get( 'frontend.query_filter' )->register();
		}
	}

	/**
	 * Load optional modules.
	 *
	 * Modules are lazy-loaded: the WooCommerce module only resolves (and thus
	 * loads any WooCommerce code) when WooCommerce is active. The check is
	 * deferred to the host plugin's own "loaded" hook so it runs after the
	 * host is ready.
	 *
	 * @return void
	 */
	private function loadModules(): void {
		// WooCommerce module — load once WooCommerce has booted.
		add_action( 'woocommerce_loaded', array( $this, 'initWooCommerceModule' ) );

		// If WooCommerce already loaded before Polyglot booted, load now.
		if ( did_action( 'woocommerce_loaded' ) ) {
			$this->initWooCommerceModule();
		}
	}

	/**
	 * Instantiate and register the WooCommerce module when active.
	 *
	 * Hooked into `woocommerce_loaded`. Safe to call when WooCommerce is
	 * absent — the module's isActive() guard prevents registration.
	 *
	 * @return void
	 */
	public function initWooCommerceModule(): void {
		if ( ! $this->has( 'woocommerce.module' ) ) {
			return;
		}

		try {
			$module = $this->get( 'woocommerce.module' );

			if ( $module instanceof ModuleInterface && $module->isActive() ) {
				$module->register();
			}
		} catch ( \Throwable $e ) {
			// A module must never break the site. Log but continue.
			Logger::error( 'WooCommerce module init failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Filter callback: provide active language codes from the Language repository.
	 *
	 * @param string[] $codes Default (empty) array.
	 * @return string[] Active language codes.
	 */
	public function filterActiveLanguageCodes( array $codes ): array {
		if ( ! $this->has( 'language.repository' ) ) {
			return $codes;
		}

		try {
			/** @var \NovaTools\Polyglot\Language\LanguageRepository $repo */
			$repo    = $this->get( 'language.repository' );
			$active  = $repo->getActive();

			return array_keys( $active );
		} catch ( \Throwable $e ) {
			Logger::error( 'filterActiveLanguageCodes: ' . $e->getMessage() );
			return $codes;
		}
	}

	/**
	 * Retrieve a service from the DI container.
	 *
	 * @param string $service Service identifier.
	 * @return mixed The resolved service instance.
	 * @throws \RuntimeException If the container is not initialised.
	 */
	public function get( string $service ): mixed {
		if ( null === $this->container ) {
			throw new \RuntimeException(
				'Polyglot DI container not initialised. Call setServiceProvider() or boot() first.'
			);
		}

		return $this->container[ $service ];
	}

	/**
	 * Check whether a service is registered in the container.
	 *
	 * @param string $service Service identifier.
	 * @return bool
	 */
	public function has( string $service ): bool {
		if ( null === $this->container ) {
			return false;
		}

		return isset( $this->container[ $service ] );
	}

	/**
	 * Return the raw Pimple container (for advanced use).
	 *
	 * @return Container|null
	 */
	public function getContainer(): ?Container {
		return $this->container;
	}
}
