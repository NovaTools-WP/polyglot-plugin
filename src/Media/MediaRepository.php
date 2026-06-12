<?php
/**
 * Media repository for NovaTools Polyglot.
 *
 * Provides read and write access to media translation records in the
 * `polyglot_translations` table with `element_type='post_attachment'`.
 * All queries are cached using the polyglot cache group.
 *
 * @package NovaTools\Polyglot\Media
 */

namespace NovaTools\Polyglot\Media;

use NovaTools\Polyglot\Database\Schema;
use NovaTools\Polyglot\Support\Cache;

defined( 'ABSPATH' ) || exit;

class MediaRepository {

	/**
	 * The element_type value used for media attachments.
	 *
	 * @var string
	 */
	const ELEMENT_TYPE = 'post_attachment';

	/**
	 * Cache wrapper instance.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Constructor.
	 *
	 * @param Cache $cache The polyglot cache wrapper.
	 */
	public function __construct( Cache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Get the language code assigned to an attachment.
	 *
	 * @param int $attachmentId Attachment post ID.
	 * @return string|null Language code, or null if not tracked.
	 */
	public function getAttachmentLanguage( int $attachmentId ): ?string {
		$key    = $this->cache->key( 'media_lang', $attachmentId );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached ?: null;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$code = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT language_code FROM {$table} WHERE element_type = %s AND element_id = %d LIMIT 1",
				self::ELEMENT_TYPE,
				$attachmentId
			)
		);

		$this->cache->set( $key, $code ?: '' );

		return $code ?: null;
	}

	/**
	 * Get all attachment IDs assigned to a given language.
	 *
	 * @param string $languageCode Target language code.
	 * @return int[] Attachment post IDs.
	 */
	public function getByLanguage( string $languageCode ): array {
		$key    = $this->cache->key( 'media_by_lang', $languageCode );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT element_id FROM {$table} WHERE element_type = %s AND language_code = %s ORDER BY element_id ASC",
				self::ELEMENT_TYPE,
				$languageCode
			)
		);

		$ids = array_map( 'intval', $ids );

		$this->cache->set( $key, $ids );

		return $ids;
	}

	/**
	 * Get the translated attachment ID for a given source attachment and target language.
	 *
	 * Looks up the trid for the source attachment, then finds the element
	 * with matching trid and target language code.
	 *
	 * @param int    $attachmentId  Source attachment post ID.
	 * @param string $languageCode  Target language code.
	 * @return int|null Translated attachment ID, or null if none exists.
	 */
	public function getTranslatedAttachment( int $attachmentId, string $languageCode ): ?int {
		$key    = $this->cache->key( 'media_translation', $attachmentId, $languageCode );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached ?: null;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$translatedId = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT t2.element_id FROM {$table} t1
				 INNER JOIN {$table} t2 ON t1.trid = t2.trid
				 WHERE t1.element_type = %s AND t1.element_id = %d
				 AND t2.element_type = %s AND t2.language_code = %s
				 LIMIT 1",
				self::ELEMENT_TYPE,
				$attachmentId,
				self::ELEMENT_TYPE,
				$languageCode
			)
		);

		$this->cache->set( $key, $translatedId ?: '' );

		return $translatedId ? (int) $translatedId : null;
	}

	/**
	 * Get the trid (translation group ID) for an attachment.
	 *
	 * @param int $attachmentId Attachment post ID.
	 * @return int|null Trid value, or null if the attachment is not tracked.
	 */
	public function getTrid( int $attachmentId ): ?int {
		$key    = $this->cache->key( 'media_trid', $attachmentId );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached ?: null;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$trid = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT trid FROM {$table} WHERE element_type = %s AND element_id = %d LIMIT 1",
				self::ELEMENT_TYPE,
				$attachmentId
			)
		);

		$this->cache->set( $key, $trid ?: '' );

		return $trid ? (int) $trid : null;
	}

	/**
	 * Get all attachments in a translation group by trid.
	 *
	 * @param int $trid Translation group ID.
	 * @return array[] Array of rows with element_id, language_code, source_language_code keys.
	 */
	public function getByTrid( int $trid ): array {
		$key    = $this->cache->key( 'media_trid_group', $trid );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT element_id, language_code, source_language_code FROM {$table} WHERE element_type = %s AND trid = %d",
				self::ELEMENT_TYPE,
				$trid
			),
			ARRAY_A
		);

		$this->cache->set( $key, $rows ?: array() );

		return $rows ?: array();
	}

	/**
	 * Get media attachments that have not yet been translated to a given language.
	 *
	 * Returns attachments in the source language that have no corresponding
	 * entry for the target language within the same trid group.
	 *
	 * @param string $targetLanguage Target language code.
	 * @param string $sourceLanguage Source language code (default: '' = any).
	 * @return int[] Source attachment IDs that lack a translation.
	 */
	public function getUntranslatedMedia( string $targetLanguage, string $sourceLanguage = '' ): array {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );

		$sourceCondition = $sourceLanguage
			? $wpdb->prepare( ' AND t1.language_code = %s', $sourceLanguage )
			: '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT t1.element_id FROM {$table} t1
				 WHERE t1.element_type = %s {$sourceCondition}
				 AND NOT EXISTS (
					 SELECT 1 FROM {$table} t2
					 WHERE t2.trid = t1.trid
					 AND t2.element_type = %s
					 AND t2.language_code = %s
				 )
				 ORDER BY t1.element_id ASC",
				self::ELEMENT_TYPE,
				self::ELEMENT_TYPE,
				$targetLanguage
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Count media attachments assigned to a given language.
	 *
	 * @param string $languageCode Language code.
	 * @return int
	 */
	public function countByLanguage( string $languageCode ): int {
		$key    = $this->cache->key( 'media_count', $languageCode );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE element_type = %s AND language_code = %s",
				self::ELEMENT_TYPE,
				$languageCode
			)
		);

		$this->cache->set( $key, $count );

		return $count;
	}

	/**
	 * Save a media translation record.
	 *
	 * Inserts or updates the polyglot_translations row for an attachment.
	 * Returns the translation_id.
	 *
	 * @param int    $attachmentId       Attachment post ID.
	 * @param string $languageCode       Language code for this attachment.
	 * @param int    $trid               Translation group ID.
	 * @param string $sourceLanguageCode Source language code (empty for originals).
	 * @param string $status             Translation status.
	 * @return int The translation_id from the database.
	 */
	public function save(
		int $attachmentId,
		string $languageCode,
		int $trid,
		string $sourceLanguageCode = '',
		string $status = 'not_translated'
	): int {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );

		// Check for existing record.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT translation_id FROM {$table} WHERE element_type = %s AND element_id = %d",
				self::ELEMENT_TYPE,
				$attachmentId
			)
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update(
				$table,
				array(
					'trid'                => $trid,
					'language_code'       => $languageCode,
					'source_language_code' => $sourceLanguageCode,
					'status'              => $status,
				),
				array( 'translation_id' => (int) $existing )
			);

			$translationId = (int) $existing;
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				array(
					'element_type'         => self::ELEMENT_TYPE,
					'element_id'           => $attachmentId,
					'trid'                 => $trid,
					'language_code'        => $languageCode,
					'source_language_code' => $sourceLanguageCode,
					'status'               => $status,
				)
			);

			$translationId = (int) $wpdb->insert_id;
		}

		$this->invalidateCache( $attachmentId, $languageCode, $trid );

		return $translationId;
	}

	/**
	 * Get the next available trid value for a new translation group.
	 *
	 * @return int Max trid + 1.
	 */
	public function getNextTrid(): int {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$max = (int) $wpdb->get_var( "SELECT MAX(trid) FROM {$table}" );

		return $max + 1;
	}

	/**
	 * Invalidate all cache entries related to a media translation record.
	 *
	 * @param int    $attachmentId Attachment post ID.
	 * @param string $languageCode Language code.
	 * @param int    $trid         Translation group ID.
	 */
	public function invalidateCache( int $attachmentId, string $languageCode, int $trid ): void {
		$this->cache->delete( $this->cache->key( 'media_lang', $attachmentId ) );
		$this->cache->delete( $this->cache->key( 'media_trid', $attachmentId ) );
		$this->cache->delete( $this->cache->key( 'media_by_lang', $languageCode ) );
		$this->cache->delete( $this->cache->key( 'media_count', $languageCode ) );
		$this->cache->delete( $this->cache->key( 'media_trid_group', $trid ) );

		// Invalidate translation lookups — we don't know the target language,
		// so flush the broader media cache group.
		$this->cache->flushGroup();
	}
}
