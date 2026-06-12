<?php
/**
 * Term synchronisation service for NovaTools Polyglot.
 *
 * Listens for term edits and updates translation status accordingly.
 * Keeps term hierarchy consistent across languages by resolving parent
 * relationships during sync.
 *
 * @package NovaTools\Polyglot\Translation\TermTranslation
 */

namespace NovaTools\Polyglot\Translation\TermTranslation;

use NovaTools\Polyglot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

class TermSyncService {

	/**
	 * Translation repository.
	 *
	 * @var TranslationRepository
	 */
	private TranslationRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param TranslationRepository $repository Translation repository.
	 */
	public function __construct( TranslationRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register WordPress hooks for automatic term sync.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'edited_term', array( $this, 'onEditedTerm' ), 10, 3 );
		add_action( 'created_term', array( $this, 'onCreatedTerm' ), 10, 3 );
	}

	/**
	 * Callback for the `edited_term` action.
	 *
	 * When a source term is edited, flag all its translations as
	 * "needs_update" so that translators know the source changed.
	 *
	 * @param int    $termId  Term ID.
	 * @param int    $ttId    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function onEditedTerm( int $termId, int $ttId, string $taxonomy ): void {
		$elementType = TermTranslator::getElementType( $taxonomy );
		$row         = $this->repository->getByElement( $elementType, $termId );

		if ( ! $row ) {
			return;
		}

		// Only flag for source terms (empty source_language_code).
		if ( ! empty( $row['source_language_code'] ) ) {
			return;
		}

		$this->flagTranslationsNeedsUpdate( (int) $row['trid'] );
	}

	/**
	 * Callback for the `created_term` action.
	 *
	 * Stores initial translation status for newly created terms that
	 * are part of a translation group.
	 *
	 * @param int    $termId   Term ID.
	 * @param int    $ttId     Term taxonomy ID.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function onCreatedTerm( int $termId, int $ttId, string $taxonomy ): void {
		// No-op: newly created terms get their translation row from
		// the TermTranslator or ContentTranslator. This hook is a
		// placeholder for future logic (e.g. auto-sync).
	}

	/**
	 * Update the translation status of a specific term.
	 *
	 * @param int    $termId   Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param string $status   New status value.
	 * @return bool
	 */
	public function updateStatus( int $termId, string $taxonomy, string $status ): bool {
		$elementType = TermTranslator::getElementType( $taxonomy );

		return $this->repository->updateStatus( $elementType, $termId, $status );
	}

	/**
	 * Mark a term translation as completed.
	 *
	 * @param int    $termId   Term ID of the translation.
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function markCompleted( int $termId, string $taxonomy ): bool {
		return $this->updateStatus( $termId, $taxonomy, 'completed' );
	}

	/**
	 * Mark a term translation as in progress.
	 *
	 * @param int    $termId   Term ID of the translation.
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function markInProgress( int $termId, string $taxonomy ): bool {
		return $this->updateStatus( $termId, $taxonomy, 'in_progress' );
	}

	/**
	 * Sync term hierarchy across languages in a translation group.
	 *
	 * For each translated term in the group, resolves the parent term's
	 * translation and updates the translated term's parent to match.
	 * This keeps the term tree consistent across languages.
	 *
	 * @param int    $termId   Source term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return int Number of translations updated.
	 */
	public function syncHierarchy( int $termId, string $taxonomy ): int {
		$elementType = TermTranslator::getElementType( $taxonomy );
		$group       = $this->repository->getGroup( $elementType, $termId );

		if ( ! $group ) {
			return 0;
		}

		$sourceTerm = get_term( $termId, $taxonomy );

		if ( ! $sourceTerm || is_wp_error( $sourceTerm ) ) {
			return 0;
		}

		$updated = 0;

		foreach ( $group->getLanguageCodes() as $lang ) {
			// Skip the source language.
			if ( $lang === $group->sourceLanguageCode ) {
				continue;
			}

			$translatedId = $group->getElementId( $lang );

			if ( null === $translatedId ) {
				continue;
			}

			// Resolve parent.
			$parentId = 0;

			if ( $sourceTerm->parent > 0 ) {
				$translatedParent = $this->repository->getTranslatedElementId(
					$sourceTerm->parent,
					$elementType,
					$lang
				);

				$parentId = $translatedParent ?? 0;
			}

			// Update the translated term's parent.
			wp_update_term( $translatedId, $taxonomy, array(
				'parent' => $parentId,
			) );

			++$updated;
		}

		return $updated;
	}

	/**
	 * Flag all non-source translations in a trid group as "needs_update".
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
			if ( empty( $row['source_language_code'] ) ) {
				continue;
			}

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
}
