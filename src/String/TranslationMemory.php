<?php
/**
 * Translation memory for NovaTools Polyglot.
 *
 * Stores previously translated strings and provides suggestions when a
 * new string is registered that is similar to a previously translated one.
 * Uses a simple similarity-based matching approach backed by the WordPress
 * object cache for performance.
 *
 * Translation memory helps translators by:
 * - Suggesting previous translations for strings that differ only in
 *   casing or minor wording
 * - Reducing repetitive work when similar strings appear across updates
 *
 * @package NovaTools\Polyglot\String
 */

namespace NovaTools\Polyglot\String;

use NovaTools\Polyglot\Support\Cache;

defined( 'ABSPATH' ) || exit;

class TranslationMemory {

	/**
	 * Maximum number of index entries to scan per suggestion request.
	 *
	 * @var int
	 */
	const MAX_INDEX_SIZE = 500;

	/**
	 * Cache wrapper instance.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * String repository for hash-based lookups.
	 *
	 * @var StringRepository
	 */
	private StringRepository $repository;

	/**
	 * Minimum similarity threshold (0–1) for a suggestion to be returned.
	 *
	 * Strings must be at least 60% similar to be suggested.
	 *
	 * @var float
	 */
	private float $minSimilarity = 0.6;

	/**
	 * Constructor.
	 *
	 * @param Cache            $cache      The polyglot cache wrapper.
	 * @param StringRepository $repository String repository for DB lookups.
	 */
	public function __construct( Cache $cache, StringRepository $repository ) {
		$this->cache      = $cache;
		$this->repository = $repository;
	}

	/**
	 * Store a source string and all its known translations in memory.
	 *
	 * The memory is keyed by a normalised version of the source string
	 * so that lookups can be performed efficiently. Each entry stores
	 * the original source value, the domain, and an array of
	 * language => translated_value pairs.
	 *
	 * @param string $source  The source string value.
	 * @param string $domain  The text domain.
	 * @param string $name    The string identifier used during registration.
	 * @param string $context The context used during registration. Default empty.
	 * @return void
	 */
	public function store( string $source, string $domain, string $name, string $context = '' ): void {
		// Compute hash using the same formula as StringManager::computeHash()
		// so the lookup matches the registered string row.
		$hash = md5( $domain . '|' . $context . '|' . $name );

		$string = $this->repository->findByHash( $hash );

		if ( ! $string ) {
			return;
		}

		// Get all translations for this string.
		$translations = $this->repository->getTranslationsForString( (int) $string['id'] );

		// Build the memory entry.
		$lang_map = array();
		foreach ( $translations as $lang => $row ) {
			// Only store completed translations.
			if ( StringManager::STATUS_TRANSLATED === (int) $row['status'] && null !== $row['value'] ) {
				$lang_map[ $lang ] = $row['value'];
			}
		}

		if ( empty( $lang_map ) ) {
			return;
		}

		$entry = array(
			'source'       => $source,
			'domain'       => $domain,
			'translations' => $lang_map,
			'stored_at'    => time(),
		);

		// Store by normalised key for lookup.
		$norm_key = $this->normalise( $source );
		$cache_key = $this->cache->key( 'tm_entry', $norm_key );

		$this->cache->set( $cache_key, $entry, 86400 ); // 24-hour TTL.

		// Also maintain an index of known normalised keys for domain-scoped searches.
		$index_key  = $this->cache->key( 'tm_index', $domain );
		$index_data = $this->cache->get( $index_key, array() );

		if ( ! in_array( $norm_key, $index_data, true ) ) {
			$index_data[] = $norm_key;
			$this->cache->set( $index_key, $index_data, 86400 );
		}
	}

	/**
	 * Suggest translations for a source string based on previously stored
	 * translation memory entries.
	 *
	 * Returns an array of suggestions, each containing:
	 * - `source`: the previously translated source string
	 * - `translations`: language → translated value pairs
	 * - `similarity`: float 0–1 indicating how similar the strings are
	 *
	 * @param string $source  The new source string to find suggestions for.
	 * @param string $domain  The text domain to scope the search.
	 * @param int    $limit   Maximum number of suggestions to return. Default 5.
	 * @return array[] Array of suggestion entries, sorted by similarity (highest first).
	 */
	public function suggest( string $source, string $domain, int $limit = 5 ): array {
		$index_key  = $this->cache->key( 'tm_index', $domain );
		$index_data = $this->cache->get( $index_key, array() );

		if ( empty( $index_data ) || ! is_array( $index_data ) ) {
			return array();
		}

		$suggestions = array();
		$source_norm = $this->normalise( $source );
		$source_len  = strlen( $source_norm );

		// Limit the scan to avoid CPU spikes on large indexes.
		$candidates = array_slice( $index_data, 0, self::MAX_INDEX_SIZE );

		// Pre-filter by string length — skip entries that can't possibly be similar.
		if ( $source_len > 0 ) {
			$candidates = array_filter( $candidates, static function ( string $key ) use ( $source_len ): bool {
				return abs( strlen( $key ) - $source_len ) < $source_len * 0.5;
			} );
		}

		foreach ( $candidates as $norm_key ) {
			$cache_key = $this->cache->key( 'tm_entry', $norm_key );
			$entry     = $this->cache->get( $cache_key );

			if ( ! $entry || ! is_array( $entry ) ) {
				continue;
			}

			$similarity = $this->similarity( $source_norm, $this->normalise( $entry['source'] ) );

			if ( $similarity >= $this->minSimilarity ) {
				$suggestions[] = array(
					'source'       => $entry['source'],
					'translations' => $entry['translations'],
					'similarity'   => round( $similarity, 2 ),
				);
			}
		}

		// Sort by similarity descending.
		usort( $suggestions, static function ( array $a, array $b ): int {
			return $b['similarity'] <=> $a['similarity'];
		} );

		return array_slice( $suggestions, 0, $limit );
	}

	/**
	 * Normalise a string for comparison.
	 *
	 * Lowercases, collapses whitespace, and strips trailing punctuation
	 * so that minor formatting differences don't prevent matching.
	 *
	 * @param string $text The string to normalise.
	 * @return string Normalised string.
	 */
	private function normalise( string $text ): string {
		$text = strtolower( trim( $text ) );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text, '.,;:!?' );

		return $text;
	}

	/**
	 * Calculate similarity between two normalised strings.
	 *
	 * Uses Levenshtein distance with an early-exit optimisation: if
	 * the distance already exceeds 70% of the maximum length the
	 * strings cannot meet the similarity threshold and we bail out
	 * immediately.
	 *
	 * @param string $a Normalised string A.
	 * @param string $b Normalised string B.
	 * @return float Similarity between 0.0 and 1.0.
	 */
	private function similarity( string $a, string $b ): float {
		if ( $a === $b ) {
			return 1.0;
		}

		$max_len = max( strlen( $a ), strlen( $b ) );

		if ( 0 === $max_len ) {
			return 0.0;
		}

		$distance = levenshtein( $a, $b );

		// Early exit — distance too large to meet threshold.
		if ( $distance > $max_len * 0.7 ) {
			return 0.0;
		}

		return 1.0 - ( $distance / $max_len );
	}

	/**
	 * Set the minimum similarity threshold for suggestions.
	 *
	 * @param float $threshold Value between 0.0 and 1.0.
	 */
	public function setMinSimilarity( float $threshold ): void {
		$this->minSimilarity = max( 0.0, min( 1.0, $threshold ) );
	}
}
