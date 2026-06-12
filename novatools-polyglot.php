<?php
/**
 * Plugin Name: NovaTools - Polyglot
 * Description: Comprehensive multilingual add-on for NovaTools — language management, content translation, string translation, PO/MO editing, WooCommerce multilingual, and WPML migration.
 * Plugin URI: https://wordpress.org/plugins/novatools-polyglot/
 * Author: Siim Liimand
 * License: GPLv2 or later
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Text Domain: novatools-polyglot
 * Domain Path: /languages
 * Requires Plugins: novatools
 *
 * @package NovaTools\Polyglot
 */

defined( 'ABSPATH' ) || exit;

use NovaTools\Polyglot\Core\Activator;
use NovaTools\Polyglot\Core\Deactivator;
use NovaTools\Polyglot\Core\Installer;
use NovaTools\Polyglot\Compatibility\DependencyCheck;

// Preload PSR-11 interfaces BEFORE any other autoloader can provide a
// different version.  Pimple 3.5 declares get(string $id) which requires
// PSR Container 2.0; if another autoloader loads the 1.x interface first
// (get($id) without type) PHP will fatal.  Loading these directly ensures
// the correct 2.0 signatures are in memory first.
if ( ! interface_exists( 'Psr\Container\ContainerInterface', false ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'vendor/psr/container/src/ContainerExceptionInterface.php';
	require_once plugin_dir_path( __FILE__ ) . 'vendor/psr/container/src/NotFoundExceptionInterface.php';
	require_once plugin_dir_path( __FILE__ ) . 'vendor/psr/container/src/ContainerInterface.php';
}

require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
require_once plugin_dir_path( __FILE__ ) . 'libs/assets.php';
require_once plugin_dir_path( __FILE__ ) . 'plugin.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/functions.php';

// Register activation and deactivation hooks.
register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

/**
 * Initializes the NovaTools Polyglot add-on when plugins are loaded.
 *
 * Runs the version-based installer, then boots the plugin and checks
 * the NovaTools dependency status.
 *
 * @since 1.0.0
 * @return void
 */
function novatools_polyglot_init() {
	// Run schema migrations / fresh install on version change.
	Installer::run();

	NovaToolsPolyglot::get_instance()->init();

	// Load translations early so __() calls registered at plugins_loaded
	// (e.g. register_admin_routes, register_submenu_pages) don't trigger
	// the WP 6.7 _load_textdomain_just_in_time notice.
	load_plugin_textdomain(
		'novatools-polyglot',
		false,
		dirname( plugin_basename( NOVATOOLS_POLYGLOT_PLUGIN_FILE ) ) . '/languages/'
	);

	if ( ! DependencyCheck::is_novatools_active() ) {
		add_action( 'admin_notices', array( DependencyCheck::class, 'admin_notice' ) );
	}
}

add_action( 'plugins_loaded', 'novatools_polyglot_init', 1 );

/**
 * Add settings link to the plugin action links.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'novatools_polyglot_add_settings_link' );
function novatools_polyglot_add_settings_link( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		admin_url( 'admin.php?page=novatools#/polyglot' ),
		esc_html__( 'Settings', 'novatools-polyglot' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}

