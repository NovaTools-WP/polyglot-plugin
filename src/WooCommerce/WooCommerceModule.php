<?php
/**
 * WooCommerce module for NovaTools Polyglot.
 *
 * The integration point for all WooCommerce multilingual features. Implements
 * ModuleInterface so it is only instantiated when WooCommerce is active (see
 * isActive()). On register() it wires every sub-component — product and
 * variation translation, product synchronisation, the multi-currency system,
 * exchange-rate scheduling, the currency switcher, and email translation.
 *
 * @package NovaTools\Polyglot\WooCommerce
 */

namespace NovaTools\Polyglot\WooCommerce;

use NovaTools\Polyglot\Core\ModuleInterface;
use NovaTools\Polyglot\WooCommerce\Currency\CurrencyManager;
use NovaTools\Polyglot\WooCommerce\Currency\CurrencySwitcher;
use NovaTools\Polyglot\WooCommerce\Currency\ExchangeRateService;

defined( 'ABSPATH' ) || exit;

class WooCommerceModule implements ModuleInterface {

	/**
	 * Product data override instance.
	 *
	 * @var ProductDataOverride
	 */
	private ProductDataOverride $productDataOverride;

	/**
	 * Admin product fields instance.
	 *
	 * @var AdminProductFields
	 */
	private AdminProductFields $adminProductFields;

	/**
	 * Product translator instance.
	 *
	 * @var ProductTranslator
	 */
	private ProductTranslator $productTranslator;

	/**
	 * Variation translator instance.
	 *
	 * @var VariationTranslator
	 */
	private VariationTranslator $variationTranslator;

	/**
	 * Product synchronisation service.
	 *
	 * @var ProductSyncService
	 */
	private ProductSyncService $productSync;

	/**
	 * Multi-currency manager (covers price conversion, custom prices, shipping).
	 *
	 * @var CurrencyManager
	 */
	private CurrencyManager $currencyManager;

	/**
	 * Exchange-rate service.
	 *
	 * @var ExchangeRateService
	 */
	private ExchangeRateService $exchangeRates;

	/**
	 * Currency switcher (widget, shortcode, block).
	 *
	 * @var CurrencySwitcher
	 */
	private CurrencySwitcher $currencySwitcher;

	/**
	 * Order email translator.
	 *
	 * @var EmailTranslator
	 */
	private EmailTranslator $emailTranslator;

	/**
	 * Whether the module's hooks have already been registered.
	 *
	 * Guards against double-registration if the loader fires more than once.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Constructor.
	 *
	 * @param ProductDataOverride  $productDataOverride  Product data override.
	 * @param AdminProductFields   $adminProductFields   Admin product fields.
	 * @param ProductTranslator    $productTranslator    Product translator.
	 * @param VariationTranslator  $variationTranslator  Variation translator.
	 * @param ProductSyncService   $productSync          Product sync service.
	 * @param CurrencyManager      $currencyManager      Multi-currency manager.
	 * @param ExchangeRateService  $exchangeRates        Exchange-rate service.
	 * @param CurrencySwitcher     $currencySwitcher     Currency switcher.
	 * @param EmailTranslator      $emailTranslator      Order email translator.
	 */
	public function __construct(
		ProductDataOverride $productDataOverride,
		AdminProductFields $adminProductFields,
		ProductTranslator $productTranslator,
		VariationTranslator $variationTranslator,
		ProductSyncService $productSync,
		CurrencyManager $currencyManager,
		ExchangeRateService $exchangeRates,
		CurrencySwitcher $currencySwitcher,
		EmailTranslator $emailTranslator
	) {
		$this->productDataOverride   = $productDataOverride;
		$this->adminProductFields    = $adminProductFields;
		$this->productTranslator     = $productTranslator;
		$this->variationTranslator   = $variationTranslator;
		$this->productSync           = $productSync;
		$this->currencyManager       = $currencyManager;
		$this->exchangeRates         = $exchangeRates;
		$this->currencySwitcher      = $currencySwitcher;
		$this->emailTranslator       = $emailTranslator;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The module is only active when WooCommerce is present and loaded.
	 */
	public function isActive(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function getDependencies(): array {
		return array();
	}

	/**
	 * Register all WooCommerce hooks via the sub-components.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		$this->registered = true;

		$this->productDataOverride->register();
		$this->adminProductFields->register();
		$this->productTranslator->register();
		$this->variationTranslator->register();
		$this->productSync->register();
		$this->currencyManager->register();
		$this->exchangeRates->register();
		$this->currencySwitcher->register();
		$this->emailTranslator->register();

		/**
		 * Fires after the Polyglot WooCommerce module has registered all hooks.
		 *
		 * Allows other code to attach WooCommerce-specific behaviour once the
		 * module is fully wired.
		 */
		do_action( 'polyglot_woocommerce_module_loaded' );
	}
}
