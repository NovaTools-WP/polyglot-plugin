<?php
/**
 * Language manager for NovaTools Polyglot.
 *
 * Handles mutation operations on the `polyglot_languages` table: adding,
 * removing, activating, deactivating languages, and setting the default.
 * Every mutation invalidates the relevant object-cache entries.
 *
 * @package NovaTools\Polyglot\Language
 */

namespace NovaTools\Polyglot\Language;

use NovaTools\Polyglot\Database\Schema;
use NovaTools\Polyglot\Support\Cache;

defined( 'ABSPATH' ) || exit;

class LanguageManager {

	/**
	 * Language repository (read access).
	 *
	 * @var LanguageRepository
	 */
	private LanguageRepository $repository;

	/**
	 * Cache wrapper instance.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Constructor.
	 *
	 * @param LanguageRepository $repository Read-only language access.
	 * @param Cache              $cache      Cache wrapper for invalidation.
	 */
	public function __construct( LanguageRepository $repository, Cache $cache ) {
		$this->repository = $repository;
		$this->cache      = $cache;
	}

	/**
	 * Add a new language to the site.
	 *
	 * Inserts a row into `polyglot_languages` as active (not default).
	 * If a language with the same code already exists it is reactivated
	 * instead of inserting a duplicate.
	 *
	 * @param array $data {
	 *     Language definition. Required keys: code, locale, english_name, native_name.
	 *     Optional keys: direction, flag_code, date_format, time_format, sort_order.
	 * }
	 * @return Language The created or re-activated language.
	 * @throws \InvalidArgumentException When required fields are missing.
	 */
	public function add( array $data ): Language {
		$required = array( 'code', 'locale', 'english_name', 'native_name' );

		foreach ( $required as $field ) {
			if ( empty( $data[ $field ] ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Missing required language field: %s', $field )
				);
			}
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_languages' );

		// Check if the language already exists (may have been deactivated).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $data['code'] ),
			ARRAY_A
		);

		if ( $existing ) {
			// Reactivate the existing language and update all provided fields
			// so that stale metadata (native name, locale, etc.) is refreshed.
			$update = array(
				'is_active'    => 1,
				'locale'       => $data['locale'],
				'english_name' => $data['english_name'],
				'native_name'  => $data['native_name'],
				'direction'    => $data['direction'] ?? $existing['direction'] ?? 'ltr',
				'flag_code'    => $data['flag_code'] ?? $existing['flag_code'] ?? $data['code'],
				'date_format'  => $data['date_format'] ?? $existing['date_format'] ?? '',
				'time_format'  => $data['time_format'] ?? $existing['time_format'] ?? '',
				'sort_order'   => $data['sort_order'] ?? $existing['sort_order'] ?? 0,
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update( $table, $update, array( 'code' => $data['code'] ) );
		} else {
			// Insert a new language row.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, array(
				'code'          => $data['code'],
				'locale'        => $data['locale'],
				'english_name'  => $data['english_name'],
				'native_name'   => $data['native_name'],
				'is_active'     => 1,
				'is_default'    => 0,
				'direction'     => $data['direction'] ?? 'ltr',
				'flag_code'     => $data['flag_code'] ?? $data['code'],
				'flag_url'      => '',
				'date_format'   => $data['date_format'] ?? '',
				'time_format'   => $data['time_format'] ?? '',
				'sort_order'    => $data['sort_order'] ?? 0,
			) );
		}

		$this->invalidateCache();
		flush_rewrite_rules();

		/**
		 * Fires after a language has been added or reactivated.
		 *
		 * @param string $code The language code that was added.
		 * @param array  $data The raw data used for creation.
		 */
		do_action( 'polyglot_language_added', $data['code'], $data );

		return $this->repository->getByCode( $data['code'] );
	}

	/**
	 * Remove (deactivate) a language.
	 *
	 * Deactivation preserves all translation data — only the `is_active`
	 * flag is cleared. This matches the spec requirement: "SHALL NOT
	 * delete any existing translations".
	 *
	 * Note: This is a soft deactivation, NOT a deletion. No rows are
	 * removed from the database.
	 *
	 * @deprecated Use deactivate() for clarity. remove() is kept for
	 *             backward compatibility with earlier API drafts.
	 * @see   deactivate()
	 *
	 * @param string $code Language code to deactivate.
	 * @return bool True if the language was found and deactivated.
	 */
	public function remove( string $code ): bool {
		return $this->deactivate( $code );
	}

	/**
	 * Activate a language.
	 *
	 * @param string $code Language code to activate.
	 * @return bool True if the language was found and activated.
	 */
	public function activate( string $code ): bool {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_languages' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->update(
			$table,
			array( 'is_active' => 1 ),
			array( 'code' => $code )
		);

		if ( ! $updated ) {
			return false;
		}

		$this->invalidateCache();
		flush_rewrite_rules();

		/**
		 * Fires after a language has been activated.
		 *
		 * @param string $code The language code that was activated.
		 */
		do_action( 'polyglot_language_activated', $code );

		return true;
	}

	/**
	 * Deactivate a language.
	 *
	 * @param string $code Language code to deactivate.
	 * @return bool True if the language was found and deactivated.
	 */
	public function deactivate( string $code ): bool {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_languages' );

		// Prevent deactivating the default language.
		$default = $this->repository->getDefault();

		if ( $default && $default->code === $code ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->update(
			$table,
			array( 'is_active' => 0 ),
			array( 'code' => $code )
		);

		if ( ! $updated ) {
			return false;
		}

		$this->invalidateCache();
		flush_rewrite_rules();

		/**
		 * Fires after a language has been deactivated.
		 *
		 * @param string $code The language code that was deactivated.
		 */
		do_action( 'polyglot_language_deactivated', $code );

		return true;
	}

	/**
	 * Set a language as the site default.
	 *
	 * Uses a single atomic query to flip the default flag, avoiding a
	 * window where zero languages are marked as default. The target
	 * language is also activated automatically (a default language must
	 * always be active).
	 *
	 * @param string $code Language code to make default.
	 * @return bool True on success.
	 */
	public function setDefault( string $code ): bool {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_languages' );

		// Verify the language exists.
		$lang = $this->repository->getByCode( $code );

		if ( ! $lang ) {
			return false;
		}

		// Single atomic query: set the chosen language as default (and
		// active), clear is_default on every other row in one statement.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET is_default = CASE WHEN code = %s THEN 1 ELSE 0 END, is_active = CASE WHEN code = %s THEN 1 ELSE is_active END",
			$code,
			$code
		) );

		$this->invalidateCache();

		/**
		 * Fires after the default language has been changed.
		 *
		 * @param string $code The new default language code.
		 */
		do_action( 'polyglot_default_language_changed', $code );

		return true;
	}

	/**
	 * Invalidate all language-related cache entries.
	 *
	 * Called after every mutation so that subsequent reads return fresh data.
	 * Targets only language-specific keys rather than flushing the entire
	 * polyglot cache group, preserving unrelated caches (locale mapper,
	 * strings, etc.).
	 *
	 * @return void
	 */
	private function invalidateCache(): void {
		$this->cache->delete( $this->cache->key( 'languages', 'all' ) );
		$this->cache->delete( $this->cache->key( 'languages', 'active' ) );
		$this->cache->delete( $this->cache->key( 'language', 'default' ) );

		// Invalidate per-code entries that may have been cached via getByCode().
		$all = $this->repository->getAll();
		foreach ( $all as $code => $lang ) {
			$this->cache->delete( $this->cache->key( 'language', $code ) );
		}
	}
}
