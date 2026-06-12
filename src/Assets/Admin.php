<?php
/**
 * Admin asset loader for NovaTools Polyglot.
 *
 * Registers the addon script (novatools-polyglot-addon) so the
 * NovaTools React shell can discover and render Polyglot components.
 *
 * @package NovaTools\Polyglot\Assets
 */

namespace NovaTools\Polyglot\Assets;

use NovaTools\Polyglot\Compatibility\DependencyCheck;
use NovaTools\Polyglot\Traits\Base;
use NovaTools\Polyglot\Libs\Assets;

defined( 'ABSPATH' ) || exit;

class Admin {

	use Base;

	/**
	 * Script handle for the Polyglot add-on bundle.
	 *
	 * @var string
	 */
	const ADDON_HANDLE = 'novatools-polyglot-addon';

	/**
	 * Dev-mode entry point relative to the Vite source root.
	 *
	 * @var string
	 */
	const DEV_SCRIPT_ADDON = 'src/admin/addon-entry.jsx';

	/**
	 * Boot the asset loader.
	 *
	 * When NovaTools core is active the add-on script is registered (not
	 * enqueued) so the main NovaTools shell can load it on demand.
	 * In standalone mode a full enqueue is performed instead.
	 *
	 * @return void
	 */
	public function bootstrap() {
		if ( DependencyCheck::is_novatools_active() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'register_addon_script' ), 9 );
		}
	}

	/**
	 * Register (not enqueue) the add-on script.
	 *
	 * The NovaTools main plugin will wp_enqueue_script() this handle
	 * when it detects the novatools-polyglot scriptHandle in the routes.
	 *
	 * @return void
	 */
	public function register_addon_script() {
		Assets\enqueue_asset(
			NOVATOOLS_POLYGLOT_DIR . '/assets/admin/dist',
			self::DEV_SCRIPT_ADDON,
			$this->get_addon_config()
		);

		wp_localize_script( self::ADDON_HANDLE, 'novaToolsPolyglot', $this->get_data() );
	}

	/**
	 * Vite/WordPress script configuration for the add-on bundle.
	 *
	 * @return array
	 */
	public function get_addon_config(): array {
		return array(
			'dependencies' => array( 'react', 'react-dom' ),
			'handle'       => self::ADDON_HANDLE,
			'in-footer'    => true,
		);
	}

	/**
	 * Data passed to the add-on script via wp_localize_script.
	 *
	 * @return array
	 */
	public function get_data(): array {
		return array(
			'apiUrl'         => rest_url(),
			'siteUrl'        => home_url( '/' ),
			'isAdmin'        => is_admin(),
			'version'        => NOVATOOLS_POLYGLOT_VERSION,
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'hasWooCommerce' => class_exists( 'WooCommerce' ),
			'plugins'        => $this->get_installed_plugins(),
			'themes'         => $this->get_installed_themes(),
		);
	}

	private function get_installed_plugins(): array {
		$plugins = array();
		$all     = get_plugins();

		foreach ( $all as $file => $data ) {
			$slug = dirname( $file );
			if ( '.' === $slug ) {
				$slug = basename( $file, '.php' );
			}

			$plugins[] = array(
				'slug' => $slug,
				'name' => $data['Name'] ?? $slug,
				'file' => $file,
			);
		}

		return $plugins;
	}

	private function get_installed_themes(): array {
		$themes = array();
		$all    = wp_get_themes();

		foreach ( $all as $theme ) {
			$themes[] = array(
				'slug' => $theme->get_stylesheet(),
				'name' => $theme->get( 'Name' ),
			);
		}

		return $themes;
	}
}
