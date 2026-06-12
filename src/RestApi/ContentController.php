<?php
/**
 * REST API controller for content discovery.
 *
 * Registers the `/polyglot/v1/content` route with endpoints for listing
 * all published WordPress content merged with translation status, and
 * batch-registering content for translation. All endpoints require the
 * `manage_options` capability.
 *
 * @package NovaTools\Polyglot\RestApi
 */

namespace NovaTools\Polyglot\RestApi;

use NovaTools\Polyglot\Core\Plugin;
use NovaTools\Polyglot\Database\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

class ContentController {

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
	const REST_BASE = 'content';

	/**
	 * Register the routes for this controller.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		// GET /polyglot/v1/content — list all published content with translation status.
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
			)
		);

		// POST /polyglot/v1/content/register — batch-register content for translation.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/register',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'registerItems' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
					'args'                => $this->getRegisterArgs(),
				),
			)
		);
	}

	/**
	 * List all published content merged with translation status.
	 *
	 * Queries wp_posts with a LEFT JOIN against polyglot_translations,
	 * then reshapes flat rows into items with a translations map keyed
	 * by language code.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getItems( WP_REST_Request $request ) {
		global $wpdb;

		$posts_table       = $wpdb->posts;
		$translations_table = Schema::getTableName( 'polyglot_translations' );

		$post_types = $request->get_param( 'post_type' );
		$search     = $request->get_param( 'search' );
		$language   = $request->get_param( 'language' );
		$status     = $request->get_param( 'status' );
		$per_page   = absint( $request->get_param( 'per_page' ) ) ?: 50;
		$page       = max( 1, absint( $request->get_param( 'page' ) ) );
		$offset     = ( $page - 1 ) * $per_page;

		// Determine translatable post types.
		if ( ! empty( $post_types ) ) {
			$post_types = array_map( 'sanitize_text_field', (array) $post_types );
		} else {
			$option     = get_option( 'polyglot_settings', array() );
			$post_types = $option['post_types'] ?? array( 'post', 'page' );

			if ( empty( $post_types ) ) {
				$post_types = array( 'post', 'page' );
			}
		}

		// Build WHERE clauses for source posts.
		$where  = array();
		$params = array();

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$where[]      = "p.post_status = 'publish' AND p.post_type IN ({$placeholders})";
		$params       = array_merge( $params, $post_types );

		// Exclude translated copies — only show the original source post per group.
		$where[] = "p.ID NOT IN (
			SELECT copy.element_id
			FROM {$translations_table} copy
			INNER JOIN {$translations_table} src
				ON src.trid = copy.trid AND src.element_id < copy.element_id
			WHERE copy.element_type = CONCAT('post_', p.post_type)
		)";

		if ( ! empty( $search ) ) {
			$where[]  = 'p.post_title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%';
		}

		// Filter by translation status for a specific language.
		$having = '';
		if ( ! empty( $language ) && ! empty( $status ) ) {
			if ( 'not_registered' === $status ) {
				$having = 'HAVING MAX(CASE WHEN t.language_code = %s THEN 1 ELSE 0 END) = 0';
				$params[] = sanitize_text_field( $language );
			} else {
				$having = 'HAVING MAX(CASE WHEN t.language_code = %s AND t.status = %s THEN 1 ELSE 0 END) = 1';
				$params[] = sanitize_text_field( $language );
				$params[] = sanitize_text_field( $status );
			}
		} elseif ( ! empty( $language ) ) {
			$having   = 'HAVING MAX(CASE WHEN t.language_code = %s THEN 1 ELSE 0 END) = 1';
			$params[] = sanitize_text_field( $language );
		}

		$where_clause = implode( ' AND ', $where );

		// Count total distinct source posts matching filters.
		$count_sql = "SELECT COUNT(*) FROM (
			SELECT p.ID
			FROM {$posts_table} p
			LEFT JOIN {$translations_table} t
				ON t.element_id = p.ID AND t.element_type = CONCAT('post_', p.post_type)
			WHERE {$where_clause}
			GROUP BY p.ID
			{$having}
		) AS filtered";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		// Fetch paginated source posts.
		$posts_sql = "SELECT p.ID AS element_id, p.post_type, p.post_title, p.post_excerpt
			FROM {$posts_table} p
			LEFT JOIN {$translations_table} t
				ON t.element_id = p.ID AND t.element_type = CONCAT('post_', p.post_type)
			WHERE {$where_clause}
			GROUP BY p.ID
			{$having}
			ORDER BY p.post_title ASC
			LIMIT %d OFFSET %d";

		$posts_params = array_merge( $params, array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$source_posts = $wpdb->get_results(
			$wpdb->prepare( $posts_sql, $posts_params ),
			ARRAY_A
		);

		$items = array();

		if ( ! empty( $source_posts ) ) {
			$source_ids = array_map( 'intval', array_column( $source_posts, 'element_id' ) );
			$ids_placeholders = implode( ', ', array_fill( 0, count( $source_ids ), '%d' ) );

			// Fetch all translation rows for these source posts (including the source post's own row).
			$trans_sql = "SELECT s.element_id AS source_id, t2.language_code, t2.status, t2.trid, t2.translation_id,
				tp.post_title AS translated_title, tp.post_content AS translated_content, tp.post_excerpt AS translated_excerpt
				FROM {$translations_table} s
				INNER JOIN {$translations_table} t2 ON t2.trid = s.trid
				LEFT JOIN {$posts_table} tp ON tp.ID = t2.element_id
				WHERE s.element_id IN ({$ids_placeholders})";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$translation_rows = $wpdb->get_results(
				$wpdb->prepare( $trans_sql, $source_ids ),
				ARRAY_A
			);

			// Group translations by source post ID.
			$translations_by_source = array();
			foreach ( $translation_rows as $trow ) {
				$sid = (int) $trow['source_id'];
				if ( ! isset( $translations_by_source[ $sid ] ) ) {
					$translations_by_source[ $sid ] = array();
				}
				$translations_by_source[ $sid ][ $trow['language_code'] ] = array(
					'status'             => $trow['status'],
					'trid'               => (int) $trow['trid'],
					'translation_id'     => (int) $trow['translation_id'],
					'translated_title'   => $trow['translated_title'] ?? '',
					'translated_content' => $trow['translated_content'] ?? '',
					'translated_excerpt' => $trow['translated_excerpt'] ?? '',
				);
			}

			// Build items with translations map.
			foreach ( $source_posts as $sp ) {
				$sid = (int) $sp['element_id'];
				$items[] = array(
					'element_id'   => $sid,
					'element_type' => 'post_' . $sp['post_type'],
					'title'        => $sp['post_title'],
					'excerpt'      => $sp['post_excerpt'] ?? '',
					'post_type'    => $sp['post_type'],
					'translations' => $translations_by_source[ $sid ] ?? array(),
				);
			}
		}

		return new WP_REST_Response(
			array(
				'items'     => $items,
				'total'     => $total,
				'per_page'  => $per_page,
				'page'      => $page,
				'max_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
			),
			200
		);
	}

	/**
	 * Batch-register content items for translation.
	 *
	 * Accepts an array of items and registers each one via
	 * ContentTranslator::setElementLanguage(). Skips items that
	 * already have a translation row.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function registerItems( WP_REST_Request $request ) {
		$plugin = Plugin::getInstance();

		if ( ! $plugin->has( 'content.translator' ) ) {
			return new WP_Error(
				'polyglot_not_booted',
				__( 'Translation service is not available.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		/** @var \NovaTools\Polyglot\Translation\ContentTranslator $translator */
		$translator = $plugin->get( 'content.translator' );

		$items = $request->get_param( 'items' );

		if ( empty( $items ) || ! is_array( $items ) ) {
			return new WP_Error(
				'polyglot_missing_items',
				__( 'An "items" array is required.', 'novatools-polyglot' ),
				array( 'status' => 400 )
			);
		}

		$registered = 0;
		$skipped    = 0;

		foreach ( $items as $item ) {
			$elementId   = absint( $item['element_id'] ?? 0 );
			$elementType = sanitize_text_field( $item['element_type'] ?? '' );
			$languageCode = sanitize_text_field( $item['language_code'] ?? '' );

			if ( empty( $elementId ) || empty( $elementType ) || empty( $languageCode ) ) {
				continue;
			}

			$existing = $translator->getTranslationGroup( $elementId, $elementType );

			if ( $existing ) {
				++$skipped;
				continue;
			}

			$result = $translator->setElementLanguage( $elementId, $elementType, $languageCode );

			if ( false !== $result ) {
				++$registered;
			}
		}

		return new WP_REST_Response(
			array(
				'registered' => $registered,
				'skipped'    => $skipped,
			),
			200
		);
	}

	/**
	 * Reshape flat JOIN rows into grouped items with a translations map.
	 *
	 * Each unique (element_id, post_type) becomes one item with a
	 * `translations` map keyed by language code.
	 *
	 * @param array $rows Raw rows from the JOIN query.
	 * @return array Grouped items.
	 */
	private function reshapeRows( array $rows ): array {
		$items = array();

		foreach ( $rows as $row ) {
			$key = $row['element_id'] . '_' . $row['post_type'];

			if ( ! isset( $items[ $key ] ) ) {
				$items[ $key ] = array(
					'element_id'   => (int) $row['element_id'],
					'element_type' => 'post_' . $row['post_type'],
					'title'        => $row['post_title'],
					'excerpt'      => $row['post_excerpt'] ?? '',
					'post_type'    => $row['post_type'],
					'translations' => array(),
				);
			}

			if ( ! empty( $row['language_code'] ) ) {
				$items[ $key ]['translations'][ $row['language_code'] ] = array(
					'status'           => $row['status'],
					'trid'             => (int) $row['trid'],
					'translation_id'   => (int) $row['translation_id'],
					'translated_title' => $row['translated_title'] ?? '',
					'translated_content' => $row['translated_content'] ?? '',
					'translated_excerpt' => $row['translated_excerpt'] ?? '',
				);
			}
		}

		return array_values( $items );
	}

	/**
	 * Check if a given request has access to content endpoints.
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
			'post_type' => array(
				'description'       => __( 'Filter by post type (e.g. "post", "page"). Accepts a single value or comma-separated list.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'search' => array(
				'description'       => __( 'Search by post title.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'language' => array(
				'description'       => __( 'Filter by language code. Returns only content with translations in this language.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status' => array(
				'description'       => __( 'Filter by translation status (requires "language" param). Use "not_registered" for content without any translation row.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'per_page' => array(
				'description' => __( 'Maximum number of items per page.', 'novatools-polyglot' ),
				'type'        => 'integer',
				'default'     => 50,
				'minimum'     => 1,
				'maximum'     => 100,
			),
			'page' => array(
				'description' => __( 'Current page of the collection.', 'novatools-polyglot' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
		);
	}

	/**
	 * Get the arguments for the batch-register endpoint.
	 *
	 * @return array[]
	 */
	protected function getRegisterArgs(): array {
		return array(
			'items' => array(
				'description' => __( 'Array of content items to register. Each item needs element_id, element_type, and language_code.', 'novatools-polyglot' ),
				'type'        => 'array',
				'required'    => true,
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'element_id'   => array(
							'type'     => 'integer',
							'required' => true,
						),
						'element_type' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'language_code' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			),
		);
	}
}
