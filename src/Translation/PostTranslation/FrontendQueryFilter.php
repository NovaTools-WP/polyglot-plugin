<?php
/**
 * Frontend query filter for NovaTools Polyglot.
 *
 * Filters WordPress queries and get_pages calls on the frontend to only show
 * posts and pages that match the current request language.
 *
 * @package NovaTools\Polyglot\Translation\PostTranslation
 */

namespace NovaTools\Polyglot\Translation\PostTranslation;

use NovaTools\Polyglot\Language\LanguageRepository;
use NovaTools\Polyglot\Translation\TranslationRepository;
use NovaTools\Polyglot\Database\Schema;

defined( 'ABSPATH' ) || exit;

class FrontendQueryFilter {

	/**
	 * Language repository instance.
	 *
	 * @var LanguageRepository
	 */
	private LanguageRepository $languageRepository;

	/**
	 * Translation repository instance.
	 *
	 * @var TranslationRepository
	 */
	private TranslationRepository $translationRepository;

	/**
	 * Constructor.
	 *
	 * @param LanguageRepository    $languageRepository    Language repository.
	 * @param TranslationRepository $translationRepository Translation repository.
	 */
	public function __construct(
		LanguageRepository $languageRepository,
		TranslationRepository $translationRepository
	) {
		$this->languageRepository    = $languageRepository;
		$this->translationRepository = $translationRepository;
	}

	/**
	 * Register frontend query filtering hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'get_pages', array( $this, 'filterGetPages' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'filterPreGetPosts' ) );

		add_action( 'save_post', array( $this, 'invalidateCache' ) );
		add_action( 'before_delete_post', array( $this, 'invalidateCache' ) );
	}

	/**
	 * Filter get_pages() results to only include pages in the current language.
	 *
	 * @param array $pages Array of page objects.
	 * @param array $r     Query arguments.
	 * @return array Filtered array of page objects.
	 */
	public function filterGetPages( array $pages, array $r ): array {
		if ( is_admin() || empty( $pages ) ) {
			return $pages;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $pages;
		}

		$current_lang = polyglot_get_current_language();
		$default_lang = $this->languageRepository->getDefault();
		$default_code = $default_lang ? $default_lang->code : 'en';

		$page_ids     = wp_list_pluck( $pages, 'ID' );
		$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );

		global $wpdb;
		$table = Schema::getTableName( 'polyglot_translations' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$db_languages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT element_id, language_code FROM {$table} WHERE element_type LIKE 'post_%%' AND element_id IN ({$placeholders})",
				$page_ids
			),
			OBJECT_K
		);

		$filtered = array();

		foreach ( $pages as $page ) {
			$lang = isset( $db_languages[ $page->ID ] ) ? $db_languages[ $page->ID ]->language_code : $default_code;

			if ( $lang === $current_lang ) {
				$filtered[] = $page;
			}
		}

		return $filtered;
	}

	/**
	 * Filter post queries (archives, main loop) to exclude posts in other languages.
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 * @return void
	 */
	public function filterPreGetPosts( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		// Avoid modifying queries for revisions or menus.
		$post_types = (array) $query->get( 'post_type' );
		if ( in_array( 'nav_menu_item', $post_types, true ) || in_array( 'revision', $post_types, true ) ) {
			return;
		}

		$current_lang = polyglot_get_current_language();
		$default_lang = $this->languageRepository->getDefault();
		$default_code = $default_lang ? $default_lang->code : 'en';

		global $wpdb;
		$table = Schema::getTableName( 'polyglot_translations' );

		if ( $current_lang === $default_code ) {
			// Current language is default. Exclude posts in other languages.
			$cache_key   = "frontend_excluded_posts:{$default_code}";
			$exclude_ids = wp_cache_get( $cache_key, 'polyglot' );

			if ( false === $exclude_ids ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$exclude_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT element_id FROM {$table} WHERE element_type LIKE 'post_%%' AND language_code != %s",
						$default_code
					)
				);
				if ( ! is_array( $exclude_ids ) ) {
					$exclude_ids = array();
				}
				wp_cache_set( $cache_key, $exclude_ids, 'polyglot', 12 * HOUR_IN_SECONDS );
			}

			if ( ! empty( $exclude_ids ) ) {
				$exclude_ids = array_map( 'intval', $exclude_ids );
				$query->set( 'post__not_in', array_merge(
					$query->get( 'post__not_in' ) ?: array(),
					$exclude_ids
				) );
			}
		} else {
			// Current language is non-default. Only include posts in this language.
			$cache_key   = "frontend_included_posts:{$current_lang}";
			$include_ids = wp_cache_get( $cache_key, 'polyglot' );

			if ( false === $include_ids ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$include_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT element_id FROM {$table} WHERE element_type LIKE 'post_%%' AND language_code = %s",
						$current_lang
					)
				);
				if ( ! is_array( $include_ids ) ) {
					$include_ids = array();
				}
				wp_cache_set( $cache_key, $include_ids, 'polyglot', 12 * HOUR_IN_SECONDS );
			}

			if ( ! empty( $include_ids ) ) {
				$include_ids = array_map( 'intval', $include_ids );

				// If query already has post__in, intersect them.
				$existing_in = $query->get( 'post__in' );
				if ( ! empty( $existing_in ) && is_array( $existing_in ) ) {
					$query->set( 'post__in', array_intersect( $existing_in, $include_ids ) );
				} else {
					$query->set( 'post__in', $include_ids );
				}
			} else {
				// No posts in this language, return empty result set.
				$query->set( 'post__in', array( 0 ) );
			}
		}
	}

	/**
	 * Invalidate frontend cache keys when posts are saved or deleted.
	 *
	 * @return void
	 */
	public function invalidateCache(): void {
		$languages = $this->languageRepository->getActive();

		foreach ( $languages as $lang ) {
			wp_cache_delete( "frontend_excluded_posts:{$lang->code}", 'polyglot' );
			wp_cache_delete( "frontend_included_posts:{$lang->code}", 'polyglot' );
		}
	}
}
