<?php
/**
 * Media sync service for NovaTools Polyglot.
 *
 * Handles batch media duplication across languages and WooCommerce product
 * gallery translation. Provides bulk operations for translating all
 * untranslated media attachments in one pass.
 *
 * @package NovaTools\Polyglot\Media
 */

namespace NovaTools\Polyglot\Media;

use NovaTools\Polyglot\Support\Cache;
use NovaTools\Polyglot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

class MediaSyncService {

	/**
	 * Media translator instance.
	 *
	 * @var MediaTranslator
	 */
	private MediaTranslator $translator;

	/**
	 * Media repository instance.
	 *
	 * @var MediaRepository
	 */
	private MediaRepository $repository;

	/**
	 * Translation repository instance.
	 *
	 * @var TranslationRepository
	 */
	private TranslationRepository $translationRepository;

	/**
	 * Cache wrapper instance.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Constructor.
	 *
	 * @param MediaTranslator       $translator            The media translator.
	 * @param MediaRepository       $repository            The media repository.
	 * @param TranslationRepository $translationRepository The translation repository.
	 * @param Cache                 $cache                 The polyglot cache wrapper.
	 */
	public function __construct(
		MediaTranslator $translator,
		MediaRepository $repository,
		TranslationRepository $translationRepository,
		Cache $cache
	) {
		$this->translator            = $translator;
		$this->repository            = $repository;
		$this->translationRepository = $translationRepository;
		$this->cache                 = $cache;
	}

	/**
	 * Batch duplicate all untranslated media from a source language to a target language.
	 *
	 * Processes attachments in configurable batches to avoid memory exhaustion
	 * and timeout issues on large media libraries.
	 *
	 * @param string $sourceLanguage Source language code (e.g. "en").
	 * @param string $targetLanguage Target language code (e.g. "fr").
	 * @param int    $batchSize      Number of attachments per batch (default 50).
	 * @return array{translated: int, skipped: int, errors: int, error_ids: int[]} Result summary.
	 */
	public function batchDuplicate(
		string $sourceLanguage,
		string $targetLanguage,
		int $batchSize = 50
	): array {
		// Defer term counting for performance during bulk operations.
		wp_defer_term_counting( true );

		$translated = 0;
		$skipped    = 0;
		$errors     = 0;
		$errorIds   = array();

		// Get all untranslated media for the target language.
		$untranslated = $this->repository->getUntranslatedMedia( $targetLanguage, $sourceLanguage );

		if ( empty( $untranslated ) ) {
			return array(
				'translated' => 0,
				'skipped'    => 0,
				'errors'     => 0,
				'error_ids'  => array(),
			);
		}

		// Process in batches.
		$batches = array_chunk( $untranslated, $batchSize );

		foreach ( $batches as $batch ) {
			foreach ( $batch as $attachmentId ) {
				$result = $this->translator->duplicate( $attachmentId, $targetLanguage );

				if ( $result > 0 ) {
					++$translated;
				} else {
					++$errors;
					$errorIds[] = $attachmentId;
				}
			}

			// Clear polyglot cache between batches to keep memory usage stable.
			$this->cache->flushGroup();
		}

		// Restore term counting.
		wp_defer_term_counting( false );

		/**
		 * Fires after a batch media duplication completes.
		 *
		 * @param int    $translated      Number of successfully translated attachments.
		 * @param int    $errors          Number of errors encountered.
		 * @param string $sourceLanguage  Source language code.
		 * @param string $targetLanguage  Target language code.
		 */
		do_action( 'polyglot_media_batch_duplicate_complete', $translated, $errors, $sourceLanguage, $targetLanguage );

		return array(
			'translated' => $translated,
			'skipped'    => $skipped,
			'errors'     => $errors,
			'error_ids'  => $errorIds,
		);
	}

	/**
	 * Translate the product gallery for a single post.
	 *
	 * Duplicates each gallery image attachment for the target language and
	 * updates the translated post's `_product_image_gallery` meta with the
	 * new attachment IDs.
	 *
	 * Works for WooCommerce products (`_product_image_gallery`) and any
	 * post type that stores gallery image IDs as a comma-separated string
	 * in a meta key.
	 *
	 * @param int    $postId        Source post ID (e.g. WooCommerce product).
	 * @param string $targetLanguage Target language code.
	 * @param string $metaKey       Meta key holding gallery IDs (default: '_product_image_gallery').
	 * @return bool True on success, false if no gallery to translate.
	 */
	public function translateGallery(
		int $postId,
		string $targetLanguage,
		string $metaKey = '_product_image_gallery'
	): bool {
		$gallery = get_post_meta( $postId, $metaKey, true );

		if ( empty( $gallery ) ) {
			return false;
		}

		$sourceIds = array_filter( array_map( 'intval', explode( ',', $gallery ) ) );

		if ( empty( $sourceIds ) ) {
			return false;
		}

		// Find the translated post.
		$translatedPostId = $this->getTranslatedPostId( $postId, $targetLanguage );

		if ( ! $translatedPostId ) {
			return false;
		}

		// Duplicate each gallery image for the target language.
		$translatedIds = array();

		foreach ( $sourceIds as $sourceId ) {
			$duplicatedId = $this->translator->duplicate( $sourceId, $targetLanguage );

			if ( $duplicatedId > 0 ) {
				$translatedIds[] = $duplicatedId;
			}
		}

		if ( empty( $translatedIds ) ) {
			return false;
		}

		// Update the translated post's gallery meta.
		update_post_meta( $translatedPostId, $metaKey, implode( ',', $translatedIds ) );

		/**
		 * Fires after a post gallery has been translated.
		 *
		 * @param int    $translatedPostId The translated post ID.
		 * @param int    $postId           Source post ID.
		 * @param string $targetLanguage   Target language code.
		 * @param int[]  $translatedIds    New gallery attachment IDs.
		 */
		do_action( 'polyglot_media_gallery_translated', $translatedPostId, $postId, $targetLanguage, $translatedIds );

		return true;
	}

	/**
	 * Batch translate galleries for all posts of a given type to a target language.
	 *
	 * Finds all posts that have gallery meta and duplicates their gallery
	 * images for the target language, updating translated posts accordingly.
	 *
	 * @param string $targetLanguage Target language code.
	 * @param string $postType       Post type to process (default: 'product').
	 * @param string $metaKey        Meta key holding gallery IDs (default: '_product_image_gallery').
	 * @param int    $batchSize      Number of posts per batch (default 20).
	 * @return array{translated: int, skipped: int, errors: int} Result summary.
	 */
	public function batchTranslateGalleries(
		string $targetLanguage,
		string $postType = 'product',
		string $metaKey = '_product_image_gallery',
		int $batchSize = 20
	): array {
		$translated = 0;
		$skipped    = 0;
		$errors     = 0;

		wp_defer_term_counting( true );

		$offset = 0;

		do {
			$posts = get_posts( array(
				'post_type'      => $postType,
				'posts_per_page' => $batchSize,
				'offset'         => $offset,
				'meta_key'       => $metaKey,
				'meta_compare'   => '!=',
				'meta_value'     => '',
				'fields'         => 'ids',
			) );

			foreach ( $posts as $postId ) {
				$result = $this->translateGallery( (int) $postId, $targetLanguage, $metaKey );

				if ( $result ) {
					++$translated;
				} else {
					++$skipped;
				}
			}

			wp_cache_flush();

			$offset += $batchSize;
		} while ( count( $posts ) === $batchSize );

		wp_defer_term_counting( false );

		/**
		 * Fires after batch gallery translation completes.
		 *
		 * @param int    $translated     Number of galleries successfully translated.
		 * @param int    $skipped        Number of galleries skipped (no translation target).
		 * @param string $targetLanguage Target language code.
		 * @param string $postType       Post type that was processed.
		 */
		do_action( 'polyglot_media_batch_galleries_complete', $translated, $skipped, $targetLanguage, $postType );

		return array(
			'translated' => $translated,
			'skipped'    => $skipped,
			'errors'     => $errors,
		);
	}

	/**
	 * Find the translated post ID for a source post and target language.
	 *
	 * @param int    $postId        Source post ID.
	 * @param string $targetLanguage Target language code.
	 * @return int|null Translated post ID, or null if not found.
	 */
	private function getTranslatedPostId( int $postId, string $targetLanguage ): ?int {
		return $this->translationRepository->getTranslatedElementId(
			$postId,
			'post',
			$targetLanguage
		);
	}
}
