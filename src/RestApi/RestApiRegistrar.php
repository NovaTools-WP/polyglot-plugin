<?php
/**
 * REST API registrar for NovaTools Polyglot.
 *
 * Registers all REST API controllers on the `rest_api_init` hook.
 * Each controller's routes are scoped under `/polyglot/v1/` and
 * require the `manage_options` capability for all endpoints.
 *
 * @package NovaTools\Polyglot\RestApi
 */

namespace NovaTools\Polyglot\RestApi;

use NovaTools\Polyglot\Core\Plugin;

defined( 'ABSPATH' ) || exit;

class RestApiRegistrar {

	/**
	 * Register all REST API controllers.
	 *
	 * Called on the `rest_api_init` action. Instantiates each controller
	 * and delegates route registration.
	 *
	 * @return void
	 */
	public function register(): void {
		$controllers = array(
			new LanguagesController(),
			new TranslationsController(),
			new ContentController(),
			new StringsController(),
			new AutoTranslateController(),
			new SettingsController(),
		);

		$plugin = Plugin::getInstance();

		if ( $plugin->has( 'scan.controller' ) ) {
			$controllers[] = $plugin->get( 'scan.controller' );
		}

		foreach ( $controllers as $controller ) {
			$controller->registerRoutes();
		}
	}

	/**
	 * Hook the registrar into WordPress.
	 *
	 * Should be called during plugin boot to attach the `rest_api_init`
	 * callback.
	 *
	 * @return void
	 */
	public function hook(): void {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}
}
