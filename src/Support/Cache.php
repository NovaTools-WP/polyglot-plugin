<?php
/**
 * Object cache wrapper for NovaTools Polyglot.
 *
 * Provides a typed interface over WordPress' wp_cache_* functions using
 * the "polyglot" cache group. All operations are scoped to this group
 * to avoid key collisions with other plugins.
 *
 * @package NovaTools\Polyglot\Support
 */

namespace NovaTools\Polyglot\Support;

defined( 'ABSPATH' ) || exit;

class Cache {

	/**
	 * WordPress object-cache group used by all Polyglot cache entries.
	 *
	 * @var string
	 */
	const GROUP = 'polyglot';

	/**
	 * Default TTL for cache entries (1 hour in seconds).
	 *
	 * @var int
	 */
	const DEFAULT_TTL = 3600;

	/**
	 * Retrieve a value from the object cache.
	 *
	 * @param string $key    Cache key.
	 * @param mixed  $default Value returned on cache miss.
	 * @return mixed Cached value or default.
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );

		return $found ? $value : $default;
	}

	/**
	 * Store a value in the object cache.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value The value to cache.
	 * @param int    $ttl   Time-to-live in seconds (default 1 hour).
	 * @return bool True on success.
	 */
	public function set( string $key, mixed $value, int $ttl = self::DEFAULT_TTL ): bool {
		return wp_cache_set( $key, $value, self::GROUP, $ttl );
	}

	/**
	 * Store a value only if the key does not already exist.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value The value to cache.
	 * @param int    $ttl   Time-to-live in seconds.
	 * @return bool True if the value was added, false if key already existed.
	 */
	public function add( string $key, mixed $value, int $ttl = self::DEFAULT_TTL ): bool {
		return wp_cache_add( $key, $value, self::GROUP, $ttl );
	}

	/**
	 * Delete a single cache entry.
	 *
	 * @param string $key Cache key.
	 * @return bool True on success.
	 */
	public function delete( string $key ): bool {
		return wp_cache_delete( $key, self::GROUP );
	}

	/**
	 * Delete multiple cache entries at once.
	 *
	 * @param string[] $keys List of cache keys to delete.
	 * @return int Number of entries actually deleted.
	 */
	public function deleteMany( array $keys ): int {
		$deleted = 0;

		foreach ( $keys as $key ) {
			if ( wp_cache_delete( $key, self::GROUP ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Increment a numeric cache value.
	 *
	 * @param string $key   Cache key.
	 * @param int    $offset Amount to increment by.
	 * @return int|false New value on success, false on failure.
	 */
	public function incr( string $key, int $offset = 1 ): int|false {
		return wp_cache_incr( $key, $offset, self::GROUP );
	}

	/**
	 * Decrement a numeric cache value.
	 *
	 * @param string $key   Cache key.
	 * @param int    $offset Amount to decrement by.
	 * @return int|false New value on success, false on failure.
	 */
	public function decr( string $key, int $offset = 1 ): int|false {
		return wp_cache_decr( $key, $offset, self::GROUP );
	}

	/**
	 * Flush all cache entries in the polyglot group.
	 *
	 * Note: wp_cache_flush_group() is available since WP 6.1.
	 * Falls back to flushing the entire cache on older versions.
	 *
	 * @return bool True on success.
	 */
	public function flushGroup(): bool {
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			return wp_cache_flush_group( self::GROUP );
		}

		// Fallback — flush entire object cache (aggressive).
		return wp_cache_flush();
	}

	/**
	 * Build a namespaced cache key.
	 *
	 * Useful for keys that combine entity type and ID, e.g.
	 *   $cache->key( 'language', 'fr' ) → 'language:fr'
	 *
	 * @param string   $namespace Key namespace / prefix.
	 * @param string|int ...$parts Additional key segments.
	 * @return string Compound cache key.
	 */
	public function key( string $namespace, string|int ...$parts ): string {
		return $namespace . ':' . implode( ':', $parts );
	}
}
