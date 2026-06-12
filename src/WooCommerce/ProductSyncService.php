<?php
/**
 * Product synchronisation service for NovaTools Polyglot.
 *
 * Keeps product translations in step with their source. When a source product
 * changes, its translations are flagged as "needs_update" (checksum-based,
 * mirroring PostSyncService). Also provides explicit field-sync to push shared
 * commerce data (pricing, inventory, images) from a source product to an
 * existing translation.
 *
 * @package NovaTools\Polyglot\WooCommerce
 */

namespace NovaTools\Polyglot\WooCommerce;

use NovaTools\Polyglot\Database\Schema;
use NovaTools\Polyglot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

class ProductSyncService {

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
	 * Register WordPress hooks for automatic product sync.
	 *
	 * Uses WooCommerce's product-specific save hooks rather than the generic
	 * `save_post` so checksums are computed after WooCommerce has persisted
	 * pricing and inventory meta.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_new_product', array( $this, 'onNewProduct' ), 10, 2 );
		add_action( 'woocommerce_update_product', array( $this, 'onUpdateProduct' ), 10, 2 );
	}

	/**
	 * Seed a checksum for newly created products.
	 *
	 * @param int        $productId Product ID.
	 * @param \WC_Product $product   Product object.
	 * @return void
	 */
	public function onNewProduct( int $productId, $product ): void {
		$this->updateChecksum( $productId );
	}

	/**
	 * Detect changes when a product is updated and flag translations.
	 *
	 * @param int        $productId Product ID.
	 * @param \WC_Product $product   Product object.
	 * @return void
	 */
	public function onUpdateProduct( int $productId, $product ): void {
		$this->detectChanges( $productId );
	}

	/**
	 * Detect changes in a source product and flag translations as needing update.
	 *
	 * @param int $productId Product ID.
	 * @return bool True if changes were detected and translations were flagged.
	 */
	public function detectChanges( int $productId ): bool {
		$row = $this->repository->getByElement( ProductTranslator::getElementType(), $productId );

		if ( ! $row ) {
			return false;
		}

		// Only source elements (those without a source language) drive sync.
		if ( ! empty( $row['source_language_code'] ) ) {
			return false;
		}

		$newChecksum = $this->computeChecksum( $productId );
		$oldChecksum = $row['checksum'] ?? '';

		$this->updateChecksum( $productId );

		if ( $newChecksum === $oldChecksum ) {
			return false;
		}

		return $this->flagTranslationsNeedsUpdate( (int) $row['trid'] );
	}

	/**
	 * Push shared product fields from a source to an existing translation.
	 *
	 * Copies the commerce data that should stay identical across languages
	 * (pricing, inventory, shipping dimensions, gallery). Translatable fields
	 * (title, description) are left untouched.
	 *
	 * @param int    $sourceId       Source product ID.
	 * @param string $targetLanguage Target language code.
	 * @param array  $fields         Optional field allowlist. Defaults to all
	 *                                 syncable meta keys.
	 * @return bool True if the translation was found and synced.
	 */
	public function syncProduct( int $sourceId, string $targetLanguage, array $fields = array() ): bool {
		$targetId = $this->repository->getTranslatedElementId(
			$sourceId,
			ProductTranslator::getElementType(),
			$targetLanguage
		);

		if ( ! $targetId ) {
			return false;
		}

		$syncable = array(
			'_regular_price',
			'_sale_price',
			'_price',
			'_sale_price_dates_from',
			'_sale_price_dates_to',
			'_tax_status',
			'_tax_class',
			'_manage_stock',
			'_stock',
			'_low_stock_amount',
			'_backorders',
			'_stock_status',
			'_weight',
			'_length',
			'_width',
			'_height',
			'_product_image_gallery',
			'_thumbnail_id',
			'crosssell_ids',
			'upsell_ids',
		);

		/**
		 * Filter the meta keys synced from source to a product translation.
		 *
		 * @param string[] $syncable      Meta keys to sync.
		 * @param int      $sourceId      Source product ID.
		 * @param int      $targetId      Target product ID.
		 */
		$syncable = apply_filters( 'polyglot_woocommerce_synced_product_meta', $syncable, $sourceId, $targetId );

		if ( ! empty( $fields ) ) {
			$syncable = array_intersect( $syncable, $fields );
		}

		foreach ( $syncable as $key ) {
			$value = get_post_meta( $sourceId, $key, true );
			update_post_meta( $targetId, $key, $value );
		}

		// Keep the translation's checksum in sync with the source so it is not
		// immediately re-flagged as needs_update.
		$this->updateChecksum( $targetId );

		/**
		 * Fires after a product translation has been synced from its source.
		 *
		 * @param int    $targetId      Target product ID.
		 * @param int    $sourceId      Source product ID.
		 * @param string $targetLanguage Target language code.
		 */
		do_action( 'polyglot_woocommerce_product_synced', $targetId, $sourceId, $targetLanguage );

		return true;
	}

	/**
	 * Compute an MD5 checksum for a product's sync-relevant content.
	 *
	 * Includes title, content, excerpt, regular price, sale price, and SKU so
	 * that changes to any of these trigger a needs_update flag.
	 *
	 * @param int $productId Product ID.
	 * @return string 32-character hex MD5 hash.
	 */
	public function computeChecksum( int $productId ): string {
		$product = wc_get_product( $productId );

		if ( ! $product ) {
			return '';
		}

		$content = wp_json_encode( array(
			'title'        => $product->get_name(),
			'content'      => $product->get_description(),
			'excerpt'      => $product->get_short_description(),
			'regular_price'=> $product->get_regular_price(),
			'sale_price'   => $product->get_sale_price(),
			'sku'          => $product->get_sku(),
		) );

		return md5( $content );
	}

	/**
	 * Update the stored checksum for a product's translation row.
	 *
	 * @param int $productId Product ID.
	 * @return bool
	 */
	public function updateChecksum( int $productId ): bool {
		$row = $this->repository->getByElement( ProductTranslator::getElementType(), $productId );

		if ( ! $row ) {
			return false;
		}

		$checksum = $this->computeChecksum( $productId );

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->update(
			$table,
			array( 'checksum' => $checksum ),
			array(
				'element_type' => ProductTranslator::getElementType(),
				'element_id'   => $productId,
			)
		);

		$this->repository->invalidateCache( (int) $row['trid'], ProductTranslator::getElementType(), $productId );

		return (bool) $updated;
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

	/**
	 * Batch-recalculate checksums for all products.
	 *
	 * Useful after initial import to seed checksums.
	 *
	 * @return int Number of products updated.
	 */
	public function recalculateChecksums(): int {
		$count = 0;

		$products = get_posts( array(
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		) );

		foreach ( $products as $productId ) {
			if ( $this->updateChecksum( (int) $productId ) ) {
				++$count;
			}
		}

		return $count;
	}
}
