<?php
/**
 * DeepL translation provider for NovaTools Polyglot.
 *
 * Supports both free and pro API tiers, formality settings, and automatic
 * language code mapping to DeepL's expected format.
 *
 * @package NovaTools\Polyglot\TranslationApi
 */

namespace NovaTools\Polyglot\TranslationApi;

use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class DeepLProvider implements TranslationProviderInterface {

	/**
	 * Provider identifier.
	 *
	 * @var string
	 */
	const ID = 'deepl';

	/**
	 * DeepL API free-tier base URL.
	 *
	 * @var string
	 */
	const URL_FREE = 'https://api-free.deepl.com/v2/translate';

	/**
	 * DeepL API pro-tier base URL.
	 *
	 * @var string
	 */
	const URL_PRO = 'https://api.deepl.com/v2/translate';

	/**
	 * DeepL usage endpoint (free).
	 *
	 * @var string
	 */
	const USAGE_URL_FREE = 'https://api-free.deepl.com/v2/usage';

	/**
	 * DeepL usage endpoint (pro).
	 *
	 * @var string
	 */
	const USAGE_URL_PRO = 'https://api.deepl.com/v2/usage';

	/**
	 * Maximum number of texts per batch request.
	 *
	 * DeepL supports up to 50 texts per request.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 50;

	/**
	 * Language code mappings from standard codes to DeepL's expected format.
	 *
	 * @var array<string, string>
	 */
	private static array $languageMap = array(
		'pt' => 'PT-BR',  // DeepL defaults PT to PT-PT; map to PT-BR for Brazilian.
		'zh' => 'ZH',     // DeepL uses ZH for simplified Chinese.
		'zh-cn' => 'ZH',
		'zh-tw' => 'ZH-HANT',
		'zh-hans' => 'ZH',
		'zh-hant' => 'ZH-HANT',
		'en' => 'EN-US',  // DeepL defaults EN to EN-GB; map to EN-US.
	);

	/**
	 * Supported formality levels.
	 *
	 * @var string[]
	 */
	private static array $formalityLevels = array(
		'default',
		'more',
		'less',
		'prefer_more',
		'prefer_less',
	);

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
				'DeepL API key is not configured.',
				0,
				null,
				null,
				self::ID
			);
		}

		$sourceCode = $this->mapSourceLanguage( $sourceLang );
		$targetCode = $this->mapTargetLanguage( $targetLang );
		$formality  = $this->getFormality();

		$results = array();
		$batches = array_chunk( $texts, self::BATCH_SIZE );

		foreach ( $batches as $batch ) {
			$body = array(
				'text'        => $batch,
				'source_lang' => array( $sourceCode ),
				'target_lang' => $targetCode,
			);

			if ( '' !== $formality && 'default' !== $formality ) {
				$body['formality'] = $formality;
			}

			$response = wp_remote_post( $this->getBaseUrl(), array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'DeepL-Auth-Key ' . $apiKey,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			) );

			if ( is_wp_error( $response ) ) {
				throw TranslationException::fromWpError( $response, self::ID );
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( 429 === $code ) {
				throw new TranslationException(
					'DeepL rate limit exceeded.',
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

			if ( ! is_array( $decoded ) || ! isset( $decoded['translations'] ) ) {
				throw new TranslationException(
					'Unexpected DeepL API response format.',
					0,
					null,
					$code,
					self::ID
				);
			}

			foreach ( $decoded['translations'] as $translation ) {
				$results[] = $translation['text'] ?? '';
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
		return 'DeepL';
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

		$url = $this->isFreeKey( $apiKey )
			? self::USAGE_URL_FREE
			: self::USAGE_URL_PRO;

		$response = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'DeepL-Auth-Key ' . $apiKey,
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
		return (string) $this->options->get( 'api.deepl.key', '' );
	}

	/**
	 * Determine the API base URL based on the key type.
	 *
	 * Free-tier keys end with ':fx', pro keys do not.
	 *
	 * @return string
	 */
	private function getBaseUrl(): string {
		$apiKey = $this->getApiKey();

		return $this->isFreeKey( $apiKey ) ? self::URL_FREE : self::URL_PRO;
	}

	/**
	 * Check whether the given key is a free-tier key.
	 *
	 * Free-tier DeepL keys end with ':fx'.
	 *
	 * @param string $apiKey The API key to check.
	 * @return bool
	 */
	private function isFreeKey( string $apiKey ): bool {
		return str_ends_with( $apiKey, ':fx' );
	}

	/**
	 * Map a source language code to DeepL's expected format.
	 *
	 * DeepL source languages are typically uppercase two-letter codes.
	 *
	 * @param string $lang Standard language code.
	 * @return string DeepL source language code.
	 */
	private function mapSourceLanguage( string $lang ): string {
		$upper = strtoupper( $lang );

		return self::$languageMap[ strtolower( $lang ) ] ?? $upper;
	}

	/**
	 * Map a target language code to DeepL's expected format.
	 *
	 * Target language mapping may include regional variants.
	 *
	 * @param string $lang Standard language code.
	 * @return string DeepL target language code.
	 */
	private function mapTargetLanguage( string $lang ): string {
		$key = strtolower( $lang );

		if ( isset( self::$languageMap[ $key ] ) ) {
			return self::$languageMap[ $key ];
		}

		return strtoupper( $lang );
	}

	/**
	 * Get the configured formality level.
	 *
	 * @return string Formality parameter value, or empty string if not set.
	 */
	private function getFormality(): string {
		$formality = (string) $this->options->get( 'api.deepl.formality', 'default' );

		return in_array( $formality, self::$formalityLevels, true ) ? $formality : 'default';
	}

	/**
	 * Fetch DeepL API usage information.
	 *
	 * Returns the current usage statistics from the DeepL API.
	 *
	 * @return array{character_count: int, character_limit: int}|null
	 */
	public function fetchUsage(): ?array {
		$apiKey = $this->getApiKey();

		if ( empty( $apiKey ) ) {
			return null;
		}

		$url = $this->isFreeKey( $apiKey )
			? self::USAGE_URL_FREE
			: self::USAGE_URL_PRO;

		$response = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'DeepL-Auth-Key ' . $apiKey,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['character_count'] ) ) {
			return null;
		}

		return $body;
	}
}
