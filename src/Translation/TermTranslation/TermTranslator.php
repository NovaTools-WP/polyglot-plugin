<?php
/**
 * Term translator for NovaTools Polyglot.
 *
 * Handles the creation of translated taxonomy terms and their linking
 * via trid. Translated terms are independent WordPress terms that share
 * a translation group with the source term.
 *
 * @package NovaTools\Polyglot\Translation\TermTranslation
 */

namespace NovaTools\Polyglot\Translation\TermTranslation;

use NovaTools\Polyglot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

class TermTranslator {

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
	 * Create a translation of a taxonomy term in a target language.
	 *
	 * Creates a new WordPress term, links it to the source term via a shared
	 * trid, and stores the translation relationship in the database.
	 *
	 * @param int    $sourceId       Source term ID.
	 * @param string $elementType    Element type (e.g. "tax_category").
	 * @param string $targetLanguage Target language code.
	 * @param array  $args           Optional overrides: name, slug, description.
	 * @return int|false New term ID, or false on failure.
	 */
	public function translate( int $sourceId, string $elementType, string $targetLanguage, array $args = array() ): int|false {
		$taxonomy = $this->elementTypeToTaxonomy( $elementType );
		$source   = get_term( $sourceId, $taxonomy );

		if ( ! $source || is_wp_error( $source ) ) {
			return false;
		}

		// Resolve or create trid.
		$existing  = $this->repository->getByElement( $elementType, $sourceId );
		$trid      = $existing ? (int) $existing['trid'] : $this->repository->getNextTrid();
		$sourceLang = $existing ? $existing['language_code'] : polyglot_get_current_language();

		// Check if translation already exists for this language.
		$existingTranslation = $this->repository->getTranslatedElementId( $sourceId, $elementType, $targetLanguage );

		if ( null !== $existingTranslation ) {
			return $existingTranslation;
		}

		// Build term data.
		$name = $args['name'] ?? $source->name;
		$slug = $args['slug'] ?? $this->generateSlug( $source->slug, $targetLanguage );

		$termArgs = array(
			'slug'        => $slug,
			'description' => $args['description'] ?? $source->description,
			'parent'      => $this->resolveTranslatedParent( $source->parent, $taxonomy, $targetLanguage ),
		);

		/**
		 * Filter term data before creating a translation.
		 *
		 * @param array    $termArgs       Associative array for wp_insert_term.
		 * @param \WP_Term $source         Source term object.
		 * @param string   $targetLanguage Target language code.
		 * @param array    $args           Original args passed to translate().
		 */
		$termArgs = apply_filters( 'polyglot_translation_term_data', $termArgs, $source, $targetLanguage, $args );

		$result = wp_insert_term( $name, $taxonomy, $termArgs );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		$newId = (int) $result['term_id'];

		// Save the translation link.
		$this->repository->save( array(
			'element_id'           => $newId,
			'element_type'         => $elementType,
			'trid'                 => $trid,
			'language_code'        => $targetLanguage,
			'source_language_code' => $sourceLang,
			'status'               => 'in_progress',
		) );

		// Ensure the source term has a translation row.
		if ( ! $existing ) {
			$this->repository->save( array(
				'element_id'           => $sourceId,
				'element_type'         => $elementType,
				'trid'                 => $trid,
				'language_code'        => $sourceLang,
				'source_language_code' => '',
				'status'               => 'completed',
			) );
		}

		/**
		 * Fires after a term translation has been created.
		 *
		 * @param int    $newId          New translated term ID.
		 * @param int    $sourceId       Source term ID.
		 * @param string $targetLanguage Target language code.
		 * @param int    $trid           Translation group ID.
		 */
		do_action( 'polyglot_term_translation_created', $newId, $sourceId, $targetLanguage, $trid );

		return $newId;
	}

	/**
	 * Get the term element type string for a given taxonomy.
	 *
	 * @param string $taxonomy WordPress taxonomy name.
	 * @return string Element type (e.g. "tax_category").
	 */
	public static function getElementType( string $taxonomy ): string {
		return 'tax_' . $taxonomy;
	}

	/**
	 * Get the language code assigned to a term.
	 *
	 * @param int    $termId   Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return string|null Language code or null.
	 */
	public function getTermLanguage( int $termId, string $taxonomy ): ?string {
		$row = $this->repository->getByElement( self::getElementType( $taxonomy ), $termId );

		return $row['language_code'] ?? null;
	}

	/**
	 * Get the ID of a term translated to a specific language.
	 *
	 * @param int    $termId         Source term ID.
	 * @param string $taxonomy       Taxonomy name.
	 * @param string $targetLanguage Target language code.
	 * @return int|null Translated term ID, or null.
	 */
	public function getTranslatedTermId( int $termId, string $taxonomy, string $targetLanguage ): ?int {
		return $this->repository->getTranslatedElementId(
			$termId,
			self::getElementType( $taxonomy ),
			$targetLanguage
		);
	}

	/**
	 * Resolve the translated parent term ID for a given language.
	 *
	 * If the source term has a parent, attempts to find the parent's
	 * translation in the target language. Falls back to 0 (no parent).
	 *
	 * @param int    $parentTermId   Source parent term ID.
	 * @param string $taxonomy       Taxonomy name.
	 * @param string $targetLanguage Target language code.
	 * @return int Translated parent term ID, or 0 if none.
	 */
	private function resolveTranslatedParent( int $parentTermId, string $taxonomy, string $targetLanguage ): int {
		if ( 0 === $parentTermId ) {
			return 0;
		}

		$translatedParent = $this->getTranslatedTermId( $parentTermId, $taxonomy, $targetLanguage );

		return $translatedParent ?? 0;
	}

	/**
	 * Generate a unique slug for a translated term.
	 *
	 * Appends the language code to avoid slug collisions.
	 *
	 * @param string $sourceSlug     Source term slug.
	 * @param string $targetLanguage Target language code.
	 * @return string
	 */
	private function generateSlug( string $sourceSlug, string $targetLanguage ): string {
		return $sourceSlug . '-' . $targetLanguage;
	}

	/**
	 * Extract the taxonomy name from an element type string.
	 *
	 * @param string $elementType Element type (e.g. "tax_category").
	 * @return string Taxonomy name.
	 */
	private function elementTypeToTaxonomy( string $elementType ): string {
		return substr( $elementType, 4 ); // Strip "tax_".
	}
}
