<?php
/**
 * Language repository for NovaTools Polyglot.
 *
 * Provides read access to the `polyglot_languages` table with WordPress
 * object-cache integration. All queries are cached and keyed by purpose
 * so that repeated calls within a request are essentially free.
 *
 * @package NovaTools\Polyglot\Language
 */

namespace NovaTools\Polyglot\Language;

use NovaTools\Polyglot\Database\Schema;
use NovaTools\Polyglot\Support\Cache;

defined( 'ABSPATH' ) || exit;

class LanguageRepository {

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
	 * Get every registered language, ordered by sort_order.
	 *
	 * Results are cached per-request (and persistently when a persistent
	 * object-cache backend is available).
	 *
	 * @return Language[] Associative array keyed by language code.
	 */
	public function getAll(): array {
		$key   = $this->cache->key( 'languages', 'all' );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $this->hydrateCollection( $cached );
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_languages' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY sort_order ASC, english_name ASC",
			ARRAY_A
		);

		$indexed = array();

		foreach ( $rows as $row ) {
			$lang = Language::fromRow( $row );
			$indexed[ $lang->code ] = $lang->toArray();
		}

		$this->cache->set( $key, $indexed );

		return $this->hydrateCollection( $indexed );
	}

	/**
	 * Get only active languages.
	 *
	 * @return Language[] Associative array keyed by language code.
	 */
	public function getActive(): array {
		$key    = $this->cache->key( 'languages', 'active' );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $this->hydrateCollection( $cached );
		}

		$all = $this->getAll();

		$active = array_filter(
			$all,
			static fn( Language $lang ): bool => $lang->isActive
		);

		$data = array();

		foreach ( $active as $lang ) {
			$data[ $lang->code ] = $lang->toArray();
		}

		$this->cache->set( $key, $data );

		return $active;
	}

	/**
	 * Get only inactive languages.
	 *
	 * Filters at the database level when the full collection has not already
	 * been loaded, otherwise reuses the cached collection.
	 *
	 * @return Language[] Associative array keyed by language code.
	 */
	public function getInactive(): array {
		$key    = $this->cache->key( 'languages', 'inactive' );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $this->hydrateCollection( $cached );
		}

		$all = $this->getAll();

		$inactive = array_filter(
			$all,
			static fn( Language $lang ): bool => ! $lang->isActive
		);

		$data = array();

		foreach ( $inactive as $lang ) {
			$data[ $lang->code ] = $lang->toArray();
		}

		$this->cache->set( $key, $data );

		return $inactive;
	}

	/**
	 * Get a single language by its short code.
	 *
	 * @param string $code Language code (e.g. "en", "fr").
	 * @return Language|null Null when the code does not exist.
	 */
	public function getByCode( string $code ): ?Language {
		$key    = $this->cache->key( 'language', $code );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return Language::fromRow( $cached );
		}

		// Check the full collection first (avoids an extra query).
		$all = $this->getAll();

		if ( isset( $all[ $code ] ) ) {
			// Cache the per-code entry so subsequent getByCode() calls
			// for this language hit the cache directly.
			$this->cache->set( $key, $all[ $code ]->toArray() );
			return $all[ $code ];
		}

		return null;
	}

	/**
	 * Get the default site language.
	 *
	 * @return Language|null Null when no default is set (should not happen).
	 */
	public function getDefault(): ?Language {
		$key    = $this->cache->key( 'language', 'default' );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return Language::fromRow( $cached );
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_languages' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			"SELECT * FROM {$table} WHERE is_default = 1 LIMIT 1",
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$this->cache->set( $key, $row );

		return Language::fromRow( $row );
	}

	/**
	 * Get a language by its full WordPress locale string.
	 *
	 * Falls back to matching by the first two characters of the locale
	 * when an exact match is not found.
	 *
	 * @param string $locale WordPress locale (e.g. "fr_FR").
	 * @return Language|null
	 */
	public function getByLocale( string $locale ): ?Language {
		$all = $this->getAll();

		// Try exact locale match first.
		foreach ( $all as $lang ) {
			if ( $lang->locale === $locale ) {
				return $lang;
			}
		}

		// Fallback: match by short code derived from the locale.
		$code = strtolower( substr( $locale, 0, 2 ) );

		return $all[ $code ] ?? null;
	}

	/**
	 * Hydrate an array of serialized language data back into value objects.
	 *
	 * @param array $collection Associative array of language data arrays keyed by code.
	 * @return Language[]
	 */
	private function hydrateCollection( array $collection ): array {
		$result = array();

		foreach ( $collection as $code => $data ) {
			$result[ $code ] = Language::fromRow( $data );
		}

		return $result;
	}
}
