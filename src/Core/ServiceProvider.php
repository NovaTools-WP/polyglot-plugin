<?php
/**
 * Pimple service provider for NovaTools Polyglot.
 *
 * Registers all core services as singletons or lazy factories in the
 * DI container. Services are resolved on first access.
 *
 * @package NovaTools\Polyglot\Core
 */

namespace NovaTools\Polyglot\Core;

use Pimple\Container;
use Pimple\ServiceProviderInterface;
use NovaTools\Polyglot\Support\HookManager;
use NovaTools\Polyglot\Support\OptionStore;
use NovaTools\Polyglot\Support\Cache;

defined( 'ABSPATH' ) || exit;

class ServiceProvider implements ServiceProviderInterface {

	/**
	 * Register services into the Pimple container.
	 *
	 * @param Container $container The DI container instance.
	 * @return void
	 */
	public function register( Container $container ): void {
		$this->registerSupportServices( $container );
		$this->registerLanguageServices( $container );
		$this->registerTranslationServices( $container );
		$this->registerStringServices( $container );
		$this->registerUrlServices( $container );
		$this->registerFileTranslationServices( $container );
		$this->registerLanguageSwitcherServices( $container );
		$this->registerAdminServices( $container );
		$this->registerWooCommerceServices( $container );
		$this->registerTranslationApiServices( $container );
		$this->registerRestControllers( $container );
	}

	// ── Domain-specific registration methods ─────────────────────────

	/**
	 * Register support infrastructure services.
	 *
	 * @param Container $container The DI container instance.
	 */
	private function registerSupportServices( Container $container ): void {
		$container['hooks'] = static function ( Container $c ): HookManager {
			return new HookManager();
		};

		$container['options'] = static function ( Container $c ): OptionStore {
			return new OptionStore();
		};

		$container['cache'] = static function ( Container $c ): Cache {
			return new Cache();
		};

		// Frontend locale switcher — makes WP core load the correct .mo
		// for the active language. Depends on the locale mapper.
		$container['locale.switcher'] = static function ( Container $c ): \NovaTools\Polyglot\Support\LocaleSwitcher {
			return new \NovaTools\Polyglot\Support\LocaleSwitcher( $c['locale.mapper'] );
		};
	}

	/**
	 * Register language management services.
	 *
	 * @param Container $container The DI container instance.
	 */
	private function registerLanguageServices( Container $container ): void {
		$container['language.repository'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Language\LanguageRepository( $c['cache'] );
		};

		$container['language.manager'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Language\LanguageManager(
				$c['language.repository'],
				$c['cache']
			);
		};

		$container['locale.mapper'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Language\LocaleMapper( $c['cache'] );
		};
	}

	/**
	 * Register translation services (content, post, term, custom field).
	 *
	 * @param Container $container The DI container instance.
	 */
	private function registerTranslationServices( Container $container ): void {
		$container['translation.repository'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Translation\TranslationRepository( $c['cache'] );
		};

		$container['content.translator'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Translation\ContentTranslator(
				$c['translation.repository'],
				$c['hooks'],
				$c['post.translator'],
				$c['term.translator']
			);
		};

		$container['post.translator'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Translation\PostTranslation\PostTranslator(
				$c['translation.repository']
			);
		};

		$container['post.duplication'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Translation\PostTranslation\PostDuplication(
				$c['translation.repository'],
				$c['options']
			);
		};

		$container['post.sync'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Translation\PostTranslation\PostSyncService(
				$c['translation.repository'],
				$c['options']
			);
		};

		$container['term.translator'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Translation\TermTranslation\TermTranslator(
				$c['translation.repository']
			);
		};

		$container['term.sync'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Translation\TermTranslation\TermSyncService(
				$c['translation.repository']
			);
		};

		$container['custom_field.translator'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Translation\CustomFieldTranslation\CustomFieldTranslator(
				$c['options']
			);
		};

		$container['frontend.query_filter'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Translation\PostTranslation\FrontendQueryFilter(
				$c['language.repository'],
				$c['translation.repository']
			);
		};
	}

	/**
	 * Register string translation services.
	 *
	 * @param Container $container The DI container instance.
	 */
	private function registerStringServices( Container $container ): void {
		$container['string.repository'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\String\StringRepository( $c['cache'] );
		};

		$container['string.manager'] = static function ( Container $c ) {
			$manager = new \NovaTools\Polyglot\String\StringManager(
				$c['string.repository'],
				$c['cache']
			);

			$manager->setTranslationMemory( $c['translation.memory'] );
			$manager->setPackageRepository( $c['package.repository'] );

			return $manager;
		};

		$container['gettext.override'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\String\GettextOverride(
				$c['string.repository']
			);
		};

		$container['translation.memory'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\String\TranslationMemory(
				$c['cache'],
				$c['string.repository']
			);
		};

		$container['package.repository'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\String\Package\PackageRepository( $c['cache'] );
		};
	}

	/**
	 * Register URL routing services.
	 *
	 * @param Container $container The DI container instance.
	 */
	private function registerUrlServices( Container $container ): void {
		$container['url.converter'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Url\UrlConverter(
				$c['options'],
				$c['hooks']
			);
		};

		$container['url.slug_translator'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Url\SlugTranslator( $c['cache'] );
		};

		$container['url.browser_redirect'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Url\BrowserRedirect(
				$c['options'],
				$c['url.converter']
			);
		};
	}

	/**
	 * Register file translation services (PO/MO, bundles, editor).
	 *
	 * @param Container $container The DI container instance.
	 */
	private function registerFileTranslationServices( Container $container ): void {
		$container['po.parser'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\FileTranslation\PoFileParser();
		};

		$container['po.compiler'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\FileTranslation\PoMoCompiler();
		};

		$container['string.extractor'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\FileTranslation\StringExtractor();
		};

		$container['file.discovery'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\FileTranslation\FileDiscoveryService();
		};

		$container['bundle.repository'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\FileTranslation\Bundle\BundleRepository(
				$c['file.discovery'],
				$c['cache']
			);
		};

		$container['po.editor.controller'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\FileTranslation\Editor\PoEditorController(
				$c['po.parser'],
				$c['po.compiler']
			);
		};

		$container['po.importer'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\FileTranslation\PoImporter(
				$c['po.parser']
			);
		};

		$container['po.exporter'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\FileTranslation\PoExporter(
				$c['po.parser']
			);
		};
	}

	/**
	 * Register language switcher services.
	 *
	 * @param Container $container The DI container instance.
	 */
	private function registerLanguageSwitcherServices( Container $container ): void {
		$container['language_switcher'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\LanguageSwitcher\LanguageSwitcher();
		};

		$container['switcher.widget'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\LanguageSwitcher\SwitcherWidget();
		};

		$container['switcher.shortcode'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\LanguageSwitcher\SwitcherShortcode(
				$c['language_switcher']
			);
		};

		$container['switcher.nav_menu'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\LanguageSwitcher\NavMenuIntegration(
				$c['language_switcher'],
				$c['language.repository']
			);
		};

		$container['switcher.admin_bar'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\LanguageSwitcher\AdminBarSwitcher(
				$c['language_switcher'],
				$c['language.repository']
			);
		};

		$container['switcher.block'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\LanguageSwitcher\SwitcherBlock();
		};
	}

	/**
	 * Register admin UI services.
	 *
	 * @param Container $container The DI container instance.
	 */
	private function registerAdminServices( Container $container ): void {
		$container['admin.menu_registrar'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Admin\MenuRegistrar(
				\NovaTools\Polyglot\Core\Plugin::getInstance()
			);
		};

		$container['admin.theme_plugin'] = $container->protect( static function ( Container $c ) {
			return new \NovaTools\Polyglot\Admin\ThemePluginPage(
				\NovaTools\Polyglot\Core\Plugin::getInstance()
			);
		});

		$container['admin.list_columns'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Admin\AdminListColumns(
				$c['translation.repository'],
				$c['language.repository']
			);
		};
	}

	/**
	 * Register WooCommerce integration services.
	 *
	 * @param Container $container The DI container instance.
	 */
	private function registerWooCommerceServices( Container $container ): void {
		$container['woocommerce.product_data_override'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\WooCommerce\ProductDataOverride(
				$c['translation.repository']
			);
		};

		$container['woocommerce.admin_product_fields'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\WooCommerce\AdminProductFields(
				$c['translation.repository']
			);
		};

		$container['woocommerce.product_translator'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\WooCommerce\ProductTranslator(
				$c['translation.repository']
			);
		};

		$container['woocommerce.variation_translator'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\WooCommerce\VariationTranslator(
				$c['translation.repository']
			);
		};

		$container['woocommerce.product_sync'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\WooCommerce\ProductSyncService(
				$c['translation.repository']
			);
		};

		$container['woocommerce.currency_manager'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\WooCommerce\Currency\CurrencyManager(
				$c['options'],
				$c['cache']
			);
		};

		$container['woocommerce.exchange_rates'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\WooCommerce\Currency\ExchangeRateService(
				$c['options'],
				$c['cache']
			);
		};

		$container['woocommerce.currency_switcher'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\WooCommerce\Currency\CurrencySwitcher(
				$c['woocommerce.currency_manager'],
				$c['options']
			);
		};

		$container['woocommerce.email_translator'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\WooCommerce\EmailTranslator(
				$c['translation.repository']
			);
		};

		$container['admin.product_translation_handler'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Admin\ProductTranslationHandler(
				$c['woocommerce.product_translator'],
				$c['woocommerce.variation_translator'],
				$c['translation.repository']
			);
		};

		$container['woocommerce.module'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\WooCommerce\WooCommerceModule(
				$c['woocommerce.product_data_override'],
				$c['woocommerce.admin_product_fields'],
				$c['woocommerce.product_translator'],
				$c['woocommerce.variation_translator'],
				$c['woocommerce.product_sync'],
				$c['woocommerce.currency_manager'],
				$c['woocommerce.exchange_rates'],
				$c['woocommerce.currency_switcher'],
				$c['woocommerce.email_translator']
			);
		};
	}

	/**
	 * Register the translation API (machine/AI translation) services.
	 *
	 * Wires the provider registry and the auto-translator that the
	 * `AutoTranslateController` REST endpoint and the `wp polyglot` auto-translate
	 * commands depend on. The three built-in providers (DeepL, Google, OpenAI)
	 * are instantiated inside `ProviderRegistry::all()`, so registering the
	 * registry here makes all three available automatically.
	 *
	 * @param Container $container The DI container instance.
	 */
	private function registerTranslationApiServices( Container $container ): void {
		$container['provider.registry'] = static function ( Container $c ): \NovaTools\Polyglot\TranslationApi\ProviderRegistry {
			return new \NovaTools\Polyglot\TranslationApi\ProviderRegistry( $c['hooks'], $c['options'] );
		};

		$container['auto.translator'] = static function ( Container $c ): \NovaTools\Polyglot\TranslationApi\AutoTranslator {
			return new \NovaTools\Polyglot\TranslationApi\AutoTranslator( $c['provider.registry'], $c['options'] );
		};
	}

	/**
	 * Register REST API controllers and related services.
	 *
	 * @param Container $container The DI container instance.
	 */
	private function registerRestControllers( Container $container ): void {
		$container['wpml.migrator'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Database\Migration\MigrateFromWpml();
		};

		$container['rest_api.registrar'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\RestApi\RestApiRegistrar();
		};

		$container['scan.controller'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\RestApi\ScanController(
				$c['string.extractor'],
				$c['string.manager'],
				$c['po.importer'],
				$c['file.discovery'],
				$c['string.repository']
			);
		};

		$container['auto.scanner'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\String\AutoScanner(
				$c['string.extractor'],
				$c['string.manager'],
				$c['options']
			);
		};
	}
}
