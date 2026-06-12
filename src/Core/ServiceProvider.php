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
		// ── Support services ────────────────────────────────────────

		$container['hooks'] = static function ( Container $c ): HookManager {
			return new HookManager();
		};

		$container['options'] = static function ( Container $c ): OptionStore {
			return new OptionStore();
		};

		$container['cache'] = static function ( Container $c ): Cache {
			return new Cache();
		};

		// ── Core services (lazy — only instantiated when accessed) ──

		// Language services.
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

		// Translation services.
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

		// Post translation services.
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
				$c['translation.repository']
			);
		};

		// Term translation services.
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

		// Custom field translation service.
		$container['custom_field.translator'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Translation\CustomFieldTranslation\CustomFieldTranslator(
				$c['options']
			);
		};

		// String translation services.
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

		// URL routing services.
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

		// String translation services (extended).
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

		// Translation API services.
		$container['provider.registry'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\TranslationApi\ProviderRegistry( $c['hooks'], $c['options'] );
		};

		$container['auto.translator'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\TranslationApi\AutoTranslator(
				$c['provider.registry'],
				$c['options']
			);
		};

		// Media translation services.
		$container['media.repository'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Media\MediaRepository( $c['cache'] );
		};

		$container['media.translator'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Media\MediaTranslator(
				$c['media.repository'],
				$c['translation.repository'],
				$c['cache']
			);
		};

		$container['media.sync'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Media\MediaSyncService(
				$c['media.translator'],
				$c['media.repository'],
				$c['translation.repository']
			);
		};

		// File translation services.
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

		// Language switcher services.
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

		// Admin services.
		$container['admin.menu_registrar'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Admin\MenuRegistrar(
				\NovaTools\Polyglot\Core\Plugin::getInstance()
			);
		};

		$container['admin.dashboard'] = $container->protect( static function ( Container $c ) {
			return new \NovaTools\Polyglot\Admin\DashboardPage(
				\NovaTools\Polyglot\Core\Plugin::getInstance()
			);
		});

		$container['admin.languages'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Admin\LanguageSettingsPage(
				\NovaTools\Polyglot\Core\Plugin::getInstance()
			);
		};

		$container['admin.translation_editor'] = $container->protect( static function ( Container $c ) {
			return new \NovaTools\Polyglot\Admin\TranslationEditorPage(
				\NovaTools\Polyglot\Core\Plugin::getInstance()
			);
		});

		$container['admin.theme_plugin'] = $container->protect( static function ( Container $c ) {
			return new \NovaTools\Polyglot\Admin\ThemePluginPage(
				\NovaTools\Polyglot\Core\Plugin::getInstance()
			);
		});

		$container['admin.settings'] = $container->protect( static function ( Container $c ) {
			return new \NovaTools\Polyglot\Admin\SettingsPage(
				\NovaTools\Polyglot\Core\Plugin::getInstance()
			);
		});

		$container['admin.import_wpml'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Admin\ImportWpmlPage(
				\NovaTools\Polyglot\Core\Plugin::getInstance()
			);
		};

		$container['admin.list_columns'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Admin\AdminListColumns(
				$c['translation.repository'],
				$c['language.repository']
			);
		};

		// WooCommerce module (lazy — only resolved when WooCommerce is active,
		// so zero WooCommerce code is loaded otherwise).
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


		// WPML migration service.
		$container['wpml.migrator'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\Database\Migration\MigrateFromWpml();
		};
		// REST API registrar.
		$container['rest_api.registrar'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\RestApi\RestApiRegistrar();
		};

		// Scan controller for string scanning endpoints.
		$container['scan.controller'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\RestApi\ScanController(
				$c['string.extractor'],
				$c['string.manager'],
				$c['po.importer'],
				$c['file.discovery'],
				$c['string.repository']
			);
		};

		// Auto-scanner for plugin/theme activation.
		$container['auto.scanner'] = static function ( Container $c ) {
			return new \NovaTools\Polyglot\String\AutoScanner(
				$c['string.extractor'],
				$c['string.manager'],
				$c['options']
			);
		};
	}
}
