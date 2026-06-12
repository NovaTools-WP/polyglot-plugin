<?php

namespace NovaTools\Polyglot\WooCommerce;

use NovaTools\Polyglot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

class AdminProductFields {

	private TranslationRepository $repository;

	private array $commerceFieldSelectors = array(
		'#_regular_price',
		'#_sale_price',
		'#_sale_price_dates_from',
		'#_sale_price_dates_to',
		'#_stock',
		'#_low_stock_amount',
		'#_backorders',
		'#_stock_status',
		'#_weight',
		'#_length',
		'#_width',
		'#_height',
		'#_sku',
		'#_virtual',
		'#_downloadable',
		'#_download_limit',
		'#_download_expiry',
		'#_sold_individually',
		'#_manage_stock',
		'#_tax_status',
		'#_tax_class',
	);

	public function __construct( TranslationRepository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueFieldScripts' ) );
	}

	public function enqueueFieldScripts( string $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$postId = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		if ( ! $postId ) {
			global $post;
			$postId = $post->ID ?? 0;
		}

		if ( ! $postId ) {
			return;
		}

		$post = get_post( $postId );

		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		if ( ! $this->isTranslatedProduct( $postId ) ) {
			return;
		}

		$this->enqueueInlineCss();
		$this->enqueueInlineJs();
	}

	private function isTranslatedProduct( int $postId ): bool {
		$post = get_post( $postId );

		if ( ! $post || 'product' !== $post->post_type ) {
			return false;
		}

		$row = $this->repository->getByElement( 'post_' . $post->post_type, $postId );

		if ( ! $row ) {
			return false;
		}

		return ! empty( $row['source_language_code'] );
	}

	private function enqueueInlineCss(): void {
		$selectors = array();

		foreach ( $this->commerceFieldSelectors as $selector ) {
			$selectors[] = $selector . ', ' . $selector . ' + .description';
		}

		$selectorList = implode( ', ', $selectors );

		$css = '
			' . $selectorList . ' {
				opacity: 0.7;
				pointer-events: none;
				cursor: not-allowed;
			}
		';

		wp_register_style( 'polyglot-admin-product-fields', false );
		wp_enqueue_style( 'polyglot-admin-product-fields' );
		wp_add_inline_style( 'polyglot-admin-product-fields', $css );
	}

	private function enqueueInlineJs(): void {
		$selectorsJson = wp_json_encode( $this->commerceFieldSelectors );
		$excludedJson  = wp_json_encode( array( '#_purchase_note' ) );

		$js = "
			(function() {
				var selectors = {$selectorsJson};
				var excluded = {$excludedJson};
				var fields = document.querySelectorAll(selectors.join(','));
				fields.forEach(function(field) {
					if (excluded.some(function(sel) { return field.matches(sel); })) {
						return;
					}
					field.disabled = true;
					field.title = 'Value inherited from original language product';
					field.setAttribute('data-polyglot-source', 'true');
				});
			})();
		";

		wp_add_inline_script( 'jquery', $js );
	}
}
