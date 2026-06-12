<?php
/**
 * REST API controller for plugin settings.
 *
 * Registers the `/polyglot/v1/settings` route with endpoints for retrieving
 * and updating plugin settings stored in the `polyglot_settings` option.
 *
 * @package NovaTools\Polyglot\RestApi
 */

namespace NovaTools\Polyglot\RestApi;

use NovaTools\Polyglot\Support\OptionStore;
use NovaTools\Polyglot\Core\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

class SettingsController {

	const NAMESPACE = 'polyglot/v1';

	const REST_BASE = 'settings';

	/**
	 * Register the routes for this controller.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getItems' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'updateItems' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
				),
			)
		);
	}

	/**
	 * Retrieve all settings.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response
	 */
	public function getItems( WP_REST_Request $request ): WP_REST_Response {
		$store = new OptionStore();

		return new WP_REST_Response( $store->all(), 200 );
	}

	/**
	 * Update settings by merging the request body into existing settings.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function updateItems( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'polyglot_invalid_nonce',
				__( 'Invalid nonce.', 'novatools-polyglot' ),
				array( 'status' => 403 )
			);
		}

		$settings = $request->get_json_params();

		if ( ! is_array( $settings ) ) {
			return new WP_Error(
				'polyglot_invalid_settings',
				__( 'Invalid settings data.', 'novatools-polyglot' ),
				array( 'status' => 400 )
			);
		}

		$plugin = Plugin::getInstance();
		if ( $plugin->has( 'admin.menu_registrar' ) ) {
			$registrar = $plugin->get( 'admin.menu_registrar' );
			$settings  = $registrar->sanitizeSettings( $settings );
		}

		$store  = new OptionStore();
		$store->merge( $settings );

		return new WP_REST_Response( $store->all(), 200 );
	}

	/**
	 * Check if the current user has access to manage settings.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return true|WP_Error
	 */
	public function permissionsCheck( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'polyglot_rest_forbidden',
				__( 'Sorry, you are not allowed to manage settings.', 'novatools-polyglot' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}
}
