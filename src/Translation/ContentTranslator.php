<?php
/**
 * Content translator orchestrator for NovaTools Polyglot.
 *
 * Coordinates post and term translation operations by delegating to
 * specialised translators. This is the main entry point for translating
 * content elements — it resolves the correct sub-translator based on
 * element type and fires before/after hooks.
 *
 * @package NovaTools\Polyglot\Translation
 */

namespace NovaTools\Polyglot\Translation;

use NovaTools\Polyglot\Support\HookManager;
use NovaTools\Polyglot\Translation\PostTranslation\PostTranslator;
use NovaTools\Polyglot\Translation\TermTranslation\TermTranslator;

defined( 'ABSPATH' ) || exit;

class ContentTranslator {

	/**
	 * Translation repository.
	 *
	 * @var TranslationRepository
	 */
	private TranslationRepository $repository;

	/**
	 * Hook manager.
	 *
	 * @var HookManager
	 */
	private HookManager $hooks;

	/**
	 * Post translator sub-service.
	 *
	 * @var PostTranslator
	 */
	private PostTranslator $postTranslator;

	/**
	 * Term translator sub-service.
	 *
	 * @var TermTranslator
	 */
	private TermTranslator $termTranslator;

	/**
	 * Constructor.
	 *
	 * @param TranslationRepository $repository      Translation repository.
	 * @param HookManager           $hooks           Hook manager.
	 * @param PostTranslator        $postTranslator  Post translator sub-service.
	 * @param TermTranslator        $termTranslator  Term translator sub-service.
	 */
	public function __construct(
		TranslationRepository $repository,
		HookManager $hooks,
		PostTranslator $postTranslator,
		TermTranslator $termTranslator
	) {
		$this->repository      = $repository;
		$this->hooks           = $hooks;
		$this->postTranslator  = $postTranslator;
		$this->termTranslator  = $termTranslator;
	}

	/**
	 * Translate a content element to a target language.
	 *
	 * Dispatches to the appropriate sub-translator based on element type.
	 * Fires `polyglot_before_translate` and `polyglot_after_translate` hooks.
	 *
	 * @param int    $sourceId       Source element ID (post ID, term ID, etc.).
	 * @param string $elementType    Element type (e.g. "post_post", "tax_category").
	 * @param string $targetLanguage Target language code.
	 * @param array  $args           Optional arguments passed to the sub-translator.
	 * @return int|false The new translated element ID, or false on failure.
	 */
	public function translate( int $sourceId, string $elementType, string $targetLanguage, array $args = array() ): int|false {
		/**
		 * Fires before a content element is translated.
		 *
		 * @param int    $sourceId       Source element ID.
		 * @param string $elementType    Element type.
		 * @param string $targetLanguage Target language code.
		 * @param array  $args           Additional arguments.
		 */
		$this->hooks->doAction( 'polyglot_before_translate', $sourceId, $elementType, $targetLanguage, $args );

		$result = false;

		if ( $this->isPostType( $elementType ) ) {
			$result = $this->postTranslator->translate( $sourceId, $elementType, $targetLanguage, $args );
		} elseif ( $this->isTermType( $elementType ) ) {
			$result = $this->termTranslator->translate( $sourceId, $elementType, $targetLanguage, $args );
		}

		/**
		 * Fires after a content element has been translated.
		 *
		 * @param int|false $result         New element ID, or false on failure.
		 * @param int       $sourceId       Source element ID.
		 * @param string    $elementType    Element type.
		 * @param string    $targetLanguage Target language code.
		 */
		$this->hooks->doAction( 'polyglot_after_translate', $result, $sourceId, $elementType, $targetLanguage );

		return $result;
	}

	/**
	 * Save a translation relationship between two elements.
	 *
	 * Low-level method used by sub-translators to persist the trid link.
	 *
	 * @param int    $elementId           The new translated element ID.
	 * @param string $elementType         Element type.
	 * @param string $languageCode        Language of the translated element.
	 * @param string $sourceLanguageCode  Language of the source element.
	 * @param int    $trid                Translation group ID.
	 * @param string $status              Translation status.
	 * @return int|false translation_id on success, false on failure.
	 */
	public function saveTranslation(
		int    $elementId,
		string $elementType,
		string $languageCode,
		string $sourceLanguageCode,
		int    $trid,
		string $status = 'not_translated'
	): int|false {
		return $this->repository->save( array(
			'element_id'           => $elementId,
			'element_type'         => $elementType,
			'trid'                 => $trid,
			'language_code'        => $languageCode,
			'source_language_code' => $sourceLanguageCode,
			'status'               => $status,
		) );
	}

	/**
	 * Get the language code for an element.
	 *
	 * @param int    $elementId   Element ID.
	 * @param string $elementType Element type.
	 * @return string|null Language code, or null if the element has no translation row.
	 */
	public function getElementLanguage( int $elementId, string $elementType ): ?string {
		$row = $this->repository->getByElement( $elementType, $elementId );

		if ( ! $row ) {
			return null;
		}

		return $row['language_code'] ?? null;
	}

	/**
	 * Get the translation group for an element.
	 *
	 * @param int    $elementId   Element ID.
	 * @param string $elementType Element type.
	 * @return TranslationGroup|null
	 */
	public function getTranslationGroup( int $elementId, string $elementType ): ?TranslationGroup {
		return $this->repository->getGroup( $elementType, $elementId );
	}

	/**
	 * Get the translated element ID for a given source and target language.
	 *
	 * @param int    $sourceId       Source element ID.
	 * @param string $elementType    Element type.
	 * @param string $targetLanguage Target language code.
	 * @return int|null Translated element ID, or null.
	 */
	public function getTranslatedId( int $sourceId, string $elementType, string $targetLanguage ): ?int {
		return $this->repository->getTranslatedElementId( $sourceId, $elementType, $targetLanguage );
	}

	/**
	 * Delete a translation relationship.
	 *
	 * @param int    $elementId   Element ID.
	 * @param string $elementType Element type.
	 * @return bool
	 */
	public function deleteTranslation( int $elementId, string $elementType ): bool {
		return $this->repository->delete( $elementType, $elementId );
	}

	/**
	 * Assign a language to an element that has no translation row yet.
	 *
	 * Creates a new trid group for the element.
	 *
	 * @param int    $elementId    Element ID.
	 * @param string $elementType  Element type.
	 * @param string $languageCode Language to assign.
	 * @return int|false translation_id on success, false on failure.
	 */
	public function setElementLanguage( int $elementId, string $elementType, string $languageCode ): int|false {
		// Check if already assigned.
		$existing = $this->repository->getByElement( $elementType, $elementId );

		if ( $existing ) {
			// Update existing row with new language.
			return $this->repository->save( array(
				'element_id'    => $elementId,
				'element_type'  => $elementType,
				'trid'          => (int) $existing['trid'],
				'language_code' => $languageCode,
				'status'        => $existing['status'] ?? 'not_translated',
			) );
		}

		// Create new group.
		$trid = $this->repository->getNextTrid();

		return $this->repository->save( array(
			'element_id'    => $elementId,
			'element_type'  => $elementType,
			'trid'          => $trid,
			'language_code' => $languageCode,
			'status'        => 'not_translated',
		) );
	}

	/**
	 * Whether an element type refers to a post.
	 *
	 * @param string $elementType Element type string.
	 * @return bool
	 */
	public function isPostType( string $elementType ): bool {
		return str_starts_with( $elementType, 'post_' );
	}

	/**
	 * Whether an element type refers to a taxonomy term.
	 *
	 * @param string $elementType Element type string.
	 * @return bool
	 */
	private function isTermType( string $elementType ): bool {
		return str_starts_with( $elementType, 'tax_' );
	}
}
