<?php
/**
 * Translation provider interface for NovaTools Polyglot.
 *
 * Defines the contract that all translation API providers must implement.
 * Each provider handles communication with a specific translation service
 * (DeepL, Google Translate, OpenAI, etc.).
 *
 * @package NovaTools\Polyglot\TranslationApi
 */

namespace NovaTools\Polyglot\TranslationApi;

defined( 'ABSPATH' ) || exit;

interface TranslationProviderInterface {

	/**
	 * Translate a single string of text.
	 *
	 * @param string $text       Source text to translate.
	 * @param string $sourceLang Source language code (e.g. 'en').
	 * @param string $targetLang Target language code (e.g. 'fr').
	 * @return string Translated text.
	 *
	 * @throws TranslationException When the API call fails.
	 */
	public function translate( string $text, string $sourceLang, string $targetLang ): string;

	/**
	 * Translate a batch of strings in a single API call.
	 *
	 * @param string[] $texts      Array of source texts to translate.
	 * @param string   $sourceLang Source language code.
	 * @param string   $targetLang Target language code.
	 * @return string[] Array of translated texts in the same order as input.
	 *
	 * @throws TranslationException When the API call fails.
	 */
	public function translateBatch( array $texts, string $sourceLang, string $targetLang ): array;

	/**
	 * Return the unique identifier for this provider.
	 *
	 * Used for storing the provider name alongside translations and for
	 * registration in the ProviderRegistry.
	 *
	 * @return string Provider identifier (e.g. 'deepl', 'google', 'openai').
	 */
	public function getId(): string;

	/**
	 * Return the human-readable name for display in admin settings.
	 *
	 * @return string E.g. 'DeepL', 'Google Translate', 'OpenAI'.
	 */
	public function getName(): string;

	/**
	 * Check whether the provider is correctly configured and ready to use.
	 *
	 * Typically validates that required API keys are present.
	 *
	 * @return bool True if the provider can make API calls.
	 */
	public function isConfigured(): bool;

	/**
	 * Validate the API credentials by making a lightweight test request.
	 *
	 * @return bool True if the credentials are valid.
	 */
	public function validateCredentials(): bool;
}
