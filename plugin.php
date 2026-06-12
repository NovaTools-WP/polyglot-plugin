<?php
/**
 * Main plugin class for NovaTools Polyglot.
 *
 * Bootstraps the plugin, registers admin integration with NovaTools
 * (or standalone mode), and initializes all core services via the
 * DI container.
 *
 * @package NovaTools\Polyglot
 */

use NovaTools\Polyglot\Assets\Admin as AssetsAdmin;
use NovaTools\Polyglot\Cli\FileCommand;
use NovaTools\Polyglot\Cli\LanguageCommand;
use NovaTools\Polyglot\Cli\StringCommand;
use NovaTools\Polyglot\Cli\TranslationCommand;
use NovaTools\Polyglot\Core\Plugin;
use NovaTools\Polyglot\Core\ServiceProvider;
use NovaTools\Polyglot\Traits\Base;

defined( 'ABSPATH' ) || exit;

/**
 * Final class NovaToolsPolyglot
 *
 * Entrypoint class following the NovaTools addon pattern.
 * Uses the Base trait singleton via get_instance().
 */
final class NovaToolsPolyglot {

	use Base;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = '1.0.0';

	/**
	 * Constructor — define plugin constants.
	 *
	 * Constants are defined here (not in init) so they are available
	 * the moment the singleton is created, matching the SEO addon pattern.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		define( 'NOVATOOLS_POLYGLOT_VERSION', self::VERSION );
		define( 'NOVATOOLS_POLYGLOT_PLUGIN_FILE', dirname( __FILE__ ) . '/novatools-polyglot.php' );
		define( 'NOVATOOLS_POLYGLOT_DIR', plugin_dir_path( __FILE__ ) );
		define( 'NOVATOOLS_POLYGLOT_URL', plugin_dir_url( __FILE__ ) );
		define( 'NOVATOOLS_POLYGLOT_ASSETS_URL', NOVATOOLS_POLYGLOT_URL . 'assets' );
		define( 'NOVATOOLS_POLYGLOT_ROUTE_PREFIX', 'novatools-polyglot/v1' );
	}

	/**
	 * Initialise the plugin.
	 *
	 * Called from the `plugins_loaded` hook (priority 1) in the main
	 * plugin file. Builds the DI container, boots core services, and
	 * registers the admin integration layer.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		$this->init_container();
		$this->init_hooks();
	}

	/**
	 * Build the Pimple DI container with all service registrations.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function init_container() {
		$plugin = Plugin::getInstance();
		$plugin->setServiceProvider( new ServiceProvider() );
	}

	/**
	 * Register WordPress hooks for admin integration and WP-CLI.
	 *
	 * Dual-mode: registers NovaTools addon routes when NovaTools is
	 * present, otherwise falls back to standalone admin menus.
	 *
	 * Also registers WP-CLI commands when running in a CLI context.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function init_hooks() {
		$plugin = Plugin::getInstance();
		$plugin->boot();

		if ( is_admin() ) {
			add_filter( 'novatools_admin_routes', array( $this, 'register_admin_routes' ) );
			add_filter( 'novatools_submenu_pages', array( $this, 'register_submenu_pages' ) );
			AssetsAdmin::get_instance()->bootstrap();
		}

		// Register WP-CLI commands when WP-CLI is available.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'cli_init', array( $this, 'register_cli_commands' ) );
		}

		// Load WPML API compatibility shim at late priority so WPML
		// has a chance to define its own functions first.
		add_action( 'plugins_loaded', array( $this, 'load_wpml_api_shim' ), 100 );
	}

	/**
	 * Register WP-CLI commands for Polyglot.
	 *
	 * Called on the `cli_init` hook. Adds four subcommands under
	 * the `wp polyglot` namespace: language, translation, string, file.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_cli_commands(): void {
		\WP_CLI::add_command( 'polyglot language', LanguageCommand::class );
		\WP_CLI::add_command( 'polyglot translation', TranslationCommand::class );
		\WP_CLI::add_command( 'polyglot string', StringCommand::class );
		\WP_CLI::add_command( 'polyglot file', FileCommand::class );
	}

		/**
		 * Load the WPML API compatibility shim.
		 *
		 * Defines drop-in replacements for common WPML API functions
		 * (icl_object_id, icl_t, etc.) when WPML is not active.
		 * During migration, WPML's own functions take precedence.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function load_wpml_api_shim(): void {
			\NovaTools\Polyglot\Compatibility\WpmlApiShim::load();
		}

	/**
	 * Script handle for the Polyglot admin add-on.
	 *
	 * @var string
	 */
	const ADDON_SCRIPT_HANDLE = 'novatools-polyglot-addon';

	/**
	 * Register admin routes for the NovaTools React shell.
	 *
	 * Only called when NovaTools core is active (and therefore this
	 * plugin is installed). Returns an array of route definitions
	 * consumed by the NovaTools admin SPA.
	 *
	 * @param array $routes Existing routes from other addons.
	 * @return array Modified routes with Polyglot entries appended.
	 */
	public function register_admin_routes( array $routes ): array {
		$routes[] = array(
			'addonId'      => 'novatools-polyglot',
			'path'         => 'polyglot',
			'component'    => 'PolyglotDashboard',
			'navLabel'     => __( 'Polyglot', 'novatools-polyglot' ),
			'icon'         => 'Globe',
			'scriptHandle' => self::ADDON_SCRIPT_HANDLE,
		);

		// Sub-routes for each Polyglot section.
		$sub_routes = array(
			array( 'path' => 'polyglot/languages',        'component' => 'Languages' ),
			array( 'path' => 'polyglot/translations',     'component' => 'Translations' ),
			array( 'path' => 'polyglot/string-translation', 'component' => 'StringTranslation' ),
			array( 'path' => 'polyglot/scan',             'component' => 'Scan' ),
			array( 'path' => 'polyglot/settings',         'component' => 'PolyglotSettings' ),
			array( 'path' => 'polyglot/import-wpml',      'component' => 'ImportWpml' ),
		);

		foreach ( $sub_routes as $sub ) {
			$routes[] = array(
				'addonId'      => 'novatools-polyglot',
				'path'         => $sub['path'],
				'component'    => $sub['component'],
				'navLabel'     => '',
				'icon'         => '',
				'scriptHandle' => self::ADDON_SCRIPT_HANDLE,
				'parent'       => 'polyglot',
			);
		}

		return $routes;
	}

	/**
	 * Register submenu pages for the NovaTools admin menu.
	 *
	 * Only called when NovaTools core is active (and therefore this
	 * plugin is installed). Returns an array of submenu page
	 * definitions consumed by the NovaTools menu system.
	 *
	 * @param array $pages Existing submenu pages from other addons.
	 * @return array Modified pages with Polyglot entries appended.
	 */
	public function register_submenu_pages( array $pages ): array {
		$plugin_url = admin_url( '/admin.php?page=novatools' );

		$pages[] = array(
			'parent_slug' => 'novatools',
			'page_title'  => __( 'Polyglot', 'novatools-polyglot' ),
			'menu_title'  => __( 'Polyglot', 'novatools-polyglot' ),
			'capability'  => 'manage_options',
			'menu_slug'   => $plugin_url . '#/polyglot',
			'function'    => null,
		);

		return $pages;
	}

}
