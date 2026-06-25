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
use NovaTools\Polyglot\Language\FlagResolver;
use NovaTools\Polyglot\Traits\FlushesCache;
use NovaTools\Polyglot\WooCommerce\Currency\ExchangeRateService;

defined( 'ABSPATH' ) || exit;

class Installer {

	use FlushesCache;

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

		// Move exchange-rate cron scheduling from init hook to activation.
		if ( version_compare( $from, '1.0.1', '<' ) ) {
			ExchangeRateService::scheduleOnActivation();
		}

		// Correct language flag codes that defaulted to the language code,
		// which produced wrong flags (e.g. Estonian "et" → Ethiopia "ET").
		if ( version_compare( $from, '1.0.2', '<' ) ) {
			static::normalizeFlagCodes();
		}

		/**
		 * Fires after a Polyglot version upgrade.
		 *
		 * @param string $from Previous version.
		 * @param string $to   New version.
		 */
		do_action( 'polyglot_upgraded', $from, $to );
	}

	/**
	 * Normalize language flag codes after the move to FlagResolver.
	 *
	 * Previously `flag_code` defaulted to the language code, which produced
	 * wrong flags (e.g. Estonian "et" was treated as country "ET" / Ethiopia).
	 * This rewrites every row whose flag_code still equals its language code
	 * (or is empty) to the correct ISO country code. Rows carrying a custom
	 * flag_code that differs from the language code are left untouched.
	 *
	 * @return void
	 */
	private static function normalizeFlagCodes(): void {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_languages' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT code, flag_code FROM {$table}", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$code      = $row['code'];
			$flag_code = $row['flag_code'] ?? '';

			// Preserve any explicit override; only fix the stale default
			// (flag_code == code) and empty values.
			if ( '' !== $flag_code && strtolower( $flag_code ) !== strtolower( $code ) ) {
				continue;
			}

			$resolved = FlagResolver::countryCode( $code );

			if ( '' === $resolved || strtoupper( $resolved ) === strtoupper( $flag_code ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, array( 'flag_code' => $resolved ), array( 'code' => $code ) );
		}
	}

}
