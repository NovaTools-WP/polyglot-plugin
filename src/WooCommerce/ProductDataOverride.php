<?php

namespace NovaTools\Polyglot\WooCommerce;

use NovaTools\Polyglot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

class ProductDataOverride {

	private TranslationRepository $repository;

	private bool $resolving = false;

	private ?\WC_Product $sourceProduct = null;

	private array $resolvedSourceIds = array();

	private array $excludedMetaKeys = array(
		'_purchase_note',
		'_thumbnail_id',
		'_product_image_gallery',
	);

	public function __construct( TranslationRepository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_filter( 'get_post_metadata', array( $this, 'filterPostMetadata' ), 5, 4 );
		add_filter( 'woocommerce_data_store_wp_post_read_meta', array( $this, 'filterMetaStore' ), 10, 3 );
	}

	public function filterPostMetadata( $value, int $objectId, string $metaKey, bool $single ) {
		if ( $this->resolving ) {
			return $value;
		}

		if ( ! $this->isTranslatedProduct( $objectId ) ) {
			return $value;
		}

		if ( ! $this->isOverrideEnabled() ) {
			return $value;
		}

		$excluded = $this->getExcludedMetaKeys();

		if ( in_array( $metaKey, $excluded, true ) ) {
			if ( '_thumbnail_id' === $metaKey || '_product_image_gallery' === $metaKey ) {
				return $this->resolveImageMeta( $objectId, $metaKey, $single );
			}

			return $value;
		}

		$sourceId = $this->resolveSourceId( $objectId );

		if ( ! $sourceId ) {
			return $value;
		}

		$this->resolving = true;
		$sourceValue = get_post_meta( $sourceId, $metaKey, $single );
		$this->resolving = false;

		if ( false === $sourceValue ) {
			return $value;
		}

		if ( $single ) {
			return array( $sourceValue );
		}

		return is_array( $sourceValue ) ? $sourceValue : array( $sourceValue );
	}

	public function filterMetaStore( array $metaData, \WC_Data $object, \WC_Data_Store_WP $store = null ): array {
		if ( ! $store instanceof \WC_Data_Store_WP ) {
			return $metaData;
		}

		if ( $this->resolving ) {
			return $metaData;
		}

		$objectId = $object->get_id();

		if ( ! $objectId || ! $this->isTranslatedProduct( $objectId ) ) {
			return $metaData;
		}

		if ( ! $this->isOverrideEnabled() ) {
			return $metaData;
		}

		$sourceId = $this->resolveSourceId( $objectId );

		if ( ! $sourceId ) {
			return $metaData;
		}

		global $wpdb;

		$postMetaTable = $wpdb->postmeta;

		$excluded = $this->getExcludedMetaKeys();

		$placeholders = implode( ',', array_fill( 0, count( $excluded ), '%s' ) );

		$this->resolving = true;
		$sourceRows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_key, meta_value FROM {$postMetaTable} WHERE post_id = %d AND meta_key NOT IN ({$placeholders})",
				array_merge( array( $sourceId ), $excluded )
			),
			ARRAY_A
		);
		$this->resolving = false;

		if ( ! is_array( $sourceRows ) ) {
			return $metaData;
		}

		$sourceMap = array();

		foreach ( $sourceRows as $row ) {
			$sourceMap[ $row['meta_key'] ] = $row['meta_value'];
		}

		foreach ( $metaData as &$row ) {
			$metaKey = is_object( $row ) ? $row->meta_key : $row['meta_key'];

			if ( in_array( $metaKey, $excluded, true ) ) {
				continue;
			}

			if ( isset( $sourceMap[ $metaKey ] ) ) {
				if ( is_object( $row ) ) {
					$row->meta_value = $sourceMap[ $metaKey ];
				} else {
					$row['meta_value'] = $sourceMap[ $metaKey ];
				}
			}
		}
		unset( $row );

		return $metaData;
	}

	private function isTranslatedProduct( int $postId ): bool {
		$post = get_post( $postId );

		if ( ! $post ) {
			return false;
		}

		if ( ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return false;
		}

		$elementType = 'post_' . $post->post_type;
		$row = $this->repository->getByElement( $elementType, $postId );

		if ( ! $row ) {
			return false;
		}

		return ! empty( $row['source_language_code'] );
	}

	private function resolveSourceId( int $translatedId ): ?int {
		if ( isset( $this->resolvedSourceIds[ $translatedId ] ) ) {
			return $this->resolvedSourceIds[ $translatedId ];
		}

		$post = get_post( $translatedId );

		if ( ! $post ) {
			return null;
		}

		$elementType = 'post_' . $post->post_type;
		$translatedRow = $this->repository->getByElement( $elementType, $translatedId );

		if ( ! $translatedRow || empty( $translatedRow['trid'] ) ) {
			return null;
		}

		$trid = (int) $translatedRow['trid'];
		$group = $this->repository->getByTrid( $trid );

		if ( empty( $group ) ) {
			return null;
		}

		$sourceId = null;

		foreach ( $group as $row ) {
			if ( empty( $row['source_language_code'] ) && $row['element_type'] === $elementType ) {
				$sourceId = (int) $row['element_id'];
				break;
			}
		}

		if ( $sourceId ) {
			$this->resolvedSourceIds[ $translatedId ] = $sourceId;
		}

		return $sourceId;
	}

	private function resolveImageMeta( int $translatedId, string $metaKey, bool $single ) {
		$this->resolving = true;
		$translatedValue = get_post_meta( $translatedId, $metaKey, true );

		if ( ! empty( $translatedValue ) ) {
			$this->resolving = false;

			if ( $single ) {
				return array( $translatedValue );
			}

			return array( $translatedValue );
		}

		$sourceId = $this->resolveSourceId( $translatedId );

		if ( ! $sourceId ) {
			$this->resolving = false;

			if ( $single ) {
				return array( $translatedValue );
			}

			return array( $translatedValue );
		}

		$sourceValue = get_post_meta( $sourceId, $metaKey, true );
		$this->resolving = false;

		if ( $single ) {
			return array( $sourceValue );
		}

		return array( $sourceValue );
	}

	private function isOverrideEnabled(): bool {
		return apply_filters( 'polyglot_woocommerce_source_product_data', true );
	}

	private function getExcludedMetaKeys(): array {
		return apply_filters( 'polyglot_woocommerce_source_excluded_meta_keys', $this->excludedMetaKeys );
	}
}
