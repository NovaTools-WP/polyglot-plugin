<?php
/**
 * Translation repository for NovaTools Polyglot.
 *
 * Provides CRUD access to the `polyglot_translations` table with WordPress
 * object-cache integration. All queries are cached and keyed by purpose
 * so that repeated calls within a request are essentially free.
 *
 * @package NovaTools\Polyglot\Translation
 */

namespace NovaTools\Polyglot\Translation;

use NovaTools\Polyglot\Database\Schema;
use NovaTools\Polyglot\Support\Cache;

defined( 'ABSPATH' ) || exit;

class TranslationRepository {

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
	 * Get the translation row for a specific element.
	 *
	 * @param string $elementType Element type (e.g. "post_post", "tax_category").
	 * @param int    $elementId   WordPress element ID (post ID, term ID, etc.).
	 * @return array|null Associative array of the translation row, or null.
	 */
	public function getByElement( string $elementType, int $elementId ): ?array {
		$key    = $this->cache->key( 'translation', 'element', $elementType, $elementId );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE element_type = %s AND element_id = %d",
				$elementType,
				$elementId
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$this->cache->set( $key, $row );

		return $row;
	}

	/**
	 * Get all translation rows that share a given trid.
	 *
	 * @param int $trid Translation group ID.
	 * @return array[] Array of associative arrays.
	 */
	public function getByTrid( int $trid ): array {
		$key    = $this->cache->key( 'translations', 'trid', $trid );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE trid = %d",
				$trid
			),
			ARRAY_A
		);

		$result = is_array( $rows ) ? $rows : array();

		$this->cache->set( $key, $result );

		return $result;
	}

	/**
	 * Get the full TranslationGroup for an element.
	 *
	 * Resolves the trid for the given element, then loads all rows in the group.
	 *
	 * @param string $elementType Element type.
	 * @param int    $elementId   Element ID.
	 * @return TranslationGroup|null The group, or null if the element has no translation row.
	 */
	public function getGroup( string $elementType, int $elementId ): ?TranslationGroup {
		$element = $this->getByElement( $elementType, $elementId );

		if ( ! $element ) {
			return null;
		}

		$rows = $this->getByTrid( (int) $element['trid'] );

		if ( empty( $rows ) ) {
			return null;
		}

		return TranslationGroup::fromRows( $rows );
	}

	/**
	 * Get the translation group by trid directly.
	 *
	 * @param int $trid Translation group ID.
	 * @return TranslationGroup|null
	 */
	public function getGroupByTrid( int $trid ): ?TranslationGroup {
		$rows = $this->getByTrid( $trid );

		if ( empty( $rows ) ) {
			return null;
		}

		return TranslationGroup::fromRows( $rows );
	}

	/**
	 * Save (insert or update) a translation row.
	 *
	 * If a row exists for the given (element_type, element_id), it is updated.
	 * Otherwise a new row is inserted.
	 *
	 * @param array $data Associative array of column values. Required keys:
	 *                    - element_type (string)
	 *                    - element_id   (int)
	 *                    - trid         (int)
	 *                    - language_code (string)
	 *                    Optional keys: source_language_code, status, checksum,
	 *                    translator_id, batch_id, translation_service.
	 * @return int|false The translation_id on success, false on failure.
	 */
	public function save( array $data ): int|false {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );

		$defaults = array(
			'element_type'         => 'post_post',
			'element_id'           => null,
			'trid'                 => 0,
			'language_code'        => '',
			'source_language_code' => '',
			'status'               => 'not_translated',
			'checksum'             => '',
			'translator_id'        => null,
			'batch_id'             => 0,
			'translation_service'  => '',
			'translated_at'        => null,
		);

		$data = array_merge( $defaults, array_filter( $data, static fn( $v ) => null !== $v ) );

		// Check for existing row.
		$existing = $this->getByElement( $data['element_type'], (int) $data['element_id'] );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$updated = $wpdb->update(
				$table,
				$data,
				array(
					'element_type' => $data['element_type'],
					'element_id'   => $data['element_id'],
				)
			);

			if ( false === $updated ) {
				return false;
			}

			$this->invalidateCache( (int) $existing['trid'], $data['element_type'], (int) $data['element_id'] );

			return (int) $existing['translation_id'];
		}

		// Insert new row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( $table, $data );

		if ( ! $inserted ) {
			return false;
		}

		$this->invalidateCache( (int) $data['trid'], $data['element_type'], (int) $data['element_id'] );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a translation row by element type and ID.
	 *
	 * @param string $elementType Element type.
	 * @param int    $elementId   Element ID.
	 * @return bool True on success.
	 */
	public function delete( string $elementType, int $elementId ): bool {
		global $wpdb;

		$existing = $this->getByElement( $elementType, $elementId );

		if ( ! $existing ) {
			return false;
		}

		$table = Schema::getTableName( 'polyglot_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->delete(
			$table,
			array(
				'element_type' => $elementType,
				'element_id'   => $elementId,
			)
		);

		if ( $deleted ) {
			$this->invalidateCache( (int) $existing['trid'], $elementType, $elementId );
		}

		return (bool) $deleted;
	}

	/**
	 * Get the next available trid (max + 1).
	 *
	 * Used when creating a new translation group for a fresh element.
	 *
	 * @return int
	 */
	public function getNextTrid(): int {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$max = (int) $wpdb->get_var( "SELECT MAX(trid) FROM {$table}" );

		return $max + 1;
	}

	/**
	 * Get the element ID of a translated element by source element and target language.
	 *
	 * @param int    $sourceElementId Source element ID.
	 * @param string $elementType     Element type.
	 * @param string $targetLanguage  Target language code.
	 * @return int|null Translated element ID, or null if not found.
	 */
	public function getTranslatedElementId( int $sourceElementId, string $elementType, string $targetLanguage ): ?int {
		$group = $this->getGroup( $elementType, $sourceElementId );

		if ( ! $group ) {
			return null;
		}

		return $group->getElementId( $targetLanguage );
	}

	/**
	 * Update the status of a translation row.
	 *
	 * @param string $elementType Element type.
	 * @param int    $elementId   Element ID.
	 * @param string $status      New status value.
	 * @return bool
	 */
	public function updateStatus( string $elementType, int $elementId, string $status ): bool {
		global $wpdb;

		$existing = $this->getByElement( $elementType, $elementId );

		if ( ! $existing ) {
			return false;
		}

		$table = Schema::getTableName( 'polyglot_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->update(
			$table,
			array(
				'status'        => $status,
				'translated_at' => current_time( 'mysql' ),
			),
			array(
				'element_type' => $elementType,
				'element_id'   => $elementId,
			)
		);

		if ( $updated ) {
			$this->invalidateCache( (int) $existing['trid'], $elementType, $elementId );
		}

		return (bool) $updated;
	}

	/**
	 * Paginated listing of translation rows with optional filters.
	 *
	 * Used by the WP-CLI `wp polyglot translation list` command and the
	 * REST API translations endpoint. Returns an array with `items`,
	 * `total`, `page`, and `per_page` keys.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string $element_type Filter by element type (e.g. "post_post").
	 *     @type string $language     Filter by language code.
	 *     @type string $status       Filter by translation status.
	 *     @type int    $trid         Filter by translation group ID.
	 *     @type int    $per_page     Results per page. Default 50.
	 *     @type int    $page         Page number (1-based). Default 1.
	 *     @type string $orderby      Order column. Default "trid".
	 *     @type string $order        Sort direction "ASC" or "DESC". Default "ASC".
	 * }
	 * @return array{items: array, total: int, page: int, per_page: int}
	 */
	public function paginate( array $args = array() ): array {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['element_type'] ) ) {
			$where[]  = 'element_type = %s';
			$params[] = $args['element_type'];
		}

		if ( ! empty( $args['language'] ) ) {
			$where[]  = 'language_code = %s';
			$params[] = $args['language'];
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['trid'] ) ) {
			$where[]  = 'trid = %d';
			$params[] = (int) $args['trid'];
		}

		$per_page = (int) ( $args['per_page'] ?? 50 );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$orderby  = $args['orderby'] ?? 'trid';
		$order    = strtoupper( $args['order'] ?? 'ASC' );

		// Whitelist order direction.
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'ASC';
		}

		// Whitelist orderby to known columns.
		$allowed_orderby = array(
			'translation_id', 'trid', 'element_type', 'element_id',
			'language_code', 'source_language_code', 'status', 'translated_at',
		);

		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'trid';
		}

		$where_clause = implode( ' AND ', $where );

		// Get total count.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$total = (int) $wpdb->get_var(
			$params ? $wpdb->prepare( $count_sql, $params ) : $count_sql
		);

		if ( 0 === $total ) {
			return array(
				'items'    => array(),
				'total'    => 0,
				'page'     => $page,
				'per_page' => $per_page,
			);
		}

		// Get paginated results.
		$offset       = ( $page - 1 ) * $per_page;
		$data_sql     = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order}, translation_id ASC LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( $data_sql, $query_params ),
			ARRAY_A
		);

		return array(
			'items'    => is_array( $rows ) ? $rows : array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Invalidate relevant cache entries for a translation row.
	 *
	 * Called internally after mutations (save, delete, updateStatus) and
	 * usable externally when the database row is updated directly (e.g.
	 * checksum writes) without going through the repository.
	 *
	 * @param int    $trid        Translation group ID.
	 * @param string $elementType Element type.
	 * @param int    $elementId   Element ID.
	 */
	public function invalidateCache( int $trid, string $elementType, int $elementId ): void {
		$this->cache->delete( $this->cache->key( 'translation', 'element', $elementType, $elementId ) );
		$this->cache->delete( $this->cache->key( 'translations', 'trid', $trid ) );
	}
}
