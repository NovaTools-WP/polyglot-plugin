<?php
/**
 * OpenAI translation provider for NovaTools Polyglot.
 *
 * Uses the OpenAI Chat Completions API with a customizable translation prompt.
 * Supports structured output for reliable batch translation results.
 *
 * @package NovaTools\Polyglot\TranslationApi
 */

namespace NovaTools\Polyglot\TranslationApi;

use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class OpenAIProvider implements TranslationProviderInterface {

	/**
	 * Provider identifier.
	 *
	 * @var string
	 */
	const ID = 'openai';

	/**
	 * OpenAI Chat Completions API endpoint.
	 *
	 * @var string
	 */
	const API_URL = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Default model to use for translations.
	 *
	 * @var string
	 */
	const DEFAULT_MODEL = 'gpt-4o-mini';

	/**
	 * Maximum number of texts per batch request.
	 *
	 * Keep batches small to avoid token limits and ensure quality.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 20;

	/**
	 * Default system prompt for translation.
	 *
	 * @var string
	 */
	const DEFAULT_SYSTEM_PROMPT = 'You are a professional translator. Translate the following text from {source} to {target}. Preserve HTML tags, placeholders (like %s, {name}), and formatting. Only output the translation, nothing else.';

	/**
	 * Settings store for API key and model retrieval.
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
				'OpenAI API key is not configured.',
				0,
				null,
				null,
				self::ID
			);
		}

		$sourceName = $this->languageName( $sourceLang );
		$targetName = $this->languageName( $targetLang );
		$model      = $this->getModel();

		$results = array();
		$batches = array_chunk( $texts, self::BATCH_SIZE );

		foreach ( $batches as $batchIndex => $batch ) {
			$useStructured = count( $batch ) > 1;

			$systemPrompt = $this->buildSystemPrompt( $sourceName, $targetName );

			if ( $useStructured ) {
				$userContent = $this->buildBatchUserMessage( $batch );
			} else {
				$userContent = $batch[0];
			}

			$body = array(
				'model'       => $model,
				'messages'    => array(
					array(
						'role'    => 'system',
						'content' => $systemPrompt,
					),
					array(
						'role'    => 'user',
						'content' => $userContent,
					),
				),
				'temperature' => 0.1,
			);

			if ( $useStructured ) {
				$body['response_format'] = array(
					'type' => 'json_schema',
					'json_schema' => array(
						'name'   => 'translations',
						'strict' => true,
						'schema' => array(
							'type'                 => 'object',
							'properties'           => array(
								'translations' => array(
									'type'  => 'array',
									'items' => array(
										'type' => 'string',
									),
								),
							),
							'required'             => array( 'translations' ),
							'additionalProperties' => false,
						),
					),
				);
			}

			/** This filter is documented in src/TranslationApi/OpenAIProvider.php */
			$body = apply_filters( 'polyglot_openai_request_body', $body, $sourceLang, $targetLang );

			$response = wp_remote_post( $this->getApiUrl(), array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $apiKey,
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
					'OpenAI rate limit exceeded.',
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

			if ( ! is_array( $decoded ) || ! isset( $decoded['choices'][0]['message']['content'] ) ) {
				throw new TranslationException(
					'Unexpected OpenAI API response format.',
					0,
					null,
					$code,
					self::ID
				);
			}

			$content = $decoded['choices'][0]['message']['content'];

			if ( $useStructured ) {
				$parsed = json_decode( $content, true );

				if ( ! is_array( $parsed ) || ! isset( $parsed['translations'] ) ) {
					throw new TranslationException(
						'Failed to parse structured OpenAI translation response.',
						0,
						null,
						$code,
						self::ID
					);
				}

				foreach ( $parsed['translations'] as $translation ) {
					$results[] = (string) $translation;
				}
			} else {
				$results[] = $content;
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
		return 'OpenAI';
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

		// Make a minimal chat completion request to validate the key.
		$response = wp_remote_post( $this->getApiUrl(), array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $apiKey,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'    => $this->getModel(),
				'messages' => array(
					array( 'role' => 'user', 'content' => 'test' ),
				),
				'max_tokens' => 1,
			) ),
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
		return (string) $this->options->get( 'api.openai.key', '' );
	}

	/**
	 * Get the configured model name.
	 *
	 * @return string
	 */
	private function getModel(): string {
		return (string) $this->options->get( 'api.openai.model', self::DEFAULT_MODEL );
	}

	/**
	 * Get the API URL, allowing override for compatible providers.
	 *
	 * @return string
	 */
	private function getApiUrl(): string {
		return (string) $this->options->get( 'api.openai.url', self::API_URL );
	}

	/**
	 * Build the system prompt for translation.
	 *
	 * @param string $sourceName Human-readable source language name.
	 * @param string $targetName Human-readable target language name.
	 * @return string
	 */
	private function buildSystemPrompt( string $sourceName, string $targetName ): string {
		$custom = (string) $this->options->get( 'api.openai.prompt', '' );

		if ( '' !== $custom ) {
			return str_replace(
				array( '{source}', '{target}' ),
				array( $sourceName, $targetName ),
				$custom
			);
		}

		return str_replace(
			array( '{source}', '{target}' ),
			array( $sourceName, $targetName ),
			self::DEFAULT_SYSTEM_PROMPT
		);
	}

	/**
	 * Build a JSON-encoded user message for batch translation.
	 *
	 * Each text is keyed by index so the model returns translations
	 * in a predictable order.
	 *
	 * @param string[] $batch Texts to include in the batch.
	 * @return string JSON-encoded message.
	 */
	private function buildBatchUserMessage( array $batch ): string {
		$items = array();

		foreach ( $batch as $i => $text ) {
			$items[] = array(
				'id'   => $i,
				'text' => $text,
			);
		}

		return 'Translate the following texts and return a JSON object with a "translations" array of strings in the same order:' . "\n" . wp_json_encode( $items );
	}

	/**
	 * Map a language code to a human-readable language name.
	 *
	 * Falls back to the raw code when no mapping is available.
	 *
	 * @param string $code Language code (e.g. 'en', 'fr', 'de').
	 * @return string Human-readable language name.
	 */
	private function languageName( string $code ): string {
		static $names = array(
			'af' => 'Afrikaans', 'ar' => 'Arabic', 'az' => 'Azerbaijani',
			'be' => 'Belarusian', 'bg' => 'Bulgarian', 'bn' => 'Bengali',
			'bs' => 'Bosnian', 'ca' => 'Catalan', 'cs' => 'Czech',
			'cy' => 'Welsh', 'da' => 'Danish', 'de' => 'German',
			'el' => 'Greek', 'en' => 'English', 'eo' => 'Esperanto',
			'es' => 'Spanish', 'et' => 'Estonian', 'eu' => 'Basque',
			'fa' => 'Persian', 'fi' => 'Finnish', 'fr' => 'French',
			'ga' => 'Irish', 'gl' => 'Galician', 'he' => 'Hebrew',
			'hi' => 'Hindi', 'hr' => 'Croatian', 'hu' => 'Hungarian',
			'hy' => 'Armenian', 'id' => 'Indonesian', 'is' => 'Icelandic',
			'it' => 'Italian', 'ja' => 'Japanese', 'ka' => 'Georgian',
			'kk' => 'Kazakh', 'km' => 'Khmer', 'ko' => 'Korean',
			'ku' => 'Kurdish', 'lo' => 'Lao', 'lt' => 'Lithuanian',
			'lv' => 'Latvian', 'mk' => 'Macedonian', 'ml' => 'Malayalam',
			'mn' => 'Mongolian', 'ms' => 'Malay', 'mt' => 'Maltese',
			'my' => 'Burmese', 'nb' => 'Norwegian Bokmål', 'ne' => 'Nepali',
			'nl' => 'Dutch', 'nn' => 'Norwegian Nynorsk', 'pa' => 'Punjabi',
			'pl' => 'Polish', 'pt' => 'Portuguese', 'ro' => 'Romanian',
			'ru' => 'Russian', 'si' => 'Sinhala', 'sk' => 'Slovak',
			'sl' => 'Slovenian', 'sq' => 'Albanian', 'sr' => 'Serbian',
			'sv' => 'Swedish', 'sw' => 'Swahili', 'ta' => 'Tamil',
			'te' => 'Telugu', 'th' => 'Thai', 'tl' => 'Filipino',
			'tr' => 'Turkish', 'uk' => 'Ukrainian', 'ur' => 'Urdu',
			'uz' => 'Uzbek', 'vi' => 'Vietnamese',
			'zh' => 'Chinese', 'zh-cn' => 'Simplified Chinese',
			'zh-tw' => 'Traditional Chinese',
		);

		$key = strtolower( $code );

		return $names[ $key ] ?? strtoupper( $code );
	}
}
