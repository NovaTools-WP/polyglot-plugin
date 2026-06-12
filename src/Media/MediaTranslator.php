<?php
/**
 * Media translator for NovaTools Polyglot.
 *
 * Orchestrates media attachment translation: duplication of attachment posts
 * to other languages, translation lookups, and per-language featured image
 * management. Duplicated attachments reference the same physical file.
 *
 * @package NovaTools\Polyglot\Media
 */

namespace NovaTools\Polyglot\Media;

use NovaTools\Polyglot\Database\Schema;
use NovaTools\Polyglot\Support\Cache;
use NovaTools\Polyglot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

class MediaTranslator {

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
	 * @param MediaRepository        $repository             The media repository.
	 * @param TranslationRepository  $translationRepository  The translation repository.
	 * @param Cache                  $cache                  The polyglot cache wrapper.
	 */
	public function __construct(
		MediaRepository $repository,
		TranslationRepository $translationRepository,
		Cache $cache
	) {
		$this->repository            = $repository;
		$this->translationRepository = $translationRepository;
		$this->cache                 = $cache;
	}

	/**
	 * Duplicate a media attachment to another language.
	 *
	 * Creates a new attachment post referencing the same physical file as the
	 * source, copies all post meta (alt text, attachment metadata, etc.),
	 * and links the new attachment to the source via a shared trid.
	 *
	 * If a translation already exists for the target language, returns the
	 * existing attachment ID without creating a duplicate.
	 *
	 * @param int    $sourceAttachmentId Source attachment post ID.
	 * @param string $targetLanguage     Target language code (e.g. "fr").
	 * @return int The new (or existing) translated attachment ID.
	 */
	public function duplicate( int $sourceAttachmentId, string $targetLanguage ): int {
		// Check if a translation already exists.
		$existing = $this->repository->getTranslatedAttachment( $sourceAttachmentId, $targetLanguage );

		if ( $existing ) {
			return $existing;
		}

		// Get the source attachment post.
		$sourcePost = get_post( $sourceAttachmentId );

		if ( ! $sourcePost || 'attachment' !== $sourcePost->post_type ) {
			return 0;
		}

		// Determine source language and trid.
		$sourceLanguage = $this->repository->getAttachmentLanguage( $sourceAttachmentId );
		$trid           = $this->repository->getTrid( $sourceAttachmentId );

		if ( ! $trid ) {
			// Source has no trid yet — create a new translation group.
			$trid = $this->repository->getNextTrid();

			// Assign the source to the new group.
			$this->repository->save(
				$sourceAttachmentId,
				$sourceLanguage ?? '',
				$trid,
				'',
				'completed'
			);
		}

		// Create the new attachment post (references same file).
		$newPostArr = array(
			'post_type'       => 'attachment',
			'post_mime_type'  => $sourcePost->post_mime_type,
			'post_title'      => $sourcePost->post_title,
			'post_excerpt'    => $sourcePost->post_excerpt,
			'post_content'    => $sourcePost->post_content,
			'post_status'     => 'inherit',
			'post_parent'     => 0,
			'guid'            => $sourcePost->guid,
			'post_date'       => $sourcePost->post_date,
			'post_date_gmt'   => $sourcePost->post_date_gmt,
			'post_modified'   => current_time( 'mysql' ),
			'post_modified_gmt' => current_time( 'mysql', true ),
		);

		/** @var int $newAttachmentId */
		$newAttachmentId = wp_insert_attachment( $newPostArr, false, 0, true );

		if ( is_wp_error( $newAttachmentId ) ) {
			return 0;
		}

		// Copy all post meta from source to new attachment.
		$this->copyAttachmentMeta( $sourceAttachmentId, $newAttachmentId );

		// Save the translation record.
		$this->repository->save(
			$newAttachmentId,
			$targetLanguage,
			$trid,
			$sourceLanguage ?? '',
			'completed'
		);

		/**
		 * Fires after a media attachment has been duplicated for translation.
		 *
		 * @param int    $newAttachmentId    The newly created attachment ID.
		 * @param int    $sourceAttachmentId The source attachment ID.
		 * @param string $targetLanguage     The target language code.
		 * @param int    $trid               The translation group ID.
		 */
		do_action( 'polyglot_media_duplicated', $newAttachmentId, $sourceAttachmentId, $targetLanguage, $trid );

		return $newAttachmentId;
	}

	/**
	 * Get the translated attachment ID for a given source and target language.
	 *
	 * @param int    $attachmentId  Source attachment post ID.
	 * @param string $languageCode  Target language code.
	 * @return int|null Translated attachment ID, or null if none exists.
	 */
	public function getTranslation( int $attachmentId, string $languageCode ): ?int {
		return $this->repository->getTranslatedAttachment( $attachmentId, $languageCode );
	}

	/**
	 * Get the featured image for a specific language translation of a post.
	 *
	 * Since translated posts are separate WP posts, their `_thumbnail_id`
	 * meta already references the correct language-specific attachment.
	 * This method looks up the translated post and returns its thumbnail.
	 *
	 * @param int    $postId  Source post ID.
	 * @param string $language Target language code.
	 * @return int|null Featured image attachment ID for the translated post, or null.
	 */
	public function getPerLanguageFeaturedImage( int $postId, string $language ): ?int {
		$translatedPostId = $this->translationRepository->getTranslatedElementId(
			$postId,
			'post',
			$language
		);

		if ( ! $translatedPostId ) {
			return null;
		}

		$thumbnailId = get_post_meta( $translatedPostId, '_thumbnail_id', true );

		return $thumbnailId ? (int) $thumbnailId : null;
	}

	/**
	 * Register WordPress hooks for automatic media language handling.
	 *
	 * Hooks into `add_attachment` to automatically assign the current
	 * admin language to newly uploaded media attachments.
	 *
	 * @return void
	 */
	public function registerHooks(): void {
		add_action( 'add_attachment', array( $this, 'onAttachmentUpload' ) );

		// Media library language filter.
		add_action( 'restrict_manage_posts', array( $this, 'renderLanguageFilter' ) );
		add_action( 'pre_get_posts', array( $this, 'filterMediaByLanguage' ) );
	}

	/**
	 * Automatically assign language to a newly uploaded attachment.
	 *
	 * Fired on the `add_attachment` hook. Determines the current admin
	 * language (or falls back to the site default) and inserts a
	 * translation record for the new attachment.
	 *
	 * @param int $attachmentId The newly uploaded attachment ID.
	 * @return void
	 */
	public function onAttachmentUpload( int $attachmentId ): void {
		// Avoid re-assigning if this attachment is already tracked
		// (e.g. created by duplicate()).
		$existing = $this->repository->getAttachmentLanguage( $attachmentId );

		if ( null !== $existing ) {
			return;
		}

		// Determine the current language.
		$languageCode = $this->getCurrentAdminLanguage();

		// Create a new translation group (trid) for this attachment.
		$trid = $this->repository->getNextTrid();

		$this->repository->save(
			$attachmentId,
			$languageCode,
			$trid,
			'',
			'completed'
		);

		/**
		 * Fires after language has been automatically assigned to an uploaded attachment.
		 *
		 * @param int    $attachmentId The attachment ID.
		 * @param string $languageCode The assigned language code.
		 */
		do_action( 'polyglot_media_upload_language_assigned', $attachmentId, $languageCode );
	}

	/**
	 * Get the current admin language code.
	 *
	 * Checks the admin language cookie, the user meta, and falls back to
	 * the site default language from the polyglot settings.
	 *
	 * @return string Language code.
	 */
	private function getCurrentAdminLanguage(): string {
		// Try admin language from user meta / cookie.
		if ( is_admin() ) {
			$userLang = get_user_meta( get_current_user_id(), 'polyglot_admin_language', true );

			if ( $userLang ) {
				return $userLang;
			}
		}

		// Try the polyglot default language.
		$defaultLang = apply_filters( 'polyglot_default_language', '' );

		if ( $defaultLang ) {
			return $defaultLang;
		}

		// Final fallback: derive from WordPress locale.
		$locale = get_locale();
		$code   = strtolower( substr( $locale, 0, 2 ) );

		return $code ?: 'en';
	}

	/**
	 * Render the language filter dropdown on the media library list view.
	 *
	 * Hooked into `restrict_manage_posts`. Only renders on the `upload.php`
	 * screen (media library) when the post type is `attachment`.
	 *
	 * @param string $post_type The current post type being listed.
	 * @return void
	 */
	public function renderLanguageFilter( string $post_type ): void {
		if ( 'attachment' !== $post_type ) {
			return;
		}

		// Get active languages via the polyglot filter.
		/** @var string[] $languageCodes */
		$languageCodes = apply_filters( 'polyglot_active_language_codes', array() );

		if ( empty( $languageCodes ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected = isset( $_GET['polyglot_media_lang'] )
			? sanitize_key( $_GET['polyglot_media_lang'] )
			: '';

		echo '<select name="polyglot_media_lang" id="polyglot-media-lang-filter">';
		printf(
			'<option value="">%s</option>',
			esc_html__( 'All languages', 'novatools-polyglot' )
		);

		foreach ( $languageCodes as $code ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $code ),
				selected( $selected, $code, false ),
				esc_html( polyglot_get_language_name( $code ) ?: strtoupper( $code ) )
			);
		}

		echo '</select>';
	}

	/**
	 * Filter media library query by selected language.
	 *
	 * Hooked into `pre_get_posts`. When the `polyglot_media_lang` query
	 * parameter is present, restricts the attachment query to IDs tracked
	 * in `polyglot_translations` for that language.
	 *
	 * @param \WP_Query $query The WordPress query object.
	 * @return void
	 */
	public function filterMediaByLanguage( \WP_Query $query ): void {
		// Only target the main query on the admin media library screen.
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['polyglot_media_lang'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$languageCode = sanitize_key( $_GET['polyglot_media_lang'] );

		if ( empty( $languageCode ) ) {
			return;
		}

		$attachmentIds = $this->repository->getByLanguage( $languageCode );

		if ( empty( $attachmentIds ) ) {
			// No media in this language — force an empty result set.
			$query->set( 'post__in', array( 0 ) );
			return;
		}

		$query->set( 'post__in', $attachmentIds );
	}

	/**
	 * Copy all attachment metadata from one attachment to another.
	 *
	 * Copies `_wp_attachment_metadata`, `_wp_attached_file`, and `_wp_attachment_image_alt`
	 * as well as any custom meta keys.
	 *
	 * @param int $sourceId Source attachment ID.
	 * @param int $targetId Target attachment ID.
	 */
	private function copyAttachmentMeta( int $sourceId, int $targetId ): void {
		// Copy the attached file path — both attachments reference the same file.
		$attachedFile = get_post_meta( $sourceId, '_wp_attached_file', true );

		if ( $attachedFile ) {
			update_post_meta( $targetId, '_wp_attached_file', $attachedFile );
		}

		// Copy attachment metadata (image sizes, etc.).
		$attachmentMeta = get_post_meta( $sourceId, '_wp_attachment_metadata', true );

		if ( $attachmentMeta ) {
			update_post_meta( $targetId, '_wp_attachment_metadata', $attachmentMeta );
		}

		// Copy alt text.
		$altText = get_post_meta( $sourceId, '_wp_attachment_image_alt', true );

		if ( $altText ) {
			update_post_meta( $targetId, '_wp_attachment_image_alt', $altText );
		}

		/**
		 * Fires after attachment meta has been copied during media duplication.
		 *
		 * Allows plugins to copy additional custom meta or transform meta values.
		 *
		 * @param int $targetId Target (new) attachment ID.
		 * @param int $sourceId Source attachment ID.
		 */
		do_action( 'polyglot_media_meta_copied', $targetId, $sourceId );
	}
}
