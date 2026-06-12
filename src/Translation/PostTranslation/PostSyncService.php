<?php
/**
 * Post synchronisation service for NovaTools Polyglot.
 *
 * Detects changes in source posts and flags their translations as
 * "needs_update" by comparing an MD5 checksum of the post content.
 * Also provides a method to recalculate checksums on demand.
 *
 * @package NovaTools\Polyglot\Translation\PostTranslation
 */

namespace NovaTools\Polyglot\Translation\PostTranslation;

use NovaTools\Polyglot\Support\OptionStore;
use NovaTools\Polyglot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

class PostSyncService {

	/**
	 * Translation repository.
	 *
	 * @var TranslationRepository
	 */
	private TranslationRepository $repository;

	/**
	 * Option store for reading translatable post type settings.
	 *
	 * @var OptionStore
	 */
	private OptionStore $optionStore;

	/**
	 * Constructor.
	 *
	 * @param TranslationRepository $repository  Translation repository.
	 * @param OptionStore           $optionStore Option store.
	 */
	public function __construct( TranslationRepository $repository, OptionStore $optionStore ) {
		$this->repository  = $repository;
		$this->optionStore = $optionStore;
	}

	/**
	 * Register WordPress hooks for automatic sync.
	 *
	 * Hooks into `save_post` to detect changes and flag translations.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'save_post', array( $this, 'onSavePost' ), 10, 3 );
	}

	/**
	 * Callback for the `save_post` action.
	 *
	 * Computes a checksum for the saved post and compares it against the
	 * stored value. If the checksum differs, all translations in the same
	 * trid group are flagged as "needs_update".
	 *
	 * @param int     $postId Post ID.
	 * @param \WP_Post $post   Post object.
	 * @param bool    $update Whether this is an update (vs. new post).
	 */
	public function onSavePost( int $postId, \WP_Post $post, bool $update ): void {
		// Skip autosaves and revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $postId ) ) {
			return;
		}

		// Bail early if post type isn't configured for translation.
		$translatableTypes = $this->optionStore->get( 'post_types', array() );
		if ( ! in_array( $post->post_type, $translatableTypes, true ) ) {
			return;
		}

		$elementType = PostTranslator::getElementType( $post->post_type );

		if ( ! $update ) {
			// New post — store initial checksum.
			$this->updateChecksum( $postId, $elementType );
			return;
		}

		$this->detectChanges( $postId, $elementType );
	}

	/**
	 * Detect changes in a source post and flag translations as needing update.
	 *
	 * @param int    $postId      Post ID.
	 * @param string $elementType Element type (e.g. "post_post").
	 * @return bool True if changes were detected and translations were flagged.
	 */
	public function detectChanges( int $postId, string $elementType ): bool {
		$row = $this->repository->getByElement( $elementType, $postId );

		if ( ! $row ) {
			// No translation row — nothing to sync.
			return false;
		}

		// Only check source elements (those with empty source_language_code).
		if ( ! empty( $row['source_language_code'] ) ) {
			return false;
		}

		$newChecksum = $this->computeChecksum( $postId );
		$oldChecksum = $row['checksum'] ?? '';

		// Always update the stored checksum.
		$this->updateChecksum( $postId, $elementType );

		if ( $newChecksum === $oldChecksum ) {
			return false;
		}

		// Content changed — flag all translations in the group as needs_update.
		return $this->flagTranslationsNeedsUpdate( (int) $row['trid'] );
	}

	/**
	 * Compute an MD5 checksum for a post's translatable content.
	 *
	 * Includes post_title, post_content, and post_excerpt so that
	 * changes to any of these fields trigger an update flag.
	 *
	 * @param int $postId Post ID.
	 * @return string 32-character hex MD5 hash.
	 */
	public function computeChecksum( int $postId ): string {
		$post = get_post( $postId );

		if ( ! $post ) {
			return '';
		}

		$content = wp_json_encode( array(
			'title'   => $post->post_title,
			'content' => $post->post_content,
			'excerpt' => $post->post_excerpt,
		) );

		return md5( $content );
	}

	/**
	 * Update the stored checksum for a post's translation row.
	 *
	 * @param int    $postId      Post ID.
	 * @param string $elementType Element type.
	 * @return bool
	 */
	public function updateChecksum( int $postId, string $elementType ): bool {
		$row = $this->repository->getByElement( $elementType, $postId );

		if ( ! $row ) {
			return false;
		}

		$checksum = $this->computeChecksum( $postId );

		global $wpdb;

		$table = \NovaTools\Polyglot\Database\Schema::getTableName( 'polyglot_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->update(
			$table,
			array( 'checksum' => $checksum ),
			array(
				'element_type' => $elementType,
				'element_id'   => $postId,
			)
		);

		// Invalidate cached row so next read picks up the new checksum.
		$this->repository->invalidateCache( (int) $row['trid'], $elementType, $postId );

		return (bool) $updated;
	}

	/**
	 * Flag all non-source translations in a trid group as "needs_update".
	 *
	 * Only translations that are currently "completed", "translated", or
	 * "awaiting_review" are changed to "needs_update". Other statuses are
	 * left as-is.
	 *
	 * @param int $trid Translation group ID.
	 * @return bool True if any rows were updated.
	 */
	private function flagTranslationsNeedsUpdate( int $trid ): bool {
		$rows = $this->repository->getByTrid( $trid );

		if ( empty( $rows ) ) {
			return false;
		}

		$flaggable = array( 'completed', 'translated', 'awaiting_review' );
		$updated   = false;

		foreach ( $rows as $row ) {
			// Skip source elements.
			if ( empty( $row['source_language_code'] ) ) {
				continue;
			}

			// Only flag translations that were considered "done".
			if ( ! in_array( $row['status'], $flaggable, true ) ) {
				continue;
			}

			$result = $this->repository->updateStatus(
				$row['element_type'],
				(int) $row['element_id'],
				'needs_update'
			);

			if ( $result ) {
				$updated = true;
			}
		}

		return $updated;
	}

	/**
	 * Batch-recalculate checksums for all posts of a given type.
	 *
	 * Useful after initial import or plugin activation to seed checksums.
	 *
	 * @param string $postType Post type to process.
	 * @return int Number of posts updated.
	 */
	public function recalculateChecksums( string $postType = 'post' ): int {
		$elementType = PostTranslator::getElementType( $postType );
		$count       = 0;
		$offset      = 0;
		$batchSize   = 200;

		do {
			$posts = get_posts( array(
				'post_type'      => $postType,
				'posts_per_page' => $batchSize,
				'offset'         => $offset,
				'post_status'    => 'any',
				'fields'         => 'ids',
			) );

			foreach ( $posts as $postId ) {
				if ( $this->updateChecksum( (int) $postId, $elementType ) ) {
					++$count;
				}
			}

			$offset += $batchSize;
		} while ( count( $posts ) === $batchSize );

		return $count;
	}
}
