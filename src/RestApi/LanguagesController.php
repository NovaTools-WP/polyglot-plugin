<?php
/**
 * REST API controller for language management.
 *
 * Registers the `/polyglot/v1/languages` route with endpoints for listing,
 * retrieving, adding, and deactivating languages. All mutating endpoints
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

class LanguagesController {

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
	const REST_BASE = 'languages';

	/**
	 * Register the routes for this controller.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		// GET /polyglot/v1/languages — list all languages.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getItems' ),
					'permission_callback' => array( $this, 'getItemsPermissionsCheck' ),
					'args'                => $this->getCollectionParams(),
				),
				// POST /polyglot/v1/languages — add a new language.
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'createItem' ),
					'permission_callback' => array( $this, 'createItemPermissionsCheck' ),
					'args'                => $this->getCreateItemArgs(),
				),
			)
		);

		// GET /polyglot/v1/languages/{code} — single language.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/(?P<code>[a-zA-Z_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getItem' ),
					'permission_callback' => array( $this, 'getItemsPermissionsCheck' ),
					'args'                => array(
						'code' => array(
							'description' => __( 'Language code.', 'novatools-polyglot' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
				// PUT /polyglot/v1/languages/{code} — update a language.
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'updateItem' ),
					'permission_callback' => array( $this, 'updateItemPermissionsCheck' ),
					'args'                => array_merge(
						array(
							'code' => array(
								'description' => __( 'Language code.', 'novatools-polyglot' ),
								'type'        => 'string',
								'required'    => true,
							),
						),
						$this->getUpdateItemArgs()
					),
				),
				// DELETE /polyglot/v1/languages/{code} — deactivate a language.
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'deleteItem' ),
					'permission_callback' => array( $this, 'deleteItemPermissionsCheck' ),
					'args'                => array(
						'code' => array(
							'description' => __( 'Language code to deactivate.', 'novatools-polyglot' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * List all languages, optionally filtered by active status.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getItems( WP_REST_Request $request ) {
		$plugin = Plugin::getInstance();

		if ( ! $plugin->has( 'language.repository' ) ) {
			return new WP_Error(
				'polyglot_not_booted',
				__( 'Language service is not available.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		/** @var \NovaTools\Polyglot\Language\LanguageRepository $repo */
		$repo = $plugin->get( 'language.repository' );

		$active_only = $request->get_param( 'active_only' );

		if ( $active_only ) {
			$languages = $repo->getActive();
		} else {
			$languages = $repo->getAll();
		}

		$data = array();

		foreach ( $languages as $lang ) {
			$data[] = $this->prepareItemForResponse( $lang, $request );
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Get a single language by code.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getItem( WP_REST_Request $request ) {
		$code = $request->get_param( 'code' );
		$plugin = Plugin::getInstance();

		if ( ! $plugin->has( 'language.repository' ) ) {
			return new WP_Error(
				'polyglot_not_booted',
				__( 'Language service is not available.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		/** @var \NovaTools\Polyglot\Language\LanguageRepository $repo */
		$repo = $plugin->get( 'language.repository' );
		$lang = $repo->getByCode( $code );

		if ( ! $lang ) {
			return new WP_Error(
				'polyglot_language_not_found',
				sprintf(
					/* translators: %s: language code */
					__( 'Language "%s" not found.', 'novatools-polyglot' ),
					$code
				),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $this->prepareItemForResponse( $lang, $request ), 200 );
	}

	/**
	 * Add a new language (or reactivate an existing one).
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function createItem( WP_REST_Request $request ) {
		$plugin = Plugin::getInstance();

		if ( ! $plugin->has( 'language.manager' ) ) {
			return new WP_Error(
				'polyglot_not_booted',
				__( 'Language manager is not available.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		$data = array(
			'code'         => sanitize_text_field( $request->get_param( 'code' ) ),
			'locale'       => sanitize_text_field( $request->get_param( 'locale' ) ),
			'english_name' => sanitize_text_field( $request->get_param( 'english_name' ) ),
			'native_name'  => sanitize_text_field( $request->get_param( 'native_name' ) ),
		);

		// Optional fields with defaults.
		if ( $request->get_param( 'direction' ) ) {
			$data['direction'] = sanitize_text_field( $request->get_param( 'direction' ) );
		}
		if ( $request->get_param( 'flag_code' ) ) {
			$data['flag_code'] = sanitize_text_field( $request->get_param( 'flag_code' ) );
		}
		if ( $request->get_param( 'date_format' ) ) {
			$data['date_format'] = sanitize_text_field( $request->get_param( 'date_format' ) );
		}
		if ( $request->get_param( 'time_format' ) ) {
			$data['time_format'] = sanitize_text_field( $request->get_param( 'time_format' ) );
		}
		if ( null !== $request->get_param( 'sort_order' ) ) {
			$data['sort_order'] = absint( $request->get_param( 'sort_order' ) );
		}

		try {
			/** @var \NovaTools\Polyglot\Language\LanguageManager $manager */
			$manager = $plugin->get( 'language.manager' );
			$lang    = $manager->add( $data );
		} catch ( \InvalidArgumentException $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Polyglot] Invalid language data: ' . $e->getMessage() );
			}
			return new WP_Error(
				'polyglot_invalid_language_data',
				__( 'Invalid language data provided. Please check your input and try again.', 'novatools-polyglot' ),
				array( 'status' => 400 )
			);
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Polyglot] Language creation failed: ' . $e->getMessage() );
			}
			return new WP_Error(
				'polyglot_language_create_failed',
				__( 'An internal error occurred while creating the language. Please try again.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		$response = $this->prepareItemForResponse( $lang, $request );

		return new WP_REST_Response( $response, 201 );
	}

	/**
	 * Deactivate a language (soft delete).
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function deleteItem( WP_REST_Request $request ) {
		$code   = sanitize_text_field( $request->get_param( 'code' ) );
		$plugin = Plugin::getInstance();

		if ( ! $plugin->has( 'language.manager' ) ) {
			return new WP_Error(
				'polyglot_not_booted',
				__( 'Language manager is not available.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		/** @var \NovaTools\Polyglot\Language\LanguageManager $manager */
		$manager = $plugin->get( 'language.manager' );

		$deactivated = $manager->deactivate( $code );

		if ( ! $deactivated ) {
			return new WP_Error(
				'polyglot_language_deactivate_failed',
				sprintf(
					/* translators: %s: language code */
					__( 'Could not deactivate language "%s". It may be the default language or does not exist.', 'novatools-polyglot' ),
					$code
				),
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response(
			array(
				'deactivated' => true,
				'code'        => $code,
			),
			200
		);
	}

	/**
	 * Update a language's settings.
	 *
	 * Supports updating `is_default` and `sort_order`. When setting a
	 * new default, the previous default is unset atomically.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function updateItem( WP_REST_Request $request ) {
		$code   = sanitize_text_field( $request->get_param( 'code' ) );
		$plugin = Plugin::getInstance();

		if ( ! $plugin->has( 'language.manager' ) ) {
			return new WP_Error(
				'polyglot_not_booted',
				__( 'Language manager is not available.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		/** @var \NovaTools\Polyglot\Language\LanguageManager $manager */
		$manager = $plugin->get( 'language.manager' );

		/** @var \NovaTools\Polyglot\Language\LanguageRepository $repo */
		$repo = $plugin->get( 'language.repository' );

		$lang = $repo->getByCode( $code );

		if ( ! $lang ) {
			return new WP_Error(
				'polyglot_language_not_found',
				sprintf(
					/* translators: %s: language code */
					__( 'Language "%s" not found.', 'novatools-polyglot' ),
					$code
				),
				array( 'status' => 404 )
			);
		}

		$isDefault  = $request->get_param( 'is_default' );
		$sortOrder  = $request->get_param( 'sort_order' );

		// Handle setting as default language.
		if ( true === $isDefault ) {
			$success = $manager->setDefault( $code );

			if ( ! $success ) {
				return new WP_Error(
					'polyglot_set_default_failed',
					sprintf(
						/* translators: %s: language code */
						__( 'Failed to set "%s" as the default language.', 'novatools-polyglot' ),
						$code
					),
					array( 'status' => 500 )
				);
			}
		}

		// Handle sort_order update.
		if ( null !== $sortOrder ) {
			global $wpdb;
			$table = \NovaTools\Polyglot\Database\Schema::getTableName( 'polyglot_languages' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update(
				$table,
				array( 'sort_order' => absint( $sortOrder ) ),
				array( 'code' => $code )
			);
		}

		// Refresh and return the updated language.
		$lang = $repo->getByCode( $code );

		return new WP_REST_Response( $this->prepareItemForResponse( $lang, $request ), 200 );
	}

	/**
	 * Check if a given request has access to list languages.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return true|WP_Error
	 */
	public function getItemsPermissionsCheck( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'polyglot_rest_forbidden',
				__( 'Sorry, you are not allowed to view languages.', 'novatools-polyglot' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Check if a given request has access to create languages.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return true|WP_Error
	 */
	public function createItemPermissionsCheck( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'polyglot_rest_forbidden',
				__( 'Sorry, you are not allowed to add languages.', 'novatools-polyglot' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Check if a given request has access to deactivate languages.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return true|WP_Error
	 */
	public function deleteItemPermissionsCheck( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'polyglot_rest_forbidden',
				__( 'Sorry, you are not allowed to deactivate languages.', 'novatools-polyglot' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Check if a given request has access to update languages.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return true|WP_Error
	 */
	public function updateItemPermissionsCheck( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'polyglot_rest_forbidden',
				__( 'Sorry, you are not allowed to update languages.', 'novatools-polyglot' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Prepare a Language object for the REST response.
	 *
	 * Includes computed stats: content_percentage, strings_percentage,
	 * needs_update, and overall_percentage based on aggregate queries.
	 *
	 * @param \NovaTools\Polyglot\Language\Language $lang    Language value object.
	 * @param WP_REST_Request                        $request Request object.
	 * @return array Prepared data for the response.
	 */
	protected function prepareItemForResponse(
		\NovaTools\Polyglot\Language\Language $lang,
		WP_REST_Request $request
	): array {
		$stats = $this->computeLanguageStats( $lang->code );

		return array(
			'code'               => $lang->code,
			'locale'             => $lang->locale,
			'english_name'       => $lang->englishName,
			'native_name'        => $lang->nativeName,
			'is_active'          => $lang->isActive,
			'is_default'         => $lang->isDefault,
			'direction'          => $lang->direction,
			'flag_code'          => $lang->flagCode,
			'date_format'        => $lang->dateFormat,
			'time_format'        => $lang->timeFormat,
			'sort_order'         => $lang->sortOrder,
			'content_percentage' => $stats['content_percentage'],
			'strings_percentage' => $stats['strings_percentage'],
			'needs_update'       => $stats['needs_update'],
			'overall_percentage' => $stats['overall_percentage'],
		);
	}

	/**
	 * Compute translation statistics for a language.
	 *
	 * Runs aggregate queries against polyglot_translations and
	 * polyglot_string_translations to derive real percentages.
	 *
	 * @param string $language_code Language code to compute stats for.
	 * @return array{content_percentage: float, strings_percentage: float, needs_update: int, overall_percentage: float}
	 */
	private function computeLanguageStats( string $language_code ): array {
		global $wpdb;

		$translations_table = \NovaTools\Polyglot\Database\Schema::getTableName( 'polyglot_translations' );
		$string_translations_table = \NovaTools\Polyglot\Database\Schema::getTableName( 'polyglot_string_translations' );
		$posts_table = $wpdb->posts;

		// Get translatable post types.
		$option     = get_option( 'polyglot_settings', array() );
		$post_types = $option['post_types'] ?? array( 'post', 'page' );
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		// Total published content count.
		$total_content = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$posts_table} WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
				$post_types
			)
		);

		// Translated content count for this language.
		$translated_content = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$translations_table} t
				INNER JOIN {$posts_table} p ON t.element_id = p.ID AND BINARY t.element_type = BINARY CONCAT('post_', p.post_type)
				WHERE t.language_code = %s AND t.status IN ('translated', 'completed') AND p.post_status = 'publish' AND p.post_type IN ({$placeholders})",
				array_merge( array( $language_code ), $post_types )
			)
		);

		// Needs-update count for this language.
		$needs_update = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$translations_table} WHERE language_code = %s AND status = 'needs_update'",
				$language_code
			)
		);

		// Content percentage.
		$content_percentage = $total_content > 0 ? round( ( $translated_content / $total_content ) * 100, 1 ) : 0;

		// String translation stats.
		$total_strings = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM " . \NovaTools\Polyglot\Database\Schema::getTableName( 'polyglot_strings' )
		);

		$translated_strings = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$string_translations_table} WHERE language = %s AND status IN (1, 2)",
				$language_code
			)
		);

		$strings_percentage = $total_strings > 0 ? round( ( $translated_strings / $total_strings ) * 100, 1 ) : 0;

		// Overall percentage is the average of content and strings.
		$overall_percentage = round( ( $content_percentage + $strings_percentage ) / 2, 1 );

		return array(
			'content_percentage' => $content_percentage,
			'strings_percentage' => $strings_percentage,
			'needs_update'       => $needs_update,
			'overall_percentage' => $overall_percentage,
		);
	}

	/**
	 * Get the query parameters for the collection endpoint.
	 *
	 * @return array[]
	 */
	protected function getCollectionParams(): array {
		return array(
			'active_only' => array(
				'description' => __( 'Return only active languages.', 'novatools-polyglot' ),
				'type'        => 'boolean',
				'default'     => false,
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
			'code' => array(
				'description' => __( 'Short language code (e.g. "en", "fr").', 'novatools-polyglot' ),
				'type'        => 'string',
				'required'    => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ) {
					return is_string( $value ) && preg_match( '/^[a-z]{2,3}([_-][a-z]{2,4})?$/i', $value );
				},
			),
			'locale' => array(
				'description' => __( 'Full WordPress locale (e.g. "en_US", "fr_FR").', 'novatools-polyglot' ),
				'type'        => 'string',
				'required'    => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'english_name' => array(
				'description'       => __( 'Language name in English.', 'novatools-polyglot' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'native_name' => array(
				'description'       => __( 'Language name in its own script.', 'novatools-polyglot' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'direction' => array(
				'description'       => __( 'Text direction: "ltr" or "rtl".', 'novatools-polyglot' ),
				'type'              => 'string',
				'default'           => 'ltr',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'flag_code' => array(
				'description'       => __( 'ISO flag code.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date_format' => array(
				'description'       => __( 'PHP date format string.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'time_format' => array(
				'description'       => __( 'PHP time format string.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'sort_order' => array(
				'description' => __( 'Admin sort order (lower values first).', 'novatools-polyglot' ),
				'type'        => 'integer',
				'default'     => 0,
			),
		);
	}

	/**
	 * Get the arguments for the update-item endpoint.
	 *
	 * @return array[]
	 */
	protected function getUpdateItemArgs(): array {
		return array(
			'is_default' => array(
				'description' => __( 'Set this language as the site default.', 'novatools-polyglot' ),
				'type'        => 'boolean',
			),
			'sort_order' => array(
				'description' => __( 'Admin sort order (lower values first).', 'novatools-polyglot' ),
				'type'        => 'integer',
			),
		);
	}
}
