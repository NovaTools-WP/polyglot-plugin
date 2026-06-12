<?php
/**
 * Plugin deactivation handler for NovaTools Polyglot.
 *
 * Flushes all Polyglot object-cache entries and clears any scheduled
 * cron events. Database tables and settings are preserved — they are
 * only removed during uninstall (see uninstall.php).
 *
 * @package NovaTools\Polyglot\Core
 */

namespace NovaTools\Polyglot\Core;

use NovaTools\Polyglot\Support\Cache;

defined( 'ABSPATH' ) || exit;

class Deactivator {

	/**
	 * Cron hook names managed by the Polyglot plugin.
	 *
	 * These are registered as tasks are implemented (post sync, exchange
	 * rate updates, string cache cleanup). Listing them here upfront
	 * ensures deactivation cleanly removes any scheduled events even
	 * if only a subset of features are active.
	 *
	 * @var string[]
	 */
	private const CRON_HOOKS = array(
		'polyglot_sync_post_checksums',
		'polyglot_exchange_rate_update',
		'polyglot_cleanup_string_cache',
	);

	/**
	 * Run all deactivation routines.
	 *
	 * Called by the register_deactivation_hook() in the main plugin file.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		static::flushCache();
		static::clearScheduledEvents();
		static::flushRewriteRules();

		/**
		 * Fires after the Polyglot plugin has been deactivated.
		 */
		do_action( 'polyglot_deactivated' );
	}

	/**
	 * Flush all Polyglot object-cache entries.
	 *
	 * @return void
	 */
	private static function flushCache(): void {
		$cache = new Cache();
		$cache->flushGroup();
	}

	/**
	 * Clear all scheduled cron events registered by the plugin.
	 *
	 * @return void
	 */
	private static function clearScheduledEvents(): void {
		foreach ( static::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Flush WordPress rewrite rules so URL routing changes take effect.
	 *
	 * @return void
	 */
	private static function flushRewriteRules(): void {
		flush_rewrite_rules( false );
	}
}
