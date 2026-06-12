<?php
/**
 * REST API controller for string translation management.
 *
 * Registers the `/polyglot/v1/strings` route with endpoints for listing,
 * registering, and translating strings. All endpoints require the
 * `manage_options` capability.
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

class StringsController {

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
	const REST_BASE = 'strings';

	/**
	 * Register the routes for this controller.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		// GET /polyglot/v1/strings — list strings with domain/status filters.
		// POST /polyglot/v1/strings — register a new string.
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

		// GET /polyglot/v1/strings/domains — list unique domains.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/domains',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getDomains' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
				),
			)
		);

		// PUT /polyglot/v1/strings/{id}/translate — save a translation for a string.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/(?P<id>\d+)/translate',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'translateItem' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
					'args'                => $this->getTranslateItemArgs(),
				),
			)
		);
	}

	/**
	 * List registered strings with optional domain/status filters.
	 *
	 * Returns paginated results from the string search engine.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getItems( WP_REST_Request $request ) {
		$plugin = Plugin::getInstance();

		if ( ! $plugin->has( 'string.repository' ) ) {
			return new WP_Error(
				'polyglot_not_booted',
				__( 'String service is not available.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		/** @var \NovaTools\Polyglot\String\StringRepository $repo */
		$repo = $plugin->get( 'string.repository' );

		$args = array(
			'domain'             => sanitize_text_field( $request->get_param( 'domain' ) ) ?: '',
			'search'             => sanitize_text_field( $request->get_param( 'search' ) ) ?: '',
			'language'           => sanitize_text_field( $request->get_param( 'language' ) ) ?: '',
			'translation_status' => $request->get_param( 'translation_status' ) !== null
				? absint( $request->get_param( 'translation_status' ) )
				: -1,
			'per_page'           => absint( $request->get_param( 'per_page' ) ) ?: 20,
			'page'               => absint( $request->get_param( 'page' ) ) ?: 1,
			'orderby'            => sanitize_text_field( $request->get_param( 'orderby' ) ) ?: 'id',
			'order'              => sanitize_text_field( $request->get_param( 'order' ) ) ?: 'ASC',
		);

		$packageId = $request->get_param( 'package_id' );
		if ( null !== $packageId ) {
			$args['package_id'] = absint( $packageId );
		}

		$result = $repo->search( $args );

		$per_page = $args['per_page'];

		return new WP_REST_Response(
			array(
				'items'     => $result['items'],
				'total'     => $result['total'],
				'per_page'  => $per_page,
				'page'      => $args['page'],
				'max_pages' => $per_page > 0 ? (int) ceil( $result['total'] / $per_page ) : 1,
			),
			200
		);
	}

	/**
	 * List unique domains that have registered strings.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getDomains( WP_REST_Request $request ) {
		$plugin = Plugin::getInstance();

		if ( ! $plugin->has( 'string.repository' ) ) {
			return new WP_Error(
				'polyglot_not_booted',
				__( 'String service is not available.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		/** @var \NovaTools\Polyglot\String\StringRepository $repo */
		$repo    = $plugin->get( 'string.repository' );
		$domains = $repo->getDomains();

		return new WP_REST_Response( $domains, 200 );
	}

	/**
	 * Register a new string for translation.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function createItem( WP_REST_Request $request ) {
		$plugin = Plugin::getInstance();

		if ( ! $plugin->has( 'string.manager' ) ) {
			return new WP_Error(
				'polyglot_not_booted',
				__( 'String manager is not available.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		$domain  = sanitize_text_field( $request->get_param( 'domain' ) );
		$name    = sanitize_text_field( $request->get_param( 'name' ) );
		$value   = $request->get_param( 'value' );
		$context = sanitize_text_field( $request->get_param( 'context' ) ) ?: '';

		if ( empty( $domain ) || empty( $name ) ) {
			return new WP_Error(
				'polyglot_missing_fields',
				__( 'domain and name are required.', 'novatools-polyglot' ),
				array( 'status' => 400 )
			);
		}

		$args = array();

		$type = sanitize_text_field( $request->get_param( 'type' ) );
		if ( ! empty( $type ) ) {
			$args['type'] = $type;
		}

		$title = sanitize_text_field( $request->get_param( 'title' ) );
		if ( ! empty( $title ) ) {
			$args['title'] = $title;
		}

		$packageId = $request->get_param( 'package_id' );
		if ( null !== $packageId ) {
			$args['package_id'] = absint( $packageId );
		}

		/** @var \NovaTools\Polyglot\String\StringManager $manager */
		$manager = $plugin->get( 'string.manager' );

		try {
			$stringId = $manager->registerString( $domain, $name, $value, $context, $args );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'polyglot_string_register_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		// Return the full registered string.
		/** @var \NovaTools\Polyglot\String\StringRepository $repo */
		$repo   = $plugin->get( 'string.repository' );
		$string = $repo->findById( $stringId );

		return new WP_REST_Response(
			array(
				'id'      => $stringId,
				'string'  => $string,
			),
			201
		);
	}

	/**
	 * Save a translation for a specific string.
	 *
	 * Creates or updates the translation row for the given string ID
	 * and target language.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function translateItem( WP_REST_Request $request ) {
		$plugin = Plugin::getInstance();

		if ( ! $plugin->has( 'string.manager' ) ) {
			return new WP_Error(
				'polyglot_not_booted',
				__( 'String manager is not available.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		$stringId = absint( $request->get_param( 'id' ) );
		$language = sanitize_text_field( $request->get_param( 'language' ) );
		$value    = $request->get_param( 'value' );
		$status   = $request->get_param( 'status' ) !== null
			? absint( $request->get_param( 'status' ) )
			: 1;

		if ( empty( $stringId ) || empty( $language ) ) {
			return new WP_Error(
				'polyglot_missing_fields',
				__( 'String ID and language are required.', 'novatools-polyglot' ),
				array( 'status' => 400 )
			);
		}

		// Verify the string exists.
		/** @var \NovaTools\Polyglot\String\StringRepository $repo */
		$repo   = $plugin->get( 'string.repository' );
		$string = $repo->findById( $stringId );

		if ( ! $string ) {
			return new WP_Error(
				'polyglot_string_not_found',
				sprintf(
					/* translators: %d: string ID */
					__( 'String with ID %d not found.', 'novatools-polyglot' ),
					$stringId
				),
				array( 'status' => 404 )
			);
		}

		/** @var \NovaTools\Polyglot\String\StringManager $manager */
		$manager = $plugin->get( 'string.manager' );

		try {
			$translationId = $manager->saveTranslation( $stringId, $language, $value, $status );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'polyglot_translation_save_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		// Return the updated translation.
		$translation = $repo->getTranslation( $stringId, $language );

		return new WP_REST_Response(
			array(
				'translation_id' => $translationId,
				'string_id'      => $stringId,
				'language'       => $language,
				'translation'    => $translation,
			),
			200
		);
	}

	/**
	 * Check if a given request has access to manage strings.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return true|WP_Error
	 */
	public function permissionsCheck( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'polyglot_rest_forbidden',
				__( 'Sorry, you are not allowed to manage strings.', 'novatools-polyglot' ),
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
			'domain' => array(
				'description'       => __( 'Filter by text domain.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'package_id' => array(
				'description' => __( 'Filter by package ID.', 'novatools-polyglot' ),
				'type'        => 'integer',
			),
			'search' => array(
				'description'       => __( 'Search strings by value or name.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'language' => array(
				'description'       => __( 'Language code for translation status filtering.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'translation_status' => array(
				'description' => __( 'Translation status: 0 = untranslated, 1 = translated, 2 = needs_update.', 'novatools-polyglot' ),
				'type'        => 'integer',
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
				'description'       => __( 'Sort column.', 'novatools-polyglot' ),
				'type'              => 'string',
				'default'           => 'id',
				'enum'              => array( 'id', 'domain', 'name', 'value', 'status', 'package_id' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'order' => array(
				'description'       => __( 'Sort order.', 'novatools-polyglot' ),
				'type'              => 'string',
				'default'           => 'ASC',
				'enum'              => array( 'ASC', 'DESC' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Get the arguments for the create-item (register string) endpoint.
	 *
	 * @return array[]
	 */
	protected function getCreateItemArgs(): array {
		return array(
			'domain' => array(
				'description'       => __( 'Text domain for the string.', 'novatools-polyglot' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'name' => array(
				'description'       => __( 'Machine-readable string identifier.', 'novatools-polyglot' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'value' => array(
				'description' => __( 'The source string value.', 'novatools-polyglot' ),
				'type'        => 'string',
				'required'    => true,
			),
			'context' => array(
				'description'       => __( 'Optional grouping context.', 'novatools-polyglot' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'type' => array(
				'description'       => __( 'String type: LINE, TEXTAREA, or VISUAL.', 'novatools-polyglot' ),
				'type'              => 'string',
				'default'           => 'LINE',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'title' => array(
				'description'       => __( 'Human-readable label for the string.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'package_id' => array(
				'description' => __( 'Package ID to link the string to.', 'novatools-polyglot' ),
				'type'        => 'integer',
			),
		);
	}

	/**
	 * Get the arguments for the translate-item endpoint.
	 *
	 * @return array[]
	 */
	protected function getTranslateItemArgs(): array {
		return array(
			'id' => array(
				'description' => __( 'String ID to translate.', 'novatools-polyglot' ),
				'type'        => 'integer',
				'required'    => true,
			),
			'language' => array(
				'description'       => __( 'Target language code.', 'novatools-polyglot' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'value' => array(
				'description' => __( 'The translated string value.', 'novatools-polyglot' ),
				'type'        => 'string',
				'required'    => true,
			),
			'status' => array(
				'description' => __( 'Translation status: 0 = untranslated, 1 = translated, 2 = needs_update.', 'novatools-polyglot' ),
				'type'        => 'integer',
				'default'     => 1,
			),
		);
	}
}
