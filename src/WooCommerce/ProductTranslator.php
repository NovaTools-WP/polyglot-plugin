<?php
/**
 * Product translator for NovaTools Polyglot.
 *
 * Handles translation of WooCommerce products — all product types (simple,
 * variable, grouped, external). Creates a translated `product` post linked to
 * the source via a shared trid with element_type `post_product`, copies the
 * non-translatable product meta, and applies translated field overrides
 * (title, description, short description, slug, purchase note).
 *
 * @package NovaTools\Polyglot\WooCommerce
 */

namespace NovaTools\Polyglot\WooCommerce;

use NovaTools\Polyglot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

class ProductTranslator {

	/**
	 * Translation repository.
	 *
	 * @var TranslationRepository
	 */
	private TranslationRepository $repository;

	/**
	 * Whether a translation is currently being inserted.
	 *
	 * Suppresses the `save_post_product` seeding hook so that a freshly
	 * created translation is not re-seeded with the wrong language.
	 *
	 * @var bool
	 */
	private bool $isTranslating = false;

	/**
	 * Constructor.
	 *
	 * @param TranslationRepository $repository Translation repository.
	 */
	public function __construct( TranslationRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register WordPress hooks for product language seeding.
	 *
	 * Seeds a source product with its language on first save so it becomes
	 * part of a translation group. Skipped while a translation is being
	 * inserted (see $isTranslating).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'save_post_product', array( $this, 'onSaveProduct' ), 5, 3 );
	}

	/**
	 * Create a translation of a product in a target language.
	 *
	 * Creates a new `product` post as a draft, applies the translated field
	 * overrides (title, description, short description, slug, purchase note),
	 * copies the non-translatable product meta, and links the new post to the
	 * source via a shared trid.
	 *
	 * @param int    $sourceId       Source product post ID.
	 * @param string $targetLanguage Target language code.
	 * @param array  $args           Optional translated overrides:
	 *                                 title, content, excerpt, slug, status,
	 *                                 purchase_note.
	 * @return int|false New product ID, or false on failure.
	 */
	public function translate( int $sourceId, string $targetLanguage, array $args = array() ): int|false {
		$source = get_post( $sourceId );

		if ( ! $source || 'product' !== $source->post_type ) {
			return false;
		}

		// Resolve or create the trid group.
		$existing   = $this->repository->getByElement( self::getElementType(), $sourceId );
		$trid       = $existing ? (int) $existing['trid'] : $this->repository->getNextTrid();
		$sourceLang = $existing ? $existing['language_code'] : polyglot_get_current_language();

		// Return the existing translation if one already exists.
		$existingTranslation = $this->repository->getTranslatedElementId(
			$sourceId,
			self::getElementType(),
			$targetLanguage
		);

		if ( null !== $existingTranslation ) {
			return $existingTranslation;
		}

		$postData = array(
			'post_title'   => $args['title'] ?? $source->post_title,
			'post_content' => $args['content'] ?? $source->post_content,
			'post_excerpt' => $args['excerpt'] ?? $source->post_excerpt,
			'post_name'    => $args['slug'] ?? '',
			'post_status'  => $args['status'] ?? 'draft',
			'post_type'    => 'product',
			'post_author'  => get_current_user_id() ?: (int) $source->post_author,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
			'menu_order'     => $source->menu_order,
		);

		/**
		 * Filter product post data before creating a translation.
		 *
		 * @param array    $postData       Associative array for wp_insert_post.
		 * @param \WP_Post $source         Source product object.
		 * @param string   $targetLanguage Target language code.
		 * @param array    $args           Original args passed to translate().
		 */
		$postData = apply_filters( 'polyglot_woocommerce_product_data', $postData, $source, $targetLanguage, $args );

		// Suppress the seeding hook while we insert the translation.
		$this->isTranslating = true;
		$newId = wp_insert_post( $postData );
		$this->isTranslating = false;

		if ( is_wp_error( $newId ) || 0 === $newId ) {
			return false;
		}

		// Apply translated purchase note and copy non-translatable meta.
		$this->copyProductMeta( $sourceId, $newId );

		if ( array_key_exists( 'purchase_note', $args ) ) {
			update_post_meta( $newId, '_purchase_note', $args['purchase_note'] );
		}

		// Save the translation link for the new product.
		$this->repository->save( array(
			'element_id'           => $newId,
			'element_type'         => self::getElementType(),
			'trid'                 => $trid,
			'language_code'        => $targetLanguage,
			'source_language_code' => $sourceLang,
			'status'               => 'in_progress',
		) );

		// Ensure the source product has a translation row.
		if ( ! $existing ) {
			$this->repository->save( array(
				'element_id'           => $sourceId,
				'element_type'         => self::getElementType(),
				'trid'                 => $trid,
				'language_code'        => $sourceLang,
				'source_language_code' => '',
				'status'               => 'completed',
			) );
		}

		/**
		 * Fires after a product translation has been created.
		 *
		 * @param int    $newId          New translated product ID.
		 * @param int    $sourceId       Source product ID.
		 * @param string $targetLanguage Target language code.
		 * @param int    $trid           Translation group ID.
		 */
		do_action( 'polyglot_woocommerce_product_translated', $newId, $sourceId, $targetLanguage, $trid );

		return $newId;
	}

	/**
	 * Seed a product's language on first save.
	 *
	 * Fired on `save_post_product`. If the product has no translation row yet,
	 * creates one with the current language so the product joins a translation
	 * group. Ignored while a translation is being inserted.
	 *
	 * @param int      $postId Product ID.
	 * @param \WP_Post $post   Product object.
	 * @param bool     $update Whether this is an update.
	 * @return void
	 */
	public function onSaveProduct( int $postId, \WP_Post $post, bool $update ): void {
		if ( $this->isTranslating ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $postId ) ) {
			return;
		}

		$existing = $this->repository->getByElement( self::getElementType(), $postId );

		if ( $existing ) {
			return;
		}

		$this->repository->save( array(
			'element_id'           => $postId,
			'element_type'         => self::getElementType(),
			'trid'                 => $this->repository->getNextTrid(),
			'language_code'        => polyglot_get_current_language(),
			'source_language_code' => '',
			'status'               => 'completed',
		) );
	}

	/**
	 * Get the product element type string.
	 *
	 * @return string Element type "post_product".
	 */
	public static function getElementType(): string {
		return 'post_product';
	}

	/**
	 * Get the language code assigned to a product.
	 *
	 * @param int $productId Product ID.
	 * @return string|null Language code or null.
	 */
	public function getProductLanguage( int $productId ): ?string {
		$row = $this->repository->getByElement( self::getElementType(), $productId );

		return $row['language_code'] ?? null;
	}

	/**
	 * Get the ID of a product translated to a specific language.
	 *
	 * @param int    $productId      Source product ID.
	 * @param string $targetLanguage Target language code.
	 * @return int|null Translated product ID, or null.
	 */
	public function getTranslatedProductId( int $productId, string $targetLanguage ): ?int {
		return $this->repository->getTranslatedElementId(
			$productId,
			self::getElementType(),
			$targetLanguage
		);
	}

	/**
	 * Copy non-translatable product meta from source to a translation.
	 *
	 * Copies pricing, inventory, shipping dimensions, tax, and gallery meta so
	 * the translation mirrors the source's commerce settings. Display prices
	 * are then handled by the multi-currency system for the active currency.
	 *
	 * @param int $sourceId Source product ID.
	 * @param int $targetId Target (translated) product ID.
	 */
	private function copyProductMeta( int $sourceId, int $targetId ): void {
		$keys = array(
			'_thumbnail_id',
			'_product_image_gallery',
			'_product_attributes',
		);

		/**
		 * Filter the product meta keys copied to a translation.
		 *
		 * @param string[] $keys     Meta keys to copy.
		 * @param int      $sourceId Source product ID.
		 * @param int      $targetId Target product ID.
		 */
		$keys = apply_filters( 'polyglot_woocommerce_copied_product_meta', $keys, $sourceId, $targetId );

		$allMeta = get_post_meta( $sourceId );

		foreach ( $keys as $key ) {
			if ( isset( $allMeta[ $key ] ) ) {
				$value = $allMeta[ $key ];
				$single = is_array( $value ) && count( $value ) === 1 ? $value[0] : $value;

				if ( '' !== $single ) {
					update_post_meta( $targetId, $key, $single );
				}
			}
		}
	}
}
