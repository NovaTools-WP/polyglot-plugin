<?php
/**
 * Version-based installer for NovaTools Polyglot.
 *
 * Compares the stored database version against the current plugin version
 * constant. Runs schema creation on first install and migration routines
 * when upgrades are detected. This is the main entry point called during
 * the plugins_loaded hook to handle version-driven upgrades.
 *
 * @package NovaTools\Polyglot\Core
 */

namespace NovaTools\Polyglot\Core;

use NovaTools\Polyglot\Database\Schema;
use NovaTools\Polyglot\Support\Cache;

defined( 'ABSPATH' ) || exit;

class Installer {

	/**
	 * WordPress option key used to track the installed database version.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'polyglot_db_version';

	/**
	 * Run the installer — compare versions and apply updates as needed.
	 *
	 * Should be called early in the plugin lifecycle (plugins_loaded).
	 *
	 * @return void
	 */
	public static function run(): void {
		$installed_version = get_option( static::VERSION_OPTION, '' );
		$current_version   = defined( 'NOVATOOLS_POLYGLOT_VERSION' )
			? NOVATOOLS_POLYGLOT_VERSION
			: '1.0.0';

		// Already at the latest version — nothing to do.
		if ( $installed_version === $current_version ) {
			return;
		}

		if ( '' === $installed_version ) {
			// Fresh install — create all tables and seed data.
			static::freshInstall();
		} else {
			// Upgrade — run version-based migrations.
			static::upgrade( $installed_version, $current_version );
		}

		// Stamp the new version.
		update_option( static::VERSION_OPTION, $current_version );

		// Clear caches after any schema change.
		static::flushCache();
	}

	/**
	 * Whether a fresh install is needed (no version stamped).
	 *
	 * @return bool
	 */
	public static function needsInstall(): bool {
		return '' === get_option( static::VERSION_OPTION, '' );
	}

	/**
	 * Handle a fresh installation.
	 *
	 * Creates all tables, seeds languages, and sets the default language.
	 * This is the single canonical entry point for initial setup —
	 * Activator::activate() delegates here via run().
	 *
	 * @return void
	 */
	private static function freshInstall(): void {
		Schema::create();

		Activator::seedLanguages();
		Activator::setDefaultLanguage();

		/**
		 * Fires after a fresh Polyglot installation.
		 */
		do_action( 'polyglot_fresh_install' );
	}

	/**
	 * Handle an upgrade from one version to another.
	 *
	 * Runs Schema::create() (which uses dbDelta for ALTER-safe updates)
	 * and then any version-specific migration routines.
	 *
	 * @param string $from Previous installed version.
	 * @param string $to   New target version.
	 * @return void
	 */
	private static function upgrade( string $from, string $to ): void {
		// dbDelta will apply any ALTER TABLE changes safely.
		Schema::create();

		// Run version-specific migrations in order.
		// Example: if ( version_compare( $from, '1.1.0', '<' ) ) { ... }
		// Add migration blocks here as the plugin evolves.

		/**
		 * Fires after a Polyglot version upgrade.
		 *
		 * @param string $from Previous version.
		 * @param string $to   New version.
		 */
		do_action( 'polyglot_upgraded', $from, $to );
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
}
