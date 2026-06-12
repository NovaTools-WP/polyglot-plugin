<?php
/**
 * Multi-currency manager for NovaTools Polyglot.
 *
 * Resolves the active currency (by language or by customer geolocation),
 * converts prices on display, supports manual per-product custom prices, and
 * converts shipping rates to the active currency. Exchange rates are stored
 * relative to the default (base) currency inside the `polyglot_settings`
 * option; converting between two currencies uses the ratio of their rates.
 *
 * @package NovaTools\Polyglot\WooCommerce\Currency
 */

namespace NovaTools\Polyglot\WooCommerce\Currency;

use NovaTools\Polyglot\Support\Cache;
use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class CurrencyManager {

	/**
	 * Select products by the active language's currency.
	 */
	const MODE_BY_LANGUAGE = 'by_language';

	/**
	 * Select the currency from the visitor's geolocated country.
	 */
	const MODE_BY_LOCATION = 'by_location';

	/**
	 * Option store.
	 *
	 * @var OptionStore
	 */
	private OptionStore $options;

	/**
	 * Cache wrapper.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Memoised active currency for the current request.
	 *
	 * @var string|null
	 */
	private ?string $activeCurrency = null;

	/**
	 * Whether a custom-price conversion pass is in progress.
	 *
	 * Prevents re-entrancy when reading product meta to resolve a custom price.
	 *
	 * @var bool
	 */
	private bool $resolving = false;

	/**
	 * Constructor.
	 *
	 * @param OptionStore $options Option store.
	 * @param Cache       $cache   Cache wrapper.
	 */
	public function __construct( OptionStore $options, Cache $cache ) {
		$this->options = $options;
		$this->cache   = $cache;
	}

	/**
	 * Register the WooCommerce price and shipping conversion hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->isEnabled() ) {
			return;
		}

		// Price conversion (covers custom prices + automatic conversion).
		add_filter( 'woocommerce_product_get_price', array( $this, 'filterPrice' ), 10, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( $this, 'filterRegularPrice' ), 10, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'filterSalePrice' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'filterPrice' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'filterRegularPrice' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'filterSalePrice' ), 10, 2 );

		// Shipping rate conversion.
		add_filter( 'woocommerce_package_rates', array( $this, 'filterShippingRates' ), 10, 2 );

		// Persist a switcher selection across requests.
		add_action( 'init', array( $this, 'applySelectedCurrency' ), 5 );

		/**
		 * Fires after the multi-currency manager has registered its hooks.
		 */
		do_action( 'polyglot_woocommerce_currency_registered', $this );
	}

	/**
	 * Whether multi-currency is enabled.
	 *
	 * @return bool
	 */
	public function isEnabled(): bool {
		return (bool) $this->options->get( 'wc.currency.enabled', true );
	}

	/**
	 * Get the configured currency-selection mode.
	 *
	 * @return string One of the MODE_* constants. Defaults to by_language.
	 */
	public function getMode(): string {
		$mode = $this->options->get( 'wc.currency.mode', self::MODE_BY_LANGUAGE );

		return in_array( $mode, array( self::MODE_BY_LANGUAGE, self::MODE_BY_LOCATION ), true )
			? $mode
			: self::MODE_BY_LANGUAGE;
	}

	/**
	 * Get the default (base) currency code.
	 *
	 * @return string Currency code (e.g. "USD"). Falls back to WooCommerce's
	 *                configured currency.
	 */
	public function getDefaultCurrency(): string {
		$default = $this->options->get( 'wc.currency.default', '' );

		if ( $default ) {
			return $default;
		}

		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
	}

	/**
	 * Get the list of enabled currency codes.
	 *
	 * Always includes the default currency.
	 *
	 * @return string[]
	 */
	public function getEnabledCurrencies(): array {
		$enabled = $this->options->get( 'wc.currency.enabled_currencies', array() );
		$enabled = is_array( $enabled ) ? $enabled : array();

		$default = $this->getDefaultCurrency();

		if ( ! in_array( $default, $enabled, true ) ) {
			array_unshift( $enabled, $default );
		}

		return array_values( $enabled );
	}

	/**
	 * Resolve the active currency for the current request.
	 *
	 * Resolution order: switcher selection → geolocation (by_location mode) →
	 * language mapping → default. The result is memoised per request.
	 *
	 * @return string Currency code.
	 */
	public function getActiveCurrency(): string {
		if ( null !== $this->activeCurrency ) {
			return $this->activeCurrency;
		}

		$enabled = $this->getEnabledCurrencies();
		$default = $this->getDefaultCurrency();

		// 1. Explicit switcher selection (cookie / request param).
		$selected = $this->getSelectedCurrency();

		if ( $selected && ( empty( $enabled ) || in_array( $selected, $enabled, true ) ) ) {
			$this->activeCurrency = $selected;
			return $selected;
		}

		// 2. Geolocation-based currency.
		if ( self::MODE_BY_LOCATION === $this->getMode() ) {
			$byLocation = $this->getCurrencyByLocation();

			if ( $byLocation ) {
				$this->activeCurrency = $byLocation;
				return $byLocation;
			}
		}

		// 3. Language-mapped currency.
		$byLanguage = $this->getCurrencyByLanguage();

		if ( $byLanguage ) {
			$this->activeCurrency = $byLanguage;
			return $byLanguage;
		}

		$this->activeCurrency = $default;
		return $default;
	}

	/**
	 * Get the stored exchange rate of a currency relative to the default base.
	 *
	 * @param string $currency Currency code.
	 * @return float Rate (1.0 for the default currency, 0 if unknown).
	 */
	public function getRate( string $currency ): float {
		if ( $currency === $this->getDefaultCurrency() ) {
			return 1.0;
		}

		$rates = $this->options->get( 'wc.currency.rates', array() );
		$rates = is_array( $rates ) ? $rates : array();

		return isset( $rates[ $currency ] ) ? (float) $rates[ $currency ] : 0.0;
	}

	/**
	 * Get the exchange rate between two currencies.
	 *
	 * @param string $from Source currency code.
	 * @param string $to   Target currency code.
	 * @return float Conversion factor. 1.0 when either rate is missing.
	 */
	public function getExchangeRate( string $from, string $to ): float {
		if ( $from === $to ) {
			return 1.0;
		}

		$fromRate = $this->getRate( $from );
		$toRate   = $this->getRate( $to );

		if ( $fromRate <= 0 ) {
			return 1.0;
		}

		return $toRate / $fromRate;
	}

	/**
	 * Convert an amount between two currencies.
	 *
	 * @param float  $amount Amount in the source currency.
	 * @param string $from   Source currency code.
	 * @param string $to     Target currency code.
	 * @return float Converted amount.
	 */
	public function convert( float $amount, string $from, string $to ): float {
		return $amount * $this->getExchangeRate( $from, $to );
	}

	/**
	 * Convert an amount from the default currency to the active currency.
	 *
	 * @param float $amount Amount in the default currency.
	 * @return float Amount in the active currency.
	 */
	public function convertToActive( float $amount ): float {
		return $this->convert( $amount, $this->getDefaultCurrency(), $this->getActiveCurrency() );
	}

	// ── Custom prices per currency (manual overrides) ───────────────────────

	/**
	 * Get a manually-set regular price for a product in a currency.
	 *
	 * @param int    $productId Product ID.
	 * @param string $currency  Currency code.
	 * @return float|null Custom price, or null if none set.
	 */
	public function getCustomPrice( int $productId, string $currency ): ?float {
		$value = get_post_meta( $productId, '_polyglot_price_' . $currency, true );

		return '' === $value ? null : (float) $value;
	}

	/**
	 * Get a manually-set sale price for a product in a currency.
	 *
	 * @param int    $productId Product ID.
	 * @param string $currency  Currency code.
	 * @return float|null Custom sale price, or null if none set.
	 */
	public function getCustomSalePrice( int $productId, string $currency ): ?float {
		$value = get_post_meta( $productId, '_polyglot_sale_price_' . $currency, true );

		return '' === $value ? null : (float) $value;
	}

	/**
	 * Set a manual regular (and optional sale) price for a product per currency.
	 *
	 * @param int       $productId Product ID.
	 * @param string    $currency  Currency code.
	 * @param float     $regular   Manual regular price.
	 * @param float|null $sale     Manual sale price, or null to clear it.
	 * @return void
	 */
	public function setCustomPrice( int $productId, string $currency, float $regular, ?float $sale = null ): void {
		update_post_meta( $productId, '_polyglot_price_' . $currency, $regular );

		if ( null === $sale ) {
			delete_post_meta( $productId, '_polyglot_sale_price_' . $currency );
		} else {
			update_post_meta( $productId, '_polyglot_sale_price_' . $currency, $sale );
		}
	}

	/**
	 * Remove all manual prices for a product in a currency.
	 *
	 * @param int    $productId Product ID.
	 * @param string $currency  Currency code.
	 * @return void
	 */
	public function deleteCustomPrice( int $productId, string $currency ): void {
		delete_post_meta( $productId, '_polyglot_price_' . $currency );
		delete_post_meta( $productId, '_polyglot_sale_price_' . $currency );
	}

	// ── WooCommerce filter callbacks ────────────────────────────────────────

	/**
	 * Filter the effective product price for the active currency.
	 *
	 * Honours a manual sale price (or regular price) before applying automatic
	 * conversion. No-op in the default currency.
	 *
	 * @param mixed      $price   Base price (default currency).
	 * @param \WC_Product $product Product object.
	 * @return mixed Converted or overridden price.
	 */
	public function filterPrice( $price, $product ) {
		if ( $this->resolving || null === $price || '' === $price ) {
			return $price;
		}

		$active  = $this->getActiveCurrency();
		$default = $this->getDefaultCurrency();

		// Manual override takes precedence over automatic conversion.
		$this->resolving = true;
		$customSale = $this->getCustomSalePrice( $product->get_id(), $active );
		$this->resolving = false;

		if ( null !== $customSale ) {
			return $customSale;
		}

		$this->resolving = true;
		$customRegular = $this->getCustomPrice( $product->get_id(), $active );
		$this->resolving = false;

		if ( null !== $customRegular ) {
			return $customRegular;
		}

		if ( $active === $default ) {
			return $price;
		}

		return (float) $price * $this->getExchangeRate( $default, $active );
	}

	/**
	 * Filter the regular price for the active currency.
	 *
	 * @param mixed      $price   Base regular price.
	 * @param \WC_Product $product Product object.
	 * @return mixed
	 */
	public function filterRegularPrice( $price, $product ) {
		if ( null === $price || '' === $price ) {
			return $price;
		}

		$active        = $this->getActiveCurrency();
		$customRegular = $this->getCustomPrice( $product->get_id(), $active );

		if ( null !== $customRegular ) {
			return $customRegular;
		}

		$default = $this->getDefaultCurrency();

		if ( $active === $default ) {
			return $price;
		}

		return (float) $price * $this->getExchangeRate( $default, $active );
	}

	/**
	 * Filter the sale price for the active currency.
	 *
	 * @param mixed      $price   Base sale price.
	 * @param \WC_Product $product Product object.
	 * @return mixed
	 */
	public function filterSalePrice( $price, $product ) {
		if ( null === $price || '' === $price ) {
			return $price;
		}

		$active     = $this->getActiveCurrency();
		$customSale = $this->getCustomSalePrice( $product->get_id(), $active );

		if ( null !== $customSale ) {
			return $customSale;
		}

		$default = $this->getDefaultCurrency();

		if ( $active === $default ) {
			return $price;
		}

		return (float) $price * $this->getExchangeRate( $default, $active );
	}

	/**
	 * Convert shipping rate costs to the active currency.
	 *
	 * Hooked into `woocommerce_package_rates`. No-op in the default currency.
	 *
	 * @param array $rates   Shipping rates.
	 * @param array $package Shipping package.
	 * @return array
	 */
	public function filterShippingRates( array $rates, array $package ): array {
		$active  = $this->getActiveCurrency();
		$default = $this->getDefaultCurrency();

		if ( $active === $default ) {
			return $rates;
		}

		$rate = $this->getExchangeRate( $default, $active );

		if ( $rate <= 0 ) {
			return $rates;
		}

		foreach ( $rates as $shipping_rate ) {
			if ( ! property_exists( $shipping_rate, 'cost' ) ) {
				continue;
			}

			$shipping_rate->cost = (float) $shipping_rate->cost * $rate;

			if ( isset( $shipping_rate->taxes ) && is_array( $shipping_rate->taxes ) ) {
				foreach ( $shipping_rate->taxes as $key => $tax ) {
					$shipping_rate->taxes[ $key ] = (float) $tax * $rate;
				}
			}
		}

		return $rates;
	}

	/**
	 * Persist a currency selection submitted via the switcher.
	 *
	 * Hooked into `init`. Reads the `polyglot_currency` request parameter and
	 * stores it in a cookie (1 year) so the selection survives navigation.
	 *
	 * @return void
	 */
	public function applySelectedCurrency(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected = isset( $_GET['polyglot_currency'] )
			? sanitize_text_field( wp_unslash( $_GET['polyglot_currency'] ) )
			: '';

		if ( ! $selected ) {
			return;
		}

		$enabled = $this->getEnabledCurrencies();

		if ( ! empty( $enabled ) && ! in_array( $selected, $enabled, true ) ) {
			return;
		}

		// Reset the memoised value so the new selection takes effect.
		$this->activeCurrency = $selected;

		// Set cookie only on the frontend (headers not sent yet during init).
		if ( ! headers_sent() ) {
			wc_setcookie( 'polyglot_currency', $selected, time() + YEAR_IN_SECONDS );
		}
	}

	// ── Internal resolution helpers ─────────────────────────────────────────

	/**
	 * Get the currency selected by the switcher (request param or cookie).
	 *
	 * @return string Currency code, or empty string if none.
	 */
	private function getSelectedCurrency(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['polyglot_currency'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['polyglot_currency'] ) );
		}

		if ( isset( $_COOKIE['polyglot_currency'] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE['polyglot_currency'] ) );
		}

		return '';
	}

	/**
	 * Resolve the currency mapped to the current language.
	 *
	 * @return string Currency code, or empty string if no mapping.
	 */
	private function getCurrencyByLanguage(): string {
		$map = $this->options->get( 'wc.currency.language_map', array() );
		$map = is_array( $map ) ? $map : array();

		$language = polyglot_get_current_language();

		return $map[ $language ] ?? '';
	}

	/**
	 * Resolve the currency from the visitor's geolocated country.
	 *
	 * Uses WooCommerce's geolocation when available, then maps the country to
	 * a currency via the configured country map.
	 *
	 * @return string Currency code, or empty string if none.
	 */
	private function getCurrencyByLocation(): string {
		if ( ! class_exists( '\WC_Geolocation' ) ) {
			return '';
		}

		$geo      = \WC_Geolocation::geolocate_ip();
		$country  = $geo['country'] ?? '';

		if ( ! $country ) {
			return '';
		}

		$map = $this->options->get( 'wc.currency.country_map', array() );
		$map = is_array( $map ) ? $map : array();

		return $map[ $country ] ?? '';
	}
}
