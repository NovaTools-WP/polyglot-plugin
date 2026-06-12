<?php

namespace NovaTools\Polyglot\Admin;

use NovaTools\Polyglot\WooCommerce\ProductTranslator;
use NovaTools\Polyglot\WooCommerce\VariationTranslator;
use NovaTools\Polyglot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

class ProductTranslationHandler {

	private ProductTranslator $productTranslator;

	private VariationTranslator $variationTranslator;

	private TranslationRepository $repository;

	public function __construct(
		ProductTranslator $productTranslator,
		VariationTranslator $variationTranslator,
		TranslationRepository $repository
	) {
		$this->productTranslator   = $productTranslator;
		$this->variationTranslator = $variationTranslator;
		$this->repository          = $repository;
	}

	public function register(): void {
		add_action( 'admin_action_polyglot_create_product_translation', array( $this, 'handleCreateTranslation' ) );
		add_action( 'admin_notices', array( $this, 'showTranslationNotice' ) );
	}

	public function handleCreateTranslation(): void {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'polyglot_create_product_translation' ) ) {
			wp_die( 'Security check failed.', '403 Forbidden', array( 'response' => 403 ) );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'You do not have permission to create translations.', '403 Forbidden', array( 'response' => 403 ) );
		}

		$sourceId    = isset( $_GET['source'] ) ? (int) $_GET['source'] : 0;
		$targetLang  = isset( $_GET['lang'] ) ? sanitize_key( $_GET['lang'] ) : '';

		if ( ! $sourceId || empty( $targetLang ) ) {
			wp_die( 'Missing required parameters.', '400 Bad Request', array( 'response' => 400 ) );
		}

		$source = get_post( $sourceId );

		if ( ! $source || ! in_array( $source->post_type, array( 'product', 'product_variation' ), true ) ) {
			wp_die( 'Source product not found.', '404 Not Found', array( 'response' => 404 ) );
		}

		$elementType = 'post_' . $source->post_type;

		$existingTranslationId = $this->repository->getTranslatedElementId(
			$sourceId,
			$elementType,
			$targetLang
		);

		if ( null !== $existingTranslationId ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'post'   => $existingTranslationId,
						'action' => 'edit',
					),
					admin_url( 'post.php' )
				)
			);
			exit;
		}

		$newProductId = $this->productTranslator->translate( $sourceId, $targetLang );

		if ( ! $newProductId ) {
			wp_die( 'Failed to create product translation.', '500 Internal Server Error', array( 'response' => 500 ) );
		}

		$product = wc_get_product( $sourceId );

		if ( $product && $product->is_type( 'variable' ) ) {
			$this->variationTranslator->translateAllVariations( $sourceId, $targetLang, $newProductId );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post'                => $newProductId,
					'action'              => 'edit',
					'polyglot_translated' => 1,
				),
				admin_url( 'post.php' )
			)
		);
		exit;
	}

	public function showTranslationNotice(): void {
		if ( ! isset( $_GET['polyglot_translated'] ) ) {
			return;
		}
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Translation created. Translate the text fields and publish.', 'novatools-polyglot' ); ?></p>
		</div>
		<?php
	}
}
