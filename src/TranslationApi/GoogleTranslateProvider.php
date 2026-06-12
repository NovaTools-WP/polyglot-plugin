<?php
/**
 * Google Cloud Translation provider for NovaTools Polyglot.
 *
 * Integrates with the Google Cloud Translation API (v2) using API key
 * authentication. Supports batch translation and automatic language detection.
 *
 * @package NovaTools\Polyglot\TranslationApi
 */

namespace NovaTools\Polyglot\TranslationApi;

use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class GoogleTranslateProvider implements TranslationProviderInterface {

	/**
	 * Provider identifier.
	 *
	 * @var string
	 */
	const ID = 'google';

	/**
	 * Google Cloud Translation API v2 endpoint.
	 *
	 * @var string
	 */
	const API_URL = 'https://translation.googleapis.com/language/translate/v2';

	/**
	 * Maximum number of texts per batch request.
	 *
	 * Google supports up to 128 texts per request, but we limit to 50
	 * for consistent behaviour across providers.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 50;

	/**
	 * Settings store for API key retrieval.
	 *
	 * @var OptionStore
	 */
	private OptionStore $options;

	/**
	 * Constructor.
	 *
	 * @param OptionStore $options Settings store for API key retrieval.
	 */
	public function __construct( OptionStore $options ) {
		$this->options = $options;
	}

	/**
	 * {@inheritdoc}
	 */
	public function translate( string $text, string $sourceLang, string $targetLang ): string {
		$results = $this->translateBatch( array( $text ), $sourceLang, $targetLang );
		return $results[0] ?? '';
	}

	/**
	 * {@inheritdoc}
	 */
	public function translateBatch( array $texts, string $sourceLang, string $targetLang ): array {
		$apiKey = $this->getApiKey();

		if ( empty( $apiKey ) ) {
			throw new TranslationException(
				'Google Translate API key is not configured.',
				0,
				null,
				null,
				self::ID
			);
		}

		$results = array();
		$batches = array_chunk( $texts, self::BATCH_SIZE );
		$url     = add_query_arg( 'key', $apiKey, self::API_URL );

		foreach ( $batches as $batch ) {
			$body = array(
				'q'      => $batch,
				'source' => $sourceLang,
				'target' => $targetLang,
				'format' => 'html',
			);

			$response = wp_remote_post( $url, array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			) );

			if ( is_wp_error( $response ) ) {
				throw TranslationException::fromWpError( $response, self::ID );
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( 429 === $code ) {
				throw new TranslationException(
					'Google Translate rate limit exceeded.',
					0,
					null,
					429,
					self::ID
				);
			}

			if ( $code < 200 || $code >= 300 ) {
				throw TranslationException::fromResponse( $response, self::ID );
			}

			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $decoded ) || ! isset( $decoded['data']['translations'] ) ) {
				throw new TranslationException(
					'Unexpected Google Translate API response format.',
					0,
					null,
					$code,
					self::ID
				);
			}

			foreach ( $decoded['data']['translations'] as $translation ) {
				$results[] = $translation['translatedText'] ?? '';
			}
		}

		return $results;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getId(): string {
		return self::ID;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getName(): string {
		return 'Google Translate';
	}

	/**
	 * {@inheritdoc}
	 */
	public function isConfigured(): bool {
		return ! empty( $this->getApiKey() );
	}

	/**
	 * {@inheritdoc}
	 */
	public function validateCredentials(): bool {
		$apiKey = $this->getApiKey();

		if ( empty( $apiKey ) ) {
			return false;
		}

		// Make a minimal translation request to validate the key.
		$response = wp_remote_post( self::API_URL, array(
			'timeout' => 15,
			'body'    => array(
				'q'      => 'test',
				'source' => 'en',
				'target' => 'de',
				'format' => 'text',
				'key'    => $apiKey,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		return $code >= 200 && $code < 300;
	}

	/**
	 * Get the API key from settings.
	 *
	 * @return string
	 */
	private function getApiKey(): string {
		return (string) $this->options->get( 'api.google.key', '' );
	}

	/**
	 * Get the list of supported languages from the Google API.
	 *
	 * @param string $targetLocale Locale for language names (e.g. 'en').
	 * @return array[] Array of language objects with 'language' and 'name' keys.
	 */
	public function getSupportedLanguages( string $targetLocale = 'en' ): array {
		$apiKey = $this->getApiKey();

		if ( empty( $apiKey ) ) {
			return array();
		}

		$response = wp_remote_get( add_query_arg( array(
			'key'    => $apiKey,
			'target' => $targetLocale,
		), self::API_URL . '/languages' ), array(
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return array();
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['data']['languages'] ) ) {
			return array();
		}

		return $decoded['data']['languages'];
	}
}
