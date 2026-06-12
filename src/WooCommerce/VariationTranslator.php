<?php
/**
 * Variation translator for NovaTools Polyglot.
 *
 * Translates WooCommerce product variations while preserving the parent-child
 * relationship: a translated variation is created under the translated parent
 * product, linked to the source variation via a shared trid with element_type
 * `post_product_variation`, and its attribute terms are mapped to the target
 * language.
 *
 * @package NovaTools\Polyglot\WooCommerce
 */

namespace NovaTools\Polyglot\WooCommerce;

use NovaTools\Polyglot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

class VariationTranslator {

	/**
	 * Translation repository.
	 *
	 * @var TranslationRepository
	 */
	private TranslationRepository $repository;

	/**
	 * Whether a variation translation is currently being inserted.
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
	 * Register WordPress hooks.
	 *
	 * Seeds variation language on variation save. Placeholder for future
	 * automatic variation-translation hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_save_product_variation', array( $this, 'onSaveVariation' ), 10, 2 );
	}

	/**
	 * Create a translation of a variation in a target language.
	 *
	 * Creates a new `product_variation` post under the translated parent
	 * product, copies the variation meta, translates the attribute terms, and
	 * links the new variation to the source via a shared trid.
	 *
	 * @param int    $sourceVariationId Source variation post ID.
	 * @param string $targetLanguage    Target language code.
	 * @param int    $targetParentId    Translated parent product ID.
	 * @param array  $args              Optional overrides: title, slug, status.
	 * @return int|false New variation ID, or false on failure.
	 */
	public function translate( int $sourceVariationId, string $targetLanguage, int $targetParentId, array $args = array() ): int|false {
		$source = get_post( $sourceVariationId );

		if ( ! $source || 'product_variation' !== $source->post_type ) {
			return false;
		}

		// Resolve or create the trid group.
		$existing   = $this->repository->getByElement( self::getElementType(), $sourceVariationId );
		$trid       = $existing ? (int) $existing['trid'] : $this->repository->getNextTrid();
		$sourceLang = $existing ? $existing['language_code'] : polyglot_get_current_language();

		// Return the existing translation if one already exists.
		$existingTranslation = $this->repository->getTranslatedElementId(
			$sourceVariationId,
			self::getElementType(),
			$targetLanguage
		);

		if ( null !== $existingTranslation ) {
			return $existingTranslation;
		}

		$postData = array(
			'post_title'  => $args['title'] ?? $source->post_title,
			'post_name'   => $args['slug'] ?? '',
			'post_status' => $args['status'] ?? $source->post_status,
			'post_type'   => 'product_variation',
			'post_parent' => $targetParentId,
			'menu_order'  => $source->menu_order,
		);

		/**
		 * Filter variation post data before creating a translation.
		 *
		 * @param array    $postData           Associative array for wp_insert_post.
		 * @param \WP_Post $source             Source variation object.
		 * @param string   $targetLanguage     Target language code.
		 * @param int      $targetParentId     Translated parent product ID.
		 */
		$postData = apply_filters( 'polyglot_woocommerce_variation_data', $postData, $source, $targetLanguage, $targetParentId );

		$this->isTranslating = true;
		$newId = wp_insert_post( $postData );
		$this->isTranslating = false;

		if ( is_wp_error( $newId ) || 0 === $newId ) {
			return false;
		}

		// Copy variation meta and translate attribute terms.
		$this->copyVariationMeta( $sourceVariationId, $newId, $targetLanguage );

		// Save the translation link for the new variation.
		$this->repository->save( array(
			'element_id'           => $newId,
			'element_type'         => self::getElementType(),
			'trid'                 => $trid,
			'language_code'        => $targetLanguage,
			'source_language_code' => $sourceLang,
			'status'               => 'in_progress',
		) );

		// Ensure the source variation has a translation row.
		if ( ! $existing ) {
			$this->repository->save( array(
				'element_id'           => $sourceVariationId,
				'element_type'         => self::getElementType(),
				'trid'                 => $trid,
				'language_code'        => $sourceLang,
				'source_language_code' => '',
				'status'               => 'completed',
			) );
		}

		/**
		 * Fires after a variation translation has been created.
		 *
		 * @param int    $newId              New translated variation ID.
		 * @param int    $sourceVariationId  Source variation ID.
		 * @param string $targetLanguage     Target language code.
		 * @param int    $targetParentId     Translated parent product ID.
		 * @param int    $trid               Translation group ID.
		 */
		do_action( 'polyglot_woocommerce_variation_translated', $newId, $sourceVariationId, $targetLanguage, $targetParentId, $trid );

		return $newId;
	}

	/**
	 * Translate all variations of a variable product.
	 *
	 * Iterates over the source product's variations, creating a translation of
	 * each under the translated parent. Returns the list of created variation
	 * IDs.
	 *
	 * @param int    $sourceParentId  Source variable product ID.
	 * @param string $targetLanguage  Target language code.
	 * @param int    $targetParentId  Translated parent product ID.
	 * @return int[] Created translated variation IDs.
	 */
	public function translateAllVariations( int $sourceParentId, string $targetLanguage, int $targetParentId ): array {
		$sourceProduct = wc_get_product( $sourceParentId );

		if ( ! $sourceProduct || ! $sourceProduct->is_type( 'variable' ) ) {
			return array();
		}

		$variationIds = array();
		$children     = $sourceProduct->get_children();

		foreach ( $children as $sourceVariationId ) {
			$newId = $this->translate( (int) $sourceVariationId, $targetLanguage, $targetParentId );

			if ( $newId ) {
				$variationIds[] = (int) $newId;
			}
		}

		return $variationIds;
	}

	/**
	 * Seed a variation's language on save.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $i            Index of the variation being saved.
	 * @return void
	 */
	public function onSaveVariation( int $variation_id, int $i ): void {
		if ( $this->isTranslating ) {
			return;
		}

		$existing = $this->repository->getByElement( self::getElementType(), $variation_id );

		if ( $existing ) {
			return;
		}

		$parentId = wp_get_post_parent_id( $variation_id );
		$lang     = '';

		if ( $parentId ) {
			$parentRow = $this->repository->getByElement( ProductTranslator::getElementType(), $parentId );
			$lang      = $parentRow['language_code'] ?? '';
		}

		$this->repository->save( array(
			'element_id'           => $variation_id,
			'element_type'         => self::getElementType(),
			'trid'                 => $this->repository->getNextTrid(),
			'language_code'        => $lang ?: polyglot_get_current_language(),
			'source_language_code' => '',
			'status'               => 'completed',
		) );
	}

	/**
	 * Get the variation element type string.
	 *
	 * @return string Element type "post_product_variation".
	 */
	public static function getElementType(): string {
		return 'post_product_variation';
	}

	/**
	 * Get the ID of a variation translated to a specific language.
	 *
	 * @param int    $variationId    Source variation ID.
	 * @param string $targetLanguage Target language code.
	 * @return int|null Translated variation ID, or null.
	 */
	public function getTranslatedVariationId( int $variationId, string $targetLanguage ): ?int {
		return $this->repository->getTranslatedElementId(
			$variationId,
			self::getElementType(),
			$targetLanguage
		);
	}

	/**
	 * Copy variation meta from source to a translation, translating attributes.
	 *
	 * @param int    $sourceId       Source variation ID.
	 * @param int    $targetId       Target variation ID.
	 * @param string $targetLanguage Target language code.
	 */
	private function copyVariationMeta( int $sourceId, int $targetId, string $targetLanguage ): void {
		$keys = array(
			'_regular_price',
			'_sale_price',
			'_price',
			'_sale_price_dates_from',
			'_sale_price_dates_to',
			'_stock',
			'_manage_stock',
			'_low_stock_amount',
			'_backorders',
			'_stock_status',
			'_weight',
			'_length',
			'_width',
			'_height',
			'_virtual',
			'_downloadable',
			'_download_limit',
			'_download_expiry',
			'_thumbnail_id',
		);

		/**
		 * Filter the variation meta keys copied to a translation.
		 *
		 * @param string[] $keys           Meta keys to copy.
		 * @param int      $sourceId       Source variation ID.
		 * @param int      $targetId       Target variation ID.
		 */
		$keys = apply_filters( 'polyglot_woocommerce_copied_variation_meta', $keys, $sourceId, $targetId );

		foreach ( $keys as $key ) {
			$value = get_post_meta( $sourceId, $key, true );

			if ( '' !== $value ) {
				update_post_meta( $targetId, $key, $value );
			}
		}

		// Translate the variation's attribute terms (e.g. pa_color => slug).
		$attributes = get_post_meta( $sourceId, '_attributes', true );

		if ( is_array( $attributes ) && ! empty( $attributes ) ) {
			update_post_meta( $targetId, '_attributes', $this->translateAttributes( $attributes, $targetLanguage ) );
		}
	}

	/**
	 * Translate a variation's attribute map to the target language.
	 *
	 * Each attribute key is a taxonomy (e.g. "pa_color") and the value is a
	 * term slug. The referenced term is resolved, translated to the target
	 * language, and its slug substituted.
	 *
	 * @param array  $attributes     Associative array of taxonomy => term slug.
	 * @param string $targetLanguage Target language code.
	 * @return array Translated attribute map.
	 */
	private function translateAttributes( array $attributes, string $targetLanguage ): array {
		$translated = array();

		foreach ( $attributes as $taxonomy => $slug ) {
			if ( 0 !== strpos( $taxonomy, 'pa_' ) || empty( $slug ) ) {
				// Custom (non-taxonomy) attributes — copy as-is.
				$translated[ $taxonomy ] = $slug;
				continue;
			}

			$sourceTerm = get_term_by( 'slug', $slug, $taxonomy );

			if ( ! $sourceTerm ) {
				$translated[ $taxonomy ] = $slug;
				continue;
			}

			$translatedTermId = $this->repository->getTranslatedElementId(
				(int) $sourceTerm->term_id,
				'tax_' . $taxonomy,
				$targetLanguage
			);

			if ( $translatedTermId ) {
				$translatedTerm = get_term( (int) $translatedTermId, $taxonomy );

				if ( $translatedTerm && ! is_wp_error( $translatedTerm ) ) {
					$translated[ $taxonomy ] = $translatedTerm->slug;
					continue;
				}
			}

			// No translation yet — fall back to the source slug.
			$translated[ $taxonomy ] = $slug;
		}

		return $translated;
	}
}
