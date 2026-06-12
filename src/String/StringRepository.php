<?php
/**
 * String repository for NovaTools Polyglot.
 *
 * Provides read access to the `polyglot_strings` and
 * `polyglot_string_translations` tables with WordPress object-cache
 * integration. Queries are cached by purpose so that repeated calls
 * within a request are essentially free.
 *
 * @package NovaTools\Polyglot\String
 */

namespace NovaTools\Polyglot\String;

use NovaTools\Polyglot\Database\Schema;
use NovaTools\Polyglot\Support\Cache;

defined( 'ABSPATH' ) || exit;

class StringRepository {

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
	 * Find a string by its database ID.
	 *
	 * @param int $id The string row ID.
	 * @return array|null String row as associative array, or null.
	 */
	public function findById( int $id ): ?array {
		$key    = $this->cache->key( 'string', $id );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_strings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$this->cache->set( $key, $row );

		return $row;
	}

	/**
	 * Find a string by its unique hash.
	 *
	 * The hash is an MD5 of `domain|context|name` computed by StringManager
	 * at registration time. This is the primary lookup path for the gettext
	 * override, since the hash uniquely identifies a registered string.
	 *
	 * @param string $hash MD5 hash (32 hex characters).
	 * @return array|null String row as associative array, or null.
	 */
	public function findByHash( string $hash ): ?array {
		$key    = $this->cache->key( 'string_hash', $hash );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_strings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE hash = %s", $hash ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		// Cache by both hash and ID so subsequent lookups by either key hit.
		$this->cache->set( $key, $row );
		$this->cache->set( $this->cache->key( 'string', (int) $row['id'] ), $row );

		return $row;
	}

	/**
	 * Find all strings belonging to a given domain.
	 *
	 * Results are ordered by name for deterministic output.
	 *
	 * @param string $domain Text domain (e.g. "mytheme", "contact-form-7").
	 * @return array[] Array of string rows as associative arrays.
	 */
	public function findByDomain( string $domain ): array {
		$key    = $this->cache->key( 'strings_domain', $domain );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_strings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE domain = %s ORDER BY name ASC", $domain ),
			ARRAY_A
		);

		$result = is_array( $rows ) ? $rows : array();

		$this->cache->set( $key, $result );

		return $result;
	}

	/**
	 * Search strings with flexible filtering.
	 *
	 * Supports filtering by domain, package ID, status, and free-text
	 * search on the string value and name. Returns a paginated result
	 * set with total count for UI pagination.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string $domain      Filter by text domain.
	 *     @type int    $package_id  Filter by package ID.
	 *     @type int    $status      Filter by status code (0 = untranslated, etc.).
	 *     @type string $search      Free-text search on value and name.
	 *     @type string $language    When provided, joins string_translations
	 *                               to filter by translation status for a language.
	 *     @type int    $translation_status Translation status for the given language.
	 *     @type int    $per_page    Results per page. Default 20.
	 *     @type int    $page        Page number (1-based). Default 1.
	 *     @type string $orderby     Order by column. Default 'id'.
	 *     @type string $order       'ASC' or 'DESC'. Default 'ASC'.
	 * }
	 * @return array {
	 *     @type array[] $items String rows matching the query.
	 *     @type int     $total Total number of matching rows (ignoring pagination).
	 * }
	 */
	public function search( array $args = array() ): array {
		$defaults = array(
			'domain'            => '',
			'package_id'        => 0,
			'status'            => -1,
			'search'            => '',
			'language'          => '',
			'translation_status' => -1,
			'per_page'          => 20,
			'page'              => 1,
			'orderby'           => 'id',
			'order'             => 'ASC',
		);

		$args = wp_parse_args( $args, $defaults );

		// Build a cache key from the query arguments.
		$cache_key = $this->cache->key(
			'strings_search',
			md5( wp_json_encode( $args ) )
		);

		$cached = $this->cache->get( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table   = Schema::getTableName( 'polyglot_strings' );
		$t_table = Schema::getTableName( 'polyglot_string_translations' );

		$where = array( '1=1' );
		$params = array();

		if ( '' !== $args['domain'] ) {
			$where[]  = 's.domain = %s';
			$params[] = $args['domain'];
		}

		if ( $args['package_id'] > 0 ) {
			$where[]  = 's.package_id = %d';
			$params[] = $args['package_id'];
		}

		if ( $args['status'] >= 0 ) {
			$where[]  = 's.status = %d';
			$params[] = $args['status'];
		}

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(s.value LIKE %s OR s.name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		// Language-based translation-status filter requires a JOIN.
		$join = '';
		if ( '' !== $args['language'] && $args['translation_status'] >= 0 ) {
			$join     = "LEFT JOIN {$t_table} st ON s.id = st.string_id AND st.language = %s";
			$params[] = $args['language'];

			if ( 0 === $args['translation_status'] ) {
				// Untranslated: no translation row exists, or status is 0.
				$where[] = '(st.status IS NULL OR st.status = 0)';
			} else {
				$where[]  = 'st.status = %d';
				$params[] = $args['translation_status'];
			}
		}

		$where_clause = implode( ' AND ', $where );

		// Validate orderby to prevent injection.
		$allowed_orderby = array( 'id', 'domain', 'name', 'value', 'status', 'package_id' );
		$orderby = in_array( $args['orderby'], $allowed_orderby, true )
			? 's.' . $args['orderby']
			: 's.id';
		$order = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		// Total count (no pagination).
		$count_sql = "SELECT COUNT(*) FROM {$table} s {$join} WHERE {$where_clause}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$total = (int) $wpdb->get_var(
			$params ? $wpdb->prepare( $count_sql, $params ) : $count_sql
		);

		// Paginated results.
		$offset  = ( $args['page'] - 1 ) * $args['per_page'];
		$data_sql = "SELECT s.* FROM {$table} s {$join} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $args['per_page'];
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( $data_sql, $params ),
			ARRAY_A
		);

		$items = is_array( $rows ) ? $rows : array();

		// Batch-fetch translations for all returned strings.
		if ( ! empty( $items ) ) {
			$ids            = array_column( $items, 'id' );
			$placeholders   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$t_table        = Schema::getTableName( 'polyglot_string_translations' );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$translations = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$t_table} WHERE string_id IN ({$placeholders})",
					$ids
				),
				ARRAY_A
			);

			$grouped = array();
			foreach ( $translations as $t ) {
				$grouped[ (int) $t['string_id'] ][ $t['language'] ] = $t;
			}

			foreach ( $items as &$item ) {
				$item['translations'] = $grouped[ (int) $item['id'] ] ?? array();
			}
			unset( $item );
		}

		$result = array(
			'items' => $items,
			'total' => $total,
		);

		$this->cache->set( $cache_key, $result, 60 ); // Short TTL for search results.

		return $result;
	}

	/**
	 * Get the translation for a specific string + language combination.
	 *
	 * Returns the translation row from `polyglot_string_translations` if
	 * one exists, or null otherwise.
	 *
	 * @param int    $string_id The string ID from `polyglot_strings`.
	 * @param string $language  Target language code.
	 * @return array|null Translation row as associative array, or null.
	 */
	public function getTranslation( int $string_id, string $language ): ?array {
		$key    = $this->cache->key( 'string_translation', $string_id, $language );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_string_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE string_id = %d AND language = %s",
				$string_id,
				$language
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
	 * Get all translations for a string, keyed by language code.
	 *
	 * @param int $string_id The string ID.
	 * @return array[] Associative array of translation rows keyed by language code.
	 */
	public function getTranslationsForString( int $string_id ): array {
		$key    = $this->cache->key( 'string_translations', $string_id );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_string_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE string_id = %d",
				$string_id
			),
			ARRAY_A
		);

		$result = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$result[ $row['language'] ] = $row;
			}
		}

		$this->cache->set( $key, $result );

		return $result;
	}

	/**
	 * Save (insert or update) a string row.
	 *
	 * If the row has an `id` key, the existing row is updated. Otherwise
	 * a new row is inserted.
	 *
	 * @param array $data String data (column => value pairs).
	 * @return int The string ID.
	 */
	public function save( array $data ): int {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_strings' );

		if ( ! empty( $data['id'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update( $table, $data, array( 'id' => $data['id'] ) );
			return (int) $data['id'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $data );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Save (insert or update) a string translation row.
	 *
	 * If a translation already exists for the string_id + language pair,
	 * it is updated. Otherwise a new row is inserted.
	 *
	 * @param array $data Translation data (must include string_id and language).
	 * @return int The translation row ID.
	 */
	public function saveTranslation( array $data ): int {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_string_translations' );

		// Check for existing translation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE string_id = %d AND language = %s",
				$data['string_id'],
				$data['language']
			)
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update( $table, $data, array( 'id' => $existing->id ) );
			$id = (int) $existing->id;
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $data );
			$id = (int) $wpdb->insert_id;
		}

		// Invalidate caches for this translation.
		$this->cache->delete( $this->cache->key( 'string_translation', (int) $data['string_id'], $data['language'] ) );
		$this->cache->delete( $this->cache->key( 'string_translations', (int) $data['string_id'] ) );

		// Invalidate search result caches so translations appear immediately.
		$this->cache->flushGroup();

		return $id;
	}

	/**
	 * Delete a string and all its translations.
	 *
	 * @param int $id The string ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$strings_table = Schema::getTableName( 'polyglot_strings' );
		$trans_table   = Schema::getTableName( 'polyglot_string_translations' );

		// Delete all translations for this string.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $trans_table, array( 'string_id' => $id ) );

		// Delete the string itself.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->delete( $strings_table, array( 'id' => $id ) );

		// Invalidate caches.
		$this->cache->delete( $this->cache->key( 'string', $id ) );

		return (bool) $deleted;
	}

	/**
	 * Invalidate caches related to a specific string hash.
	 *
	 * Called by StringManager after mutations so that subsequent reads
	 * return fresh data.
	 *
	 * @param string $hash The string hash.
	 * @param int    $id   The string ID.
	 */
	public function invalidateStringCache( string $hash, int $id ): void {
		$this->cache->delete( $this->cache->key( 'string_hash', $hash ) );
		$this->cache->delete( $this->cache->key( 'string', $id ) );
	}

	/**
	 * Get all unique domains that have registered strings.
	 *
	 * @return string[] Array of domain names.
	 */
	public function getDomains(): array {
		$key    = $this->cache->key( 'strings_domains' );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_strings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_col( "SELECT DISTINCT domain FROM {$table} ORDER BY domain ASC" );

		$result = is_array( $rows ) ? $rows : array();

		$this->cache->set( $key, $result );

		return $result;
	}

	/**
	 * Invalidate the domain-level string cache.
	 *
	 * @param string $domain Text domain to invalidate.
	 */
	public function invalidateDomainCache( string $domain ): void {
		$this->cache->delete( $this->cache->key( 'strings_domain', $domain ) );
		$this->cache->delete( $this->cache->key( 'strings_domains' ) );
	}
}
