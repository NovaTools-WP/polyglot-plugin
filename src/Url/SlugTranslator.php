<?php
/**
 * Slug translator for NovaTools Polyglot.
 *
 * Manages per-language post slugs stored in post meta using the
 * key pattern `_polyglot_slug_{lang}`. When a translated slug exists,
 * it replaces the original slug in URL generation for that language.
 *
 * Also provides rewrite rule integration so that translated slugs
 * resolve to the correct post.
 *
 * @package NovaTools\Polyglot\Url
 */

namespace NovaTools\Polyglot\Url;

use NovaTools\Polyglot\Support\Cache;

defined( 'ABSPATH' ) || exit;

class SlugTranslator {

	/**
	 * Post meta key pattern for translated slugs.
	 *
	 * Usage: _polyglot_slug_fr, _polyglot_slug_de, etc.
	 */
	const META_PREFIX = '_polyglot_slug_';

	/**
	 * Cache service.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Constructor.
	 *
	 * @param Cache $cache Cache wrapper.
	 */
	public function __construct( Cache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Get the translated slug for a post in a given language.
	 *
	 * @param int    $postId   Post ID.
	 * @param string $language Target language code.
	 * @return string|null Translated slug, or null if not set.
	 */
	public function getTranslatedSlug( int $postId, string $language ): ?string {
		$cacheKey = $this->cache->key( 'slug', $postId, $language );
		$cached   = $this->cache->get( $cacheKey );

		if ( null !== $cached ) {
			return $cached;
		}

		$metaKey = self::META_PREFIX . $language;
		$slug    = get_post_meta( $postId, $metaKey, true );

		if ( empty( $slug ) ) {
			$this->cache->set( $cacheKey, '' ); // Cache the miss to avoid repeated lookups.
			return null;
		}

		$this->cache->set( $cacheKey, $slug );

		return $slug;
	}

	/**
	 * Set the translated slug for a post in a given language.
	 *
	 * @param int    $postId   Post ID.
	 * @param string $language Language code.
	 * @param string $slug     The translated slug.
	 * @return bool True on success.
	 */
	public function setTranslatedSlug( int $postId, string $language, string $slug ): bool {
		$metaKey = self::META_PREFIX . $language;

		$result = update_post_meta( $postId, $metaKey, $slug );

		// Invalidate cache.
		$this->cache->delete( $this->cache->key( 'slug', $postId, $language ) );

		return false !== $result;
	}

	/**
	 * Delete the translated slug for a post in a given language.
	 *
	 * @param int    $postId   Post ID.
	 * @param string $language Language code.
	 * @return bool True on success.
	 */
	public function deleteTranslatedSlug( int $postId, string $language ): bool {
		$metaKey = self::META_PREFIX . $language;

		$result = delete_post_meta( $postId, $metaKey );

		$this->cache->delete( $this->cache->key( 'slug', $postId, $language ) );

		return $result;
	}

	/**
	 * Delete all translated slugs for a given post.
	 *
	 * @param int $postId Post ID.
	 * @return int Number of slugs deleted.
	 */
	public function deleteAllSlugs( int $postId ): int {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
				$postId,
				$wpdb->esc_like( self::META_PREFIX ) . '%'
			)
		);

		// Flush cached slugs for this post (all languages).
		$this->cache->flushGroup();

		return (int) $deleted;
	}

	/**
	 * Find a post ID by its translated slug and language.
	 *
	 * Useful for resolving incoming translated-slug URLs to the correct post.
	 *
	 * @param string $slug     The translated slug to look up.
	 * @param string $language The language code.
	 * @return int|null Post ID, or null if no match.
	 */
	public function getPostIdBySlug( string $slug, string $language ): ?int {
		$cacheKey = $this->cache->key( 'slug_lookup', $language, $slug );
		$cached   = $this->cache->get( $cacheKey );

		if ( null !== $cached ) {
			return $cached > 0 ? $cached : null;
		}

		global $wpdb;

		$metaKey = self::META_PREFIX . $language;

		$postId = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				$metaKey,
				$slug
			)
		);

		if ( $postId ) {
			$this->cache->set( $cacheKey, (int) $postId );
			return (int) $postId;
		}

		// Cache the miss.
		$this->cache->set( $cacheKey, 0 );

		return null;
	}

	/**
	 * Filter the permalink for a post to use the translated slug.
	 *
	 * Designed to be hooked into `post_link` / `post_type_link`.
	 *
	 * @param string      $permalink The original permalink.
	 * @param \WP_Post    $post      The post object.
	 * @param string|null $language  Target language code (defaults to current).
	 * @return string Modified permalink with translated slug.
	 */
	public function filterPermalink( string $permalink, \WP_Post $post, ?string $language = null ): string {
		if ( null === $language ) {
			return $permalink;
		}

		$translated = $this->getTranslatedSlug( $post->ID, $language );

		if ( null === $translated || '' === $translated ) {
			return $permalink;
		}

		// Replace the post slug in the permalink.
		$originalSlug = $post->post_name;

		if ( '' === $originalSlug ) {
			return $permalink;
		}

		// Replace only the last occurrence of the original slug to avoid
		// corrupting path segments that might coincidentally match.
		$pos = strrpos( $permalink, $originalSlug );

		if ( false !== $pos ) {
			return substr_replace( $permalink, $translated, $pos, strlen( $originalSlug ) );
		}

		return $permalink;
	}
}
