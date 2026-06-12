<?php
/**
 * Post duplication service for NovaTools Polyglot.
 *
 * Duplicates a post to another language, copying all content, custom fields
 * (based on their configured copy/translate/ignore mode), the featured image,
 * and taxonomy assignments. The duplicated post is linked to the original
 * via a shared trid.
 *
 * @package NovaTools\Polyglot\Translation\PostTranslation
 */

namespace NovaTools\Polyglot\Translation\PostTranslation;

use NovaTools\Polyglot\Translation\TranslationRepository;
use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class PostDuplication {

	/**
	 * Translation repository.
	 *
	 * @var TranslationRepository
	 */
	private TranslationRepository $repository;

	/**
	 * Settings store.
	 *
	 * @var OptionStore
	 */
	private OptionStore $options;

	/**
	 * Constructor.
	 *
	 * @param TranslationRepository $repository Translation repository.
	 * @param OptionStore           $options    Settings store.
	 */
	public function __construct(
		TranslationRepository $repository,
		OptionStore $options
	) {
		$this->repository = $repository;
		$this->options    = $options;
	}

	/**
	 * Duplicate a post to another language.
	 *
	 * Creates a new post with the same content, copies custom fields (those
	 * configured as "copy"), transfers the featured image, and assigns the
	 * same taxonomy terms. The new post is linked to the original via trid.
	 *
	 * @param int    $sourceId       Source post ID.
	 * @param string $targetLanguage Target language code.
	 * @return int|false New post ID, or false on failure.
	 */
	public function duplicate( int $sourceId, string $targetLanguage ): int|false {
		$source = get_post( $sourceId );

		if ( ! $source ) {
			return false;
		}

		$elementType = PostTranslator::getElementType( $source->post_type );

		// Check for existing translation in this language.
		$existing = $this->repository->getTranslatedElementId( $sourceId, $elementType, $targetLanguage );

		if ( null !== $existing ) {
			return $existing;
		}

		// Resolve or create trid.
		$sourceRow  = $this->repository->getByElement( $elementType, $sourceId );
		$trid       = $sourceRow ? (int) $sourceRow['trid'] : $this->repository->getNextTrid();
		$sourceLang = $sourceRow ? $sourceRow['language_code'] : polyglot_get_current_language();

		// Create the duplicate post.
		$postData = array(
			'post_title'     => $source->post_title,
			'post_content'   => $source->post_content,
			'post_excerpt'   => $source->post_excerpt,
			'post_status'    => $source->post_status,
			'post_type'      => $source->post_type,
			'post_author'    => get_current_user_id() ?: (int) $source->post_author,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
			'menu_order'     => $source->menu_order,
			'post_password'  => $source->post_password,
		);

		/**
		 * Filter post data before creating a duplicate.
		 *
		 * @param array    $postData       Post data for wp_insert_post.
		 * @param \WP_Post $source         Source post object.
		 * @param string   $targetLanguage Target language code.
		 */
		$postData = apply_filters( 'polyglot_duplicate_post_data', $postData, $source, $targetLanguage );

		$newId = wp_insert_post( $postData );

		if ( is_wp_error( $newId ) || 0 === $newId ) {
			return false;
		}

		// Copy post format.
		$format = get_post_format( $sourceId );
		if ( $format && ! is_wp_error( $format ) ) {
			set_post_format( $newId, $format );
		}

		// Copy custom fields.
		$this->copyCustomFields( $sourceId, $newId );

		// Copy featured image.
		$this->copyFeaturedImage( $sourceId, $newId );

		// Copy taxonomy terms.
		$this->copyTaxonomies( $sourceId, $newId, $source->post_type );

		// Save translation relationship.
		$this->repository->save( array(
			'element_id'           => $newId,
			'element_type'         => $elementType,
			'trid'                 => $trid,
			'language_code'        => $targetLanguage,
			'source_language_code' => $sourceLang,
			'status'               => 'not_translated',
		) );

		// Ensure source has a translation row.
		if ( ! $sourceRow ) {
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
		 * Fires after a post has been duplicated to another language.
		 *
		 * @param int    $newId          New duplicated post ID.
		 * @param int    $sourceId       Source post ID.
		 * @param string $targetLanguage Target language code.
		 * @param int    $trid           Translation group ID.
		 */
		do_action( 'polyglot_post_duplicated', $newId, $sourceId, $targetLanguage, $trid );

		return $newId;
	}

	/**
	 * Copy custom fields from source to target post.
	 *
	 * Copies all meta values for fields configured as "copy" mode.
	 * Internal WordPress fields (starting with "_") are always copied
	 * unless explicitly configured otherwise.
	 *
	 * @param int $sourceId Source post ID.
	 * @param int $targetId Target post ID.
	 */
	private function copyCustomFields( int $sourceId, int $targetId ): void {
		$meta = get_post_meta( $sourceId );

		if ( empty( $meta ) ) {
			return;
		}

		$fieldSettings = $this->options->get( 'custom_fields', array() );

		foreach ( $meta as $key => $values ) {
			// Determine the copy mode for this field.
			$mode = $fieldSettings[ $key ] ?? $this->getDefaultFieldMode( $key );

			if ( 'ignore' === $mode || 'translate' === $mode ) {
				unset( $meta[ $key ] );
				continue;
			}
		}

		if ( empty( $meta ) ) {
			return;
		}

		foreach ( $meta as $key => $values ) {
			// "copy" mode — duplicate all values.
			foreach ( $values as $value ) {
				add_post_meta( $targetId, $key, maybe_unserialize( $value ) );
			}
		}
	}

	/**
	 * Copy the featured image from source to target post.
	 *
	 * @param int $sourceId Source post ID.
	 * @param int $targetId Target post ID.
	 */
	private function copyFeaturedImage( int $sourceId, int $targetId ): void {
		$thumbnailId = get_post_thumbnail_id( $sourceId );

		if ( $thumbnailId ) {
			set_post_thumbnail( $targetId, $thumbnailId );
		}
	}

	/**
	 * Copy taxonomy term assignments from source to target post.
	 *
	 * Assigns the same terms (by term_id) from each taxonomy that the
	 * source post belongs to. Category gets a default of "Uncategorized"
	 * if the target post would otherwise have no category.
	 *
	 * @param int    $sourceId Source post ID.
	 * @param int    $targetId Target post ID.
	 * @param string $postType Post type of the source.
	 */
	private function copyTaxonomies( int $sourceId, int $targetId, string $postType ): void {
		$taxonomies = get_object_taxonomies( $postType );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $sourceId, $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			wp_set_object_terms( $targetId, $terms, $taxonomy );
		}
	}

	/**
	 * Determine the default copy mode for a custom field key.
	 *
	 * Internal WordPress fields (prefixed with "_") default to "copy".
	 * All other fields default to "copy" as well, since duplication
	 * implies copying everything by default.
	 *
	 * @param string $key Meta key.
	 * @return string "copy", "translate", or "ignore".
	 */
	private function getDefaultFieldMode( string $key ): string {
		// WordPress internal fields are always copied.
		if ( str_starts_with( $key, '_' ) ) {
			return 'copy';
		}

		return 'copy';
	}
}
