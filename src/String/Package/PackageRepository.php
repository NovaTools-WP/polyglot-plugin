<?php
/**
 * String package repository for NovaTools Polyglot.
 *
 * Provides CRUD operations on the `polyglot_string_packages` table.
 * Packages group related strings by source — e.g. all strings from a
 * specific plugin, theme, or admin-text context. Each package has a
 * kind (Plugin, Theme, Admin Texts, Block), a kind_slug, a name,
 * and a human-readable title.
 *
 * @package NovaTools\Polyglot\String\Package
 */

namespace NovaTools\Polyglot\String\Package;

use NovaTools\Polyglot\Database\Schema;
use NovaTools\Polyglot\Support\Cache;

defined( 'ABSPATH' ) || exit;

class PackageRepository {

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
	 * Find a package by its ID.
	 *
	 * @param int $id Package row ID.
	 * @return array|null Package row as associative array, or null.
	 */
	public function findById( int $id ): ?array {
		$key    = $this->cache->key( 'pkg', $id );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_string_packages' );

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
	 * Find a package by its unique kind + kind_slug + name combination.
	 *
	 * This is the natural key for packages — each combination of kind,
	 * kind_slug, and name should have exactly one row.
	 *
	 * @param string $kind      Package kind (e.g. "Plugin", "Theme").
	 * @param string $kind_slug Kind slug (e.g. "contact-form-7").
	 * @param string $name      Package name (usually same as kind_slug).
	 * @return array|null Package row as associative array, or null.
	 */
	public function findByKindAndName( string $kind, string $kind_slug, string $name ): ?array {
		$key    = $this->cache->key( 'pkg_lookup', md5( $kind . '|' . $kind_slug . '|' . $name ) );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_string_packages' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE kind = %s AND kind_slug = %s AND name = %s",
				$kind,
				$kind_slug,
				$name
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		// Cache by both lookup key and ID.
		$this->cache->set( $key, $row );
		$this->cache->set( $this->cache->key( 'pkg', (int) $row['id'] ), $row );

		return $row;
	}

	/**
	 * Get all packages, optionally filtered by kind.
	 *
	 * @param string $kind Optional. Filter by kind (e.g. "Plugin", "Theme").
	 * @return array[] Array of package rows as associative arrays.
	 */
	public function getAll( string $kind = '' ): array {
		$key    = $this->cache->key( 'pkgs_all', $kind ?: 'all' );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_string_packages' );

		if ( '' !== $kind ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE kind = %s ORDER BY title ASC",
					$kind
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$rows = $wpdb->get_results(
				"SELECT * FROM {$table} ORDER BY kind ASC, title ASC",
				ARRAY_A
			);
		}

		$result = is_array( $rows ) ? $rows : array();

		$this->cache->set( $key, $result );

		return $result;
	}

	/**
	 * Create or update a package.
	 *
	 * If a package with the same kind + kind_slug + name exists, it is
	 * updated. Otherwise a new row is inserted.
	 *
	 * @param array $data {
	 *     Package data. Required keys: kind, kind_slug, name.
	 *     Optional keys: title, description.
	 * }
	 * @return int The package ID.
	 */
	public function save( array $data ): int {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_string_packages' );

		// Check for existing package with the same natural key.
		$existing = $this->findByKindAndName(
			$data['kind'],
			$data['kind_slug'],
			$data['name']
		);

		if ( $existing ) {
			$update = array_filter(
				array(
					'title'       => $data['title'] ?? $existing['title'],
					'description' => $data['description'] ?? $existing['description'],
				),
				static fn( $v ): bool => null !== $v
			);

			if ( ! empty( $update ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->update( $table, $update, array( 'id' => $existing['id'] ) );
			}

			$this->invalidateCache( (int) $existing['id'], $data['kind'] );

			return (int) $existing['id'];
		}

		// Insert new package.
		$insert = array(
			'kind'        => $data['kind'],
			'kind_slug'   => $data['kind_slug'],
			'name'        => $data['name'],
			'title'       => $data['title'] ?? $data['name'],
			'description' => $data['description'] ?? '',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $insert );

		$id = (int) $wpdb->insert_id;

		$this->invalidateCache( $id, $data['kind'] );

		/**
		 * Fires after a string package has been created.
		 *
		 * @param int   $id   The package ID.
		 * @param array $data The package data used for creation.
		 */
		do_action( 'polyglot_string_package_created', $id, $data );

		return $id;
	}

	/**
	 * Delete a package by ID.
	 *
	 * Note: this does NOT delete the strings linked to the package.
	 * String cleanup should be handled separately.
	 *
	 * @param int $id Package ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_string_packages' );

		// Fetch the row first so we can invalidate the right caches.
		$row = $this->findById( $id );

		if ( ! $row ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->delete( $table, array( 'id' => $id ) );

		$this->invalidateCache( $id, $row['kind'] );

		return (bool) $deleted;
	}

	/**
	 * Invalidate cache entries for a package.
	 *
	 * @param int    $id   Package ID.
	 * @param string $kind Package kind.
	 */
	private function invalidateCache( int $id, string $kind ): void {
		$this->cache->delete( $this->cache->key( 'pkg', $id ) );
		$this->cache->delete( $this->cache->key( 'pkgs_all', 'all' ) );

		if ( '' !== $kind ) {
			$this->cache->delete( $this->cache->key( 'pkgs_all', $kind ) );
		}
	}
}
