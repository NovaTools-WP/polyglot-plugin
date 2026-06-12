<?php
/**
 * Module interface for NovaTools Polyglot.
 *
 * All optional modules (WooCommerce, etc.) must implement this interface.
 * Modules are lazy-loaded: they are only instantiated when isActive()
 * returns true, keeping zero overhead for unused features.
 *
 * @package NovaTools\Polyglot\Core
 */

namespace NovaTools\Polyglot\Core;

defined( 'ABSPATH' ) || exit;

interface ModuleInterface {

	/**
	 * Register the module's hooks, filters, and shortcodes.
	 *
	 * Called once during plugin boot when isActive() has returned true.
	 *
	 * @return void
	 */
	public function register(): void;

	/**
	 * Whether this module should be loaded.
	 *
	 * Perform lightweight checks here (e.g. class_exists, option check).
	 * Heavy initialisation belongs in register(), not here.
	 *
	 * @return bool True if the module should be activated.
	 */
	public function isActive(): bool;

	/**
	 * Return identifiers of modules that must be active before this one.
	 *
	 * Return an empty array if the module has no dependencies.
	 *
	 * @return string[] List of module identifiers this module depends on.
	 */
	public function getDependencies(): array;
}
