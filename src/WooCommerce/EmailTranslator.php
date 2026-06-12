<?php
/**
 * Email translator for NovaTools Polyglot.
 *
 * Translates WooCommerce order emails using the customer's order language.
 * The order language is captured when the order is placed; while an email
 * renders, the WordPress locale and the Polyglot current language are switched
 * to that language so `__()` calls inside WooCommerce email templates resolve
 * to the correct translations, then restored afterwards.
 *
 * @package NovaTools\Polyglot\WooCommerce
 */

namespace NovaTools\Polyglot\WooCommerce;

use NovaTools\Polyglot\Core\Plugin;
use NovaTools\Polyglot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

class EmailTranslator {

	/**
	 * Meta key storing an order's language code.
	 */
	const ORDER_LANGUAGE_META = '_polyglot_order_language';

	/**
	 * Translation repository.
	 *
	 * @var TranslationRepository
	 */
	private TranslationRepository $repository;

	/**
	 * Locale in effect before the current email switched it.
	 *
	 * @var string|null
	 */
	private ?string $previousLocale = null;

	/**
	 * Constructor.
	 *
	 * @param TranslationRepository $repository Translation repository.
	 */
	public function __construct( TranslationRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register email-translation hooks.
	 *
	 * Captures the order language on creation and switches the locale while
	 * emails render.
	 *
	 * @return void
	 */
	public function register(): void {
		// Capture order language at checkout (and for admin-created orders).
		add_action( 'woocommerce_checkout_order_created', array( $this, 'captureOrderLanguage' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'captureOrderLanguageFromPost' ), 10, 2 );

		// Switch language for the duration of an email.
		add_action( 'woocommerce_email_header', array( $this, 'switchToOrderLanguage' ), 10, 2 );
		add_action( 'woocommerce_email_footer', array( $this, 'restoreLanguage' ) );
	}

	/**
	 * Store the current language on a freshly created order.
	 *
	 * @param \WC_Order $order Order object.
	 * @return void
	 */
	public function captureOrderLanguage( $order ): void {
		if ( ! $order ) {
			return;
		}

		$this->setOrderLanguage( $order->get_id(), polyglot_get_current_language() );
	}

	/**
	 * Fallback language capture when an order is saved from the admin.
	 *
	 * @param int      $orderId Order ID.
	 * @param \WC_Order $order   Order object.
	 * @return void
	 */
	public function captureOrderLanguageFromPost( int $orderId, $order ): void {
		$existing = $this->getOrderLanguage( $orderId );

		if ( $existing ) {
			return;
		}

		$this->setOrderLanguage( $orderId, polyglot_get_current_language() );
	}

	/**
	 * Switch the locale to the order's language before an email renders.
	 *
	 * @param string     $email_heading Email heading text.
	 * @param \WC_Email $email          Email object (holds the order in ->object).
	 * @return void
	 */
	public function switchToOrderLanguage( $email_heading, $email = null ): void {
		$language = '';

		if ( $email && isset( $email->object ) && $email->object && method_exists( $email->object, 'get_id' ) ) {
			$language = $this->getOrderLanguage( (int) $email->object->get_id() );
		}

		if ( ! $language ) {
			$language = polyglot_get_current_language();
		}

		$locale = $this->getLocaleForLanguage( $language );

		$this->previousLocale = determine_locale();

		if ( $locale && $locale !== $this->previousLocale ) {
			switch_to_locale( $locale );

			// Force WooCommerce to reload its translations for the new locale.
			unload_textdomain( 'woocommerce' );
		}

		// Align Polyglot's notion of the current language for the email body.
		polyglot_set_current_language( $language );

		/**
		 * Fires after the email locale has been switched to the order language.
		 *
		 * @param string $language Order language code.
		 * @param string $locale   Target locale.
		 */
		do_action( 'polyglot_woocommerce_email_language_switched', $language, $locale );
	}

	/**
	 * Restore the locale after an email has rendered.
	 *
	 * @param \WC_Email $email Email object.
	 * @return void
	 */
	public function restoreLanguage( $email = null ): void {
		if ( null !== $this->previousLocale ) {
			restore_previous_locale();
			unload_textdomain( 'woocommerce' );

			$this->previousLocale = null;
		}

		// Clear the Polyglot override so subsequent requests re-resolve.
		polyglot_set_current_language( null );
	}

	/**
	 * Set the language code on an order.
	 *
	 * @param int    $orderId  Order ID.
	 * @param string $language Language code.
	 * @return int|false Meta ID or false.
	 */
	public function setOrderLanguage( int $orderId, string $language ) {
		return update_post_meta( $orderId, self::ORDER_LANGUAGE_META, $language );
	}

	/**
	 * Get the language code stored on an order.
	 *
	 * @param int $orderId Order ID.
	 * @return string Language code, or empty string if none.
	 */
	public function getOrderLanguage( int $orderId ): string {
		$language = get_post_meta( $orderId, self::ORDER_LANGUAGE_META, true );

		return is_string( $language ) ? $language : '';
	}

	/**
	 * Resolve a WordPress locale for a Polyglot language code.
	 *
	 * Uses the Language repository when available, with a small fallback map.
	 *
	 * @param string $code Language code (e.g. "fr").
	 * @return string Locale string (e.g. "fr_FR"), or empty if unknown.
	 */
	private function getLocaleForLanguage( string $code ): string {
		if ( '' === $code ) {
			return '';
		}

		try {
			$plugin = Plugin::getInstance();

			if ( $plugin->has( 'language.repository' ) ) {
				/** @var \NovaTools\Polyglot\Language\LanguageRepository $repo */
				$repo = $plugin->get( 'language.repository' );
				$lang = $repo->getByCode( $code );

				if ( $lang && ! empty( $lang->locale ) ) {
					return $lang->locale;
				}
			}
		} catch ( \Throwable ) {
			// Fall through to the static map.
		}

		$fallback = array(
			'en' => 'en_US',
			'fr' => 'fr_FR',
			'de' => 'de_DE',
			'es' => 'es_ES',
			'it' => 'it_IT',
			'nl' => 'nl_NL',
			'pt' => 'pt_PT',
			'ru' => 'ru_RU',
			'pl' => 'pl_PL',
			'ja' => 'ja',
			'zh' => 'zh_CN',
		);

		return $fallback[ $code ] ?? '';
	}
}
