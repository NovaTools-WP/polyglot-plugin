<?php
/**
 * REST API controller for content translations.
 *
 * Registers the `/polyglot/v1/translations` route with endpoints for listing,
 * creating/saving, and retrieving translation groups by trid. All endpoints
 * require the `manage_options` capability.
 *
 * @package NovaTools\Polyglot\RestApi
 */

namespace NovaTools\Polyglot\RestApi;

use NovaTools\Polyglot\Core\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

class TranslationsController {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'polyglot/v1';

	/**
	 * REST base for this controller.
	 *
	 * @var string
	 */
	const REST_BASE = 'translations';

	/**
	 * Register the routes for this controller.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		// GET /polyglot/v1/translations — list with filters.
		// POST /polyglot/v1/translations — create/save a translation.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getItems' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
					'args'                => $this->getCollectionParams(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'createItem' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
					'args'                => $this->getCreateItemArgs(),
				),
			)
		);

		// GET /polyglot/v1/translations/{trid} — get translation group by trid.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/(?P<trid>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getItem' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
					'args'                => array(
						'trid' => array(
							'description' => __( 'Translation group ID (trid).', 'novatools-polyglot' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * List translations with optional filters.
	 *
	 * Delegates to TranslationRepository::paginate() for consistent
	 * query logic and pagination.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getItems( WP_REST_Request $request ) {
		$plugin = Plugin::getInstance();

		if ( ! $plugin->has( 'translation.repository' ) ) {
			return new WP_Error(
				'polyglot_not_booted',
				__( 'Translation service is not available.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		/** @var \NovaTools\Polyglot\Translation\TranslationRepository $repo */
		$repo = $plugin->get( 'translation.repository' );

		$args = array(
			'element_type' => sanitize_text_field( $request->get_param( 'element_type' ) ?: '' ),
			'language'     => sanitize_text_field( $request->get_param( 'language_code' ) ?: '' ),
			'status'       => sanitize_text_field( $request->get_param( 'status' ) ?: '' ),
			'trid'         => $request->get_param( 'trid' ) ? absint( $request->get_param( 'trid' ) ) : 0,
			'per_page'     => absint( $request->get_param( 'per_page' ) ) ?: 20,
			'page'         => max( 1, absint( $request->get_param( 'page' ) ) ),
			'orderby'      => sanitize_text_field( $request->get_param( 'orderby' ) ?: 'trid' ),
			'order'        => sanitize_text_field( $request->get_param( 'order' ) ?: 'ASC' ),
		);

		$result = $repo->paginate( $args );

		return new WP_REST_Response(
			array(
				'items'     => $result['items'],
				'total'     => $result['total'],
				'per_page'  => $result['per_page'],
				'page'      => $result['page'],
				'max_pages' => $result['per_page'] > 0 ? (int) ceil( $result['total'] / $result['per_page'] ) : 1,
			),
			200
		);
	}

	/**
	 * Get a translation group by trid.
	 *
	 * Returns all translation rows that share the given trid, representing
	 * a complete translation group across all languages.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getItem( WP_REST_Request $request ) {
		$trid   = absint( $request->get_param( 'trid' ) );
		$plugin = Plugin::getInstance();

		if ( ! $plugin->has( 'translation.repository' ) ) {
			return new WP_Error(
				'polyglot_not_booted',
				__( 'Translation service is not available.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		/** @var \NovaTools\Polyglot\Translation\TranslationRepository $repo */
		$repo = $plugin->get( 'translation.repository' );

		$group = $repo->getGroupByTrid( $trid );

		if ( ! $group ) {
			return new WP_Error(
				'polyglot_translation_group_not_found',
				sprintf(
					/* translators: %d: translation group ID */
					__( 'Translation group with trid %d not found.', 'novatools-polyglot' ),
					$trid
				),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $group->toArray(), 200 );
	}

	/**
	 * Create or update a translation relationship.
	 *
	 * Creates a new translation row linking an element to a language and
	 * trid group, or updates an existing row if the element is already
	 * registered.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function createItem( WP_REST_Request $request ) {
		$plugin = Plugin::getInstance();

		if ( ! $plugin->has( 'content.translator' ) ) {
			return new WP_Error(
				'polyglot_not_booted',
				__( 'Translation service is not available.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		$elementType        = sanitize_text_field( $request->get_param( 'element_type' ) );
		$elementId          = absint( $request->get_param( 'element_id' ) );
		$languageCode       = sanitize_text_field( $request->get_param( 'language_code' ) );
		$sourceLanguageCode = sanitize_text_field( $request->get_param( 'source_language_code' ) );
		$trid               = $request->get_param( 'trid' );
		$status             = sanitize_text_field( $request->get_param( 'status' ) ) ?: 'not_translated';
		$title              = sanitize_text_field( $request->get_param( 'title' ) ?? '' );
		$content            = wp_kses_post( $request->get_param( 'content' ) ?? '' );
		$excerpt            = sanitize_textarea_field( $request->get_param( 'excerpt' ) ?? '' );

		if ( empty( $elementType ) || empty( $elementId ) || empty( $languageCode ) ) {
			return new WP_Error(
				'polyglot_missing_fields',
				__( 'element_type, element_id, and language_code are required.', 'novatools-polyglot' ),
				array( 'status' => 400 )
			);
		}

		/** @var \NovaTools\Polyglot\Translation\ContentTranslator $translator */
		$translator = $plugin->get( 'content.translator' );

		// If no trid provided, check if the element already has one.
		if ( empty( $trid ) ) {
			$existing = $translator->getTranslationGroup( $elementId, $elementType );

			if ( $existing ) {
				$trid = $existing->trid;
			} else {
				// Get next trid for a new group.
				$repo = $plugin->get( 'translation.repository' );
				$trid = $repo->getNextTrid();
			}
		}

		// For post types, always create or update a translated post.
		// The source post's own row in polyglot_translations must not be overwritten.
		$savedElementId = $elementId;

		if ( $translator->isPostType( $elementType ) ) {
			$postType   = substr( $elementType, 5 ); // Strip 'post_' prefix.
			$group      = $translator->getTranslationGroup( $elementId, $elementType );
			$existingId = $group ? $group->getElementId( $languageCode ) : null;

			// Use source post content as fallback for empty fields.
			$sourcePost = get_post( $elementId );
			$finalTitle   = '' !== $title ? $title : ( $sourcePost->post_title ?? '' );
			$finalContent = '' !== $content ? $content : '';
			$finalExcerpt = '' !== $excerpt ? $excerpt : ( $sourcePost->post_excerpt ?? '' );

			if ( $existingId ) {
				wp_update_post( array(
					'ID'           => $existingId,
					'post_title'   => $finalTitle,
					'post_content' => $finalContent,
					'post_excerpt' => $finalExcerpt,
				) );
				$savedElementId = $existingId;
			} else {
				$newId = wp_insert_post( array(
					'post_type'    => $postType,
					'post_title'   => $finalTitle,
					'post_content' => $finalContent,
					'post_excerpt' => $finalExcerpt,
					'post_status'  => 'publish',
					'post_author'  => get_current_user_id(),
				), true );

				if ( is_wp_error( $newId ) ) {
					return $newId;
				}

				$savedElementId = $newId;
			}
		}

		$translationId = $translator->saveTranslation(
			$savedElementId,
			$elementType,
			$languageCode,
			$sourceLanguageCode ?: '',
			absint( $trid ),
			$status
		);

		if ( false === $translationId ) {
			return new WP_Error(
				'polyglot_translation_save_failed',
				__( 'Failed to save translation.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		// Return the full group after save.
		$group = $translator->getTranslationGroup( $elementId, $elementType );

		return new WP_REST_Response(
			array(
				'translation_id' => $translationId,
				'trid'           => absint( $trid ),
				'group'          => $group ? $group->toArray() : null,
			),
			201
		);
	}

	/**
	 * Check if a given request has access to translations.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return true|WP_Error
	 */
	public function permissionsCheck( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'polyglot_rest_forbidden',
				__( 'Sorry, you are not allowed to manage translations.', 'novatools-polyglot' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Get the query parameters for the collection endpoint.
	 *
	 * @return array[]
	 */
	protected function getCollectionParams(): array {
		return array(
			'element_type' => array(
				'description'       => __( 'Filter by element type (e.g. "post_post", "tax_category").', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'language_code' => array(
				'description'       => __( 'Filter by language code.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'trid' => array(
				'description' => __( 'Filter by translation group ID.', 'novatools-polyglot' ),
				'type'        => 'integer',
			),
			'status' => array(
				'description'       => __( 'Filter by translation status.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'per_page' => array(
				'description' => __( 'Maximum number of items per page.', 'novatools-polyglot' ),
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 100,
			),
			'page' => array(
				'description' => __( 'Current page of the collection.', 'novatools-polyglot' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
			'orderby' => array(
				'description'       => __( 'Sort column. Allowed: translation_id, trid, element_type, element_id, language_code, status, translated_at.', 'novatools-polyglot' ),
				'type'              => 'string',
				'default'           => 'trid',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'order' => array(
				'description'       => __( 'Sort direction: ASC or DESC.', 'novatools-polyglot' ),
				'type'              => 'string',
				'default'           => 'ASC',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Get the arguments for the create-item endpoint.
	 *
	 * @return array[]
	 */
	protected function getCreateItemArgs(): array {
		return array(
			'element_type' => array(
				'description'       => __( 'Element type (e.g. "post_post", "tax_category").', 'novatools-polyglot' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'element_id' => array(
				'description' => __( 'WordPress element ID (post ID, term ID, etc.).', 'novatools-polyglot' ),
				'type'        => 'integer',
				'required'    => true,
			),
			'language_code' => array(
				'description'       => __( 'Language code for this element.', 'novatools-polyglot' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'source_language_code' => array(
				'description'       => __( 'Source language code for the translation.', 'novatools-polyglot' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'trid' => array(
				'description' => __( 'Translation group ID. Auto-generated if omitted.', 'novatools-polyglot' ),
				'type'        => 'integer',
			),
			'status' => array(
				'description'       => __( 'Translation status.', 'novatools-polyglot' ),
				'type'              => 'string',
				'default'           => 'not_translated',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
