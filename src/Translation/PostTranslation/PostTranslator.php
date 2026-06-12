<?php
/**
 * Post translator for NovaTools Polyglot.
 *
 * Handles the creation of translated posts and their linking via trid.
 * Copies post format and status from the source post, and registers
 * the translation relationship in the database.
 *
 * @package NovaTools\Polyglot\Translation\PostTranslation
 */

namespace NovaTools\Polyglot\Translation\PostTranslation;

use NovaTools\Polyglot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

class PostTranslator {

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
	 * Create a translation of a post in a target language.
	 *
	 * Creates a new WordPress post as a draft, links it to the source post
	 * via a shared trid, and copies the post format from the source.
	 *
	 * @param int    $sourceId       Source post ID.
	 * @param string $elementType    Element type (e.g. "post_post", "post_page").
	 * @param string $targetLanguage Target language code.
	 * @param array  $args           Optional overrides: title, content, excerpt, status.
	 * @return int|false New post ID, or false on failure.
	 */
	public function translate( int $sourceId, string $elementType, string $targetLanguage, array $args = array() ): int|false {
		$source = get_post( $sourceId );

		if ( ! $source ) {
			return false;
		}

		// Resolve or create the trid group.
		$existing  = $this->repository->getByElement( $elementType, $sourceId );
		$trid      = $existing ? (int) $existing['trid'] : $this->repository->getNextTrid();
		$sourceLang = $existing ? $existing['language_code'] : polyglot_get_current_language();

		// Check if a translation already exists for this language.
		$existingTranslation = $this->repository->getTranslatedElementId( $sourceId, $elementType, $targetLanguage );

		if ( null !== $existingTranslation ) {
			return $existingTranslation;
		}

		// Build post data for the new translation.
		$postData = array(
			'post_title'   => $args['title'] ?? $source->post_title,
			'post_content' => $args['content'] ?? '',
			'post_excerpt' => $args['excerpt'] ?? '',
			'post_status'  => $args['status'] ?? $source->post_status,
			'post_type'    => $source->post_type,
			'post_author'  => get_current_user_id() ?: (int) $source->post_author,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
			'menu_order'     => $source->menu_order,
			'post_password'  => $source->post_password,
		);

		/**
		 * Filter post data before creating a translation.
		 *
		 * @param array    $postData       Associative array for wp_insert_post.
		 * @param \WP_Post $source         Source post object.
		 * @param string   $targetLanguage Target language code.
		 * @param array    $args           Original args passed to translate().
		 */
		$postData = apply_filters( 'polyglot_translation_post_data', $postData, $source, $targetLanguage, $args );

		$newId = wp_insert_post( $postData );

		if ( is_wp_error( $newId ) || 0 === $newId ) {
			return false;
		}

		// Copy post format from source.
		$this->copyPostFormat( $sourceId, $newId );

		// Save the translation link for the new post.
		$this->repository->save( array(
			'element_id'           => $newId,
			'element_type'         => $elementType,
			'trid'                 => $trid,
			'language_code'        => $targetLanguage,
			'source_language_code' => $sourceLang,
			'status'               => 'in_progress',
		) );

		// Ensure the source element has a translation row if new to the system.
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
		 * Fires after a post translation has been created.
		 *
		 * @param int    $newId          New translated post ID.
		 * @param int    $sourceId       Source post ID.
		 * @param string $targetLanguage Target language code.
		 * @param int    $trid           Translation group ID.
		 */
		do_action( 'polyglot_post_translation_created', $newId, $sourceId, $targetLanguage, $trid );

		return $newId;
	}

	/**
	 * Get the post element type string for a given post type.
	 *
	 * @param string $postType WordPress post type.
	 * @return string Element type (e.g. "post_post", "post_page").
	 */
	public static function getElementType( string $postType ): string {
		return 'post_' . $postType;
	}

	/**
	 * Get the language code assigned to a post.
	 *
	 * @param int $postId Post ID.
	 * @return string|null Language code or null.
	 */
	public function getPostLanguage( int $postId ): ?string {
		$post  = get_post( $postId );

		if ( ! $post ) {
			return null;
		}

		$row = $this->repository->getByElement( self::getElementType( $post->post_type ), $postId );

		return $row['language_code'] ?? null;
	}

	/**
	 * Get the ID of a post translated to a specific language.
	 *
	 * @param int    $postId         Source post ID.
	 * @param string $targetLanguage Target language code.
	 * @return int|null Translated post ID, or null.
	 */
	public function getTranslatedPostId( int $postId, string $targetLanguage ): ?int {
		$post = get_post( $postId );

		if ( ! $post ) {
			return null;
		}

		return $this->repository->getTranslatedElementId(
			$postId,
			self::getElementType( $post->post_type ),
			$targetLanguage
		);
	}

	/**
	 * Copy the post format from the source post to the translation.
	 *
	 * @param int $sourceId Source post ID.
	 * @param int $targetId Target (translated) post ID.
	 */
	private function copyPostFormat( int $sourceId, int $targetId ): void {
		$format = get_post_format( $sourceId );

		if ( $format && ! is_wp_error( $format ) ) {
			set_post_format( $targetId, $format );
		}
	}
}
