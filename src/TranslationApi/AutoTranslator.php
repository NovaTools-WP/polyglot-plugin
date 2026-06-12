<?php
/**
 * Auto-translation orchestrator for NovaTools Polyglot.
 *
 * Coordinates batch auto-translation of untranslated strings using a
 * configured provider. Handles throttling with exponential backoff and
 * records the provider name alongside each translation.
 *
 * @package NovaTools\Polyglot\TranslationApi
 */

namespace NovaTools\Polyglot\TranslationApi;

use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class AutoTranslator {

	/**
	 * Maximum number of attempts per batch (1 initial + 3 retries).
	 *
	 * @var int
	 */
	const MAX_RETRIES = 4;

	/**
	 * Base delay in seconds for exponential backoff.
	 *
	 * Delay formula: BASE_DELAY * 2^(attempt - 1).
	 * Attempt 1: 2s, Attempt 2: 4s, Attempt 3: 8s, Attempt 4: 16s.
	 *
	 * @var int
	 */
	const BASE_DELAY = 2;

	/**
	 * Default delay in seconds when a rate limit (429) is encountered.
	 *
	 * @var int
	 */
	const RATE_LIMIT_DELAY = 60;

	/**
	 * Number of strings per batch sent to the provider.
	 *
	 * @var int
	 */
	const STRINGS_PER_BATCH = 25;

	/**
	 * Provider registry for looking up translation providers.
	 *
	 * @var ProviderRegistry
	 */
	private ProviderRegistry $registry;

	/**
	 * Settings store.
	 *
	 * @var OptionStore
	 */
	private OptionStore $options;

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry $registry Provider registry.
	 * @param OptionStore      $options  Settings store.
	 */
	public function __construct( ProviderRegistry $registry, OptionStore $options ) {
		$this->registry = $registry;
		$this->options  = $options;
	}

	/**
	 * Auto-translate all untranslated strings for a given language.
	 *
	 * Fetches untranslated strings from the database, batches them, and sends
	 * each batch to the configured provider. Records the provider name in the
	 * `provider` column for each saved translation.
	 *
	 * @param string      $language   Target language code.
	 * @param string|null $providerId Optional provider override. Defaults to configured provider.
	 * @return array{translated: int, failed: int, skipped: int} Summary counts.
	 */
	public function translateLanguage( string $language, ?string $providerId = null ): array {
		$provider = $this->resolveProvider( $providerId );

		if ( null === $provider ) {
			return array( 'translated' => 0, 'failed' => 0, 'skipped' => 0 );
		}

		$sourceLang = $this->getSourceLanguage();
		$strings    = $this->getUntranslatedStrings( $language );

		if ( empty( $strings ) ) {
			return array( 'translated' => 0, 'failed' => 0, 'skipped' => 0 );
		}

		return $this->processStrings( $strings, $sourceLang, $language, $provider );
	}

	/**
	 * Auto-translate a specific set of string IDs.
	 *
	 * @param int[]       $stringIds Array of string IDs to translate.
	 * @param string      $language  Target language code.
	 * @param string|null $providerId Optional provider override.
	 * @return array{translated: int, failed: int, skipped: int}
	 */
	public function translateStrings( array $stringIds, string $language, ?string $providerId = null ): array {
		$provider = $this->resolveProvider( $providerId );

		if ( null === $provider || empty( $stringIds ) ) {
			return array( 'translated' => 0, 'failed' => 0, 'skipped' => 0 );
		}

		$sourceLang = $this->getSourceLanguage();
		$strings    = $this->loadStringsByIds( $stringIds );

		if ( empty( $strings ) ) {
			return array( 'translated' => 0, 'failed' => 0, 'skipped' => 0 );
		}

		return $this->processStrings( $strings, $sourceLang, $language, $provider );
	}

	/**
	 * Get the provider that will be used for auto-translation.
	 *
	 * @return TranslationProviderInterface|null
	 */
	public function getActiveProvider(): ?TranslationProviderInterface {
		return $this->resolveProvider( null );
	}

	/**
	 * Resolve a translation provider by ID or fall back to the default.
	 *
	 * @param string|null $providerId Specific provider ID, or null for default.
	 * @return TranslationProviderInterface|null
	 */
	private function resolveProvider( ?string $providerId ): ?TranslationProviderInterface {
		if ( null !== $providerId ) {
			$provider = $this->registry->get( $providerId );

			if ( null !== $provider && $provider->isConfigured() ) {
				return $provider;
			}

			return null;
		}

		return $this->registry->getDefaultProvider();
	}

	/**
	 * Process an array of strings through the translation provider in batches.
	 *
	 * @param array<int, array{id: int, value: string}> $strings    String records from the database.
	 * @param string                                     $sourceLang Source language code.
	 * @param string                                     $targetLang Target language code.
	 * @param TranslationProviderInterface               $provider   The translation provider.
	 * @return array{translated: int, failed: int, skipped: int}
	 */
	private function processStrings( array $strings, string $sourceLang, string $targetLang, TranslationProviderInterface $provider ): array {
		$translated = 0;
		$failed     = 0;
		$skipped    = 0;
		$batches    = array_chunk( $strings, self::STRINGS_PER_BATCH );
		$providerId = $provider->getId();

		foreach ( $batches as $batch ) {
			$texts   = array_column( $batch, 'value' );
			$ids     = array_column( $batch, 'id' );
			$results = $this->translateWithRetry( $provider, $texts, $sourceLang, $targetLang );

			if ( null === $results ) {
				$failed += count( $batch );
				continue;
			}

			foreach ( $results as $i => $translatedText ) {
				if ( ! isset( $ids[ $i ] ) ) {
					$skipped++;
					continue;
				}

				$saved = $this->saveTranslation( $ids[ $i ], $targetLang, $translatedText, $providerId );

				if ( $saved ) {
					$translated++;
				} else {
					$failed++;
				}
			}
		}

		return array(
			'translated' => $translated,
			'failed'     => $failed,
			'skipped'    => $skipped,
		);
	}

	/**
	 * Translate a batch of texts with retry and exponential backoff.
	 *
	 * Retries up to MAX_RETRIES times with exponential backoff on failure.
	 * Rate-limit responses (429) use a longer delay.
	 *
	 * @param TranslationProviderInterface $provider   The translation provider.
	 * @param string[]                     $texts      Texts to translate.
	 * @param string                       $sourceLang Source language code.
	 * @param string                       $targetLang Target language code.
	 * @return string[]|null Array of translated texts, or null if all retries failed.
	 */
	private function translateWithRetry( TranslationProviderInterface $provider, array $texts, string $sourceLang, string $targetLang ): ?array {
		$lastException = null;

		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			try {
				return $provider->translateBatch( $texts, $sourceLang, $targetLang );
			} catch ( TranslationException $e ) {
				$lastException = $e;

				// Do not retry on auth errors (401/403) or bad requests (400).
				$code = $e->getStatusCode();
				if ( in_array( $code, array( 400, 401, 403 ), true ) ) {
					break;
				}

				// Calculate delay: longer for rate limits, exponential for others.
				if ( $e->isRateLimit() ) {
					$delay = self::RATE_LIMIT_DELAY;
				} else {
					$delay = self::BASE_DELAY * (int) pow( 2, $attempt - 1 );
				}

				if ( $attempt < self::MAX_RETRIES ) {
					sleep( $delay );
				}
			}
		}

		// Log the final failure for debugging.
		if ( $lastException ) {
			error_log( sprintf(
				'[Polyglot] Auto-translation failed after %d attempts for provider "%s": %s',
				self::MAX_RETRIES,
				$provider->getId(),
				$lastException->getMessage()
			) );
		}

		return null;
	}

	/**
	 * Save a translated string to the database.
	 *
	 * Records the provider identifier in the `provider` column
	 * and sets the status to translated (1).
	 *
	 * @param int    $stringId   Source string ID.
	 * @param string $language   Target language code.
	 * @param string $value      Translated text.
	 * @param string $providerId Provider identifier (e.g. 'deepl').
	 * @return bool True if the translation was saved.
	 */
	private function saveTranslation( int $stringId, string $language, string $value, string $providerId ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'polyglot_string_translations';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct insert for batch performance.

		$inserted = $wpdb->insert(
			$table,
			array(
				'string_id'          => $stringId,
				'language'           => $language,
				'status'             => 1,  // Translated.
				'value'              => $value,
				'translator_id'      => get_current_user_id() ?: null,
				'translation_service'  => $providerId,
				'translated_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $inserted ) {
			// Try update if insert fails (translation already exists for this string+language).
			$updated = $wpdb->update(
				$table,
				array(
					'status'              => 1,
					'value'               => $value,
					'translation_service'  => $providerId,
					'translated_at'       => current_time( 'mysql' ),
				),
				array(
					'string_id' => $stringId,
					'language'  => $language,
				),
				array( '%d', '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);

			return false !== $updated;
		}

		return true;
	}

	/**
	 * Get all untranslated strings for a given language.
	 *
	 * Returns strings that do not yet have a translation in the target language,
	 * or whose existing translation has status 0 (untranslated).
	 *
	 * @param string $language Target language code.
	 * @return array<int, array{id: int, value: string}>
	 */
	private function getUntranslatedStrings( string $language ): array {
		global $wpdb;

		$strings_table    = $wpdb->prefix . 'polyglot_strings';
		$translations_table = $wpdb->prefix . 'polyglot_string_translations';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch query for auto-translation.

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.value
				 FROM {$strings_table} s
				 LEFT JOIN {$translations_table} st
				     ON st.string_id = s.id AND st.language = %s AND st.status = 1
				 WHERE st.id IS NULL
				 ORDER BY s.id
				 LIMIT 5000",
				$language
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Load specific strings by their IDs.
	 *
	 * @param int[] $stringIds String IDs to load.
	 * @return array<int, array{id: int, value: string}>
	 */
	private function loadStringsByIds( array $stringIds ): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'polyglot_strings';
		$ids     = array_map( 'absint', $stringIds );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnsupportedUnpreparedPlaceholder, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch query.

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, value FROM {$table} WHERE id IN ({$placeholders})",
				...$ids
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnsupportedUnpreparedPlaceholder, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get the source language for translations.
	 *
	 * Defaults to the site's default language from settings.
	 *
	 * @return string
	 */
	private function getSourceLanguage(): string {
		return (string) $this->options->get( 'default_language', 'en' );
	}
}
