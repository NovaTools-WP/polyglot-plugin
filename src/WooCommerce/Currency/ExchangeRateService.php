<?php
/**
 * Exchange-rate service for NovaTools Polyglot.
 *
 * Fetches exchange rates from a configurable API provider (ApiLayer,
 * Fixer.io, or OpenExchangeRates) and stores them relative to the default
 * (base) currency in `polyglot_settings`. Updates run on a configurable
 * schedule via a WordPress cron event.
 *
 * @package NovaTools\Polyglot\WooCommerce\Currency
 */

namespace NovaTools\Polyglot\WooCommerce\Currency;

use NovaTools\Polyglot\Support\Cache;
use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class ExchangeRateService {

	/**
	 * Cron hook used for scheduled rate updates.
	 */
	const CRON_HOOK = 'polyglot_update_exchange_rates';

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
	 * Register the scheduled update event and its callback.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'updateRates' ) );
	}

	/**
	 * Schedule (or reschedule) the exchange-rate update event.
	 *
	 * Reads the configured schedule frequency and aligns the cron event. The
	 * "manual" frequency clears any scheduled event.
	 *
	 * @return void
	 */
	public function scheduleEvent(): void {
		$next = wp_next_scheduled( self::CRON_HOOK );

		if ( 'manual' === $this->getSchedule() ) {
			if ( $next ) {
				wp_unschedule_event( $next, self::CRON_HOOK );
			}

			return;
		}

		$interval = $this->getScheduleInterval();

		if ( $next && ( $next - time() ) < $interval ) {
			return;
		}

		if ( $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}

		wp_schedule_event( time() + $interval, $this->getSchedule(), self::CRON_HOOK );
	}

	/**
	 * Schedule the exchange-rate cron event during plugin activation.
	 *
	 * Called from Activator::activate() so that wp_next_scheduled() is not
	 * checked on every page load via an `init` hook.
	 *
	 * @return void
	 */
	public static function scheduleOnActivation(): void {
		$service = new self( new OptionStore(), new Cache() );
		$service->scheduleEvent();
	}

	/**
	 * Fetch fresh rates from the configured provider and persist them.
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function updateRates() {
		$provider = $this->getProvider();
		$key      = $this->getApiKey();
		$base     = $this->getDefaultCurrency();

		if ( ! $provider ) {
			return new \WP_Error( 'no_provider', __( 'No exchange-rate provider configured.', 'novatools-polyglot' ) );
		}

		if ( ! $key ) {
			return new \WP_Error( 'no_api_key', __( 'No API key configured for the exchange-rate provider.', 'novatools-polyglot' ) );
		}

		$rates = $this->fetchFromProvider( $provider, $key, $base );

		if ( is_wp_error( $rates ) ) {
			return $rates;
		}

		// Persist only rates for enabled currencies, relative to the base.
		$enabled = $this->getEnabledCurrencies();
		$stored  = array();

		foreach ( $enabled as $currency ) {
			if ( $currency === $base ) {
				continue;
			}

			if ( isset( $rates[ $currency ] ) ) {
				$stored[ $currency ] = (float) $rates[ $currency ];
			}
		}

		$this->options->set( 'wc.currency.rates', $stored );
		$this->options->set( 'wc.currency.rates_updated', current_time( 'mysql', true ) );

		$this->cache->delete( $this->cache->key( 'wc', 'rates' ) );

		/**
		 * Fires after exchange rates have been updated.
		 *
		 * @param array  $stored   Persisted rates (currency => rate).
		 * @param string $provider Provider identifier.
		 * @param string $base     Base currency code.
		 */
		do_action( 'polyglot_woocommerce_rates_updated', $stored, $provider, $base );

		return true;
	}

	/**
	 * Fetch rates from a specific provider.
	 *
	 * @param string $provider Provider identifier (apilayer|fixer|openexchangerates).
	 * @param string $key      API key.
	 * @param string $base     Base currency code.
	 * @return array|\WP_Error Map of currency => rate relative to base, or error.
	 */
	public function fetchFromProvider( string $provider, string $key, string $base ) {
		switch ( $provider ) {
			case 'apilayer':
				return $this->fetchApiLayer( $key, $base );
			case 'fixer':
				return $this->fetchFixer( $key, $base );
			case 'openexchangerates':
				return $this->fetchOpenExchangeRates( $key, $base );
			default:
				/**
				 * Allow custom exchange-rate providers.
				 *
				 * @param array|\WP_Error $rates  Default empty.
				 * @param string          $provider Provider identifier.
				 * @param string          $key      API key.
				 * @param string          $base     Base currency code.
				 */
				return apply_filters( 'polyglot_woocommerce_exchange_rate_provider', new \WP_Error( 'unknown_provider', __( 'Unknown exchange-rate provider.', 'novatools-polyglot' ) ), $provider, $key, $base );
		}
	}

	/**
	 * Fetch rates from ApiLayer (exchangerate.host / APILayer).
	 *
	 * @param string $key  API key.
	 * @param string $base Base currency code.
	 * @return array|\WP_Error
	 */
	private function fetchApiLayer( string $key, string $base ) {
		$url = add_query_arg(
			array(
				'base'    => $base,
				'apikey'  => $key,
			),
			'https://api.apilayer.com/exchangerates_data/latest'
		);

		return $this->parseRatesResponse( wp_remote_get( $url ), 'rates' );
	}

	/**
	 * Fetch rates from Fixer.io.
	 *
	 * @param string $key  API key (access key).
	 * @param string $base Base currency code.
	 * @return array|\WP_Error
	 */
	private function fetchFixer( string $key, string $base ) {
		$url = add_query_arg(
			array(
				'access_key' => $key,
				'base'       => $base,
			),
			'https://data.fixer.io/api/latest'
		);

		return $this->parseRatesResponse( wp_remote_get( $url ), 'rates' );
	}

	/**
	 * Fetch rates from OpenExchangeRates.
	 *
	 * @param string $key  API key (app id).
	 * @param string $base Base currency code.
	 * @return array|\WP_Error
	 */
	private function fetchOpenExchangeRates( string $key, string $base ) {
		$url = add_query_arg(
			array(
				'app_id' => $key,
				'base'   => $base,
			),
			'https://openexchangerates.org/api/latest.json'
		);

		return $this->parseRatesResponse( wp_remote_get( $url ), 'rates' );
	}

	/**
	 * Parse a provider HTTP response into a currency => rate map.
	 *
	 * @param array|\WP_Error $response wp_remote_get() response.
	 * @param string          $key      JSON key holding the rates object.
	 * @return array|\WP_Error
	 */
	private function parseRatesResponse( $response, string $key ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'provider_http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Exchange-rate provider returned HTTP %d.', 'novatools-polyglot' ),
					$code
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body[ $key ] ) || ! is_array( $body[ $key ] ) ) {
			return new \WP_Error( 'provider_parse_error', __( 'Could not parse exchange-rate response.', 'novatools-polyglot' ) );
		}

		return $body[ $key ];
	}

	// ── Configuration accessors ─────────────────────────────────────────────

	/**
	 * Get the configured provider identifier.
	 *
	 * @return string Provider id (apilayer|fixer|openexchangerates), or empty.
	 */
	public function getProvider(): string {
		return (string) $this->options->get( 'wc.currency.api.provider', '' );
	}

	/**
	 * Get the API key for the configured provider.
	 *
	 * @return string API key.
	 */
	public function getApiKey(): string {
		return (string) $this->options->get( 'wc.currency.api.key', '' );
	}

	/**
	 * Get the configured update schedule.
	 *
	 * @return string daily|weekly|manual.
	 */
	public function getSchedule(): string {
		$schedule = $this->options->get( 'wc.currency.schedule', 'daily' );

		return in_array( $schedule, array( 'daily', 'weekly', 'manual' ), true ) ? $schedule : 'daily';
	}

	/**
	 * Get the update interval in seconds for the configured schedule.
	 *
	 * @return int Seconds.
	 */
	public function getScheduleInterval(): int {
		switch ( $this->getSchedule() ) {
			case 'weekly':
				return WEEK_IN_SECONDS;
			case 'manual':
				return 0;
			case 'daily':
			default:
				return DAY_IN_SECONDS;
		}
	}

	/**
	 * Get the default currency from settings.
	 *
	 * @return string
	 */
	private function getDefaultCurrency(): string {
		$default = $this->options->get( 'wc.currency.default', '' );

		return $default ?: ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD' );
	}

	/**
	 * Get the enabled currencies from settings.
	 *
	 * @return string[]
	 */
	private function getEnabledCurrencies(): array {
		$enabled = $this->options->get( 'wc.currency.enabled_currencies', array() );

		return is_array( $enabled ) ? $enabled : array();
	}
}
