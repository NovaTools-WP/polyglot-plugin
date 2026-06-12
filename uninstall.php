<?php
/**
 * Uninstall handler for NovaTools Polyglot.
 *
 * Drops all 6 custom polyglot_* tables and removes the polyglot_settings
 * option when the plugin is uninstalled via WordPress admin.
 *
 * @package NovaTools\Polyglot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Drop all custom tables.
$tables = array(
	'polyglot_languages',
	'polyglot_translations',
	'polyglot_batches',
	'polyglot_strings',
	'polyglot_string_translations',
	'polyglot_string_packages',
);

foreach ( $tables as $table ) {
	$table_name = $wpdb->prefix . $table;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
}

// Remove main settings option.
delete_option( 'polyglot_settings' );

// Remove version stamp used by the installer.
delete_option( 'polyglot_db_version' );

// Clear any scheduled cron events.
wp_clear_scheduled_hook( 'polyglot_sync_post_checksums' );
wp_clear_scheduled_hook( 'polyglot_exchange_rate_update' );
wp_clear_scheduled_hook( 'polyglot_cleanup_string_cache' );

// Flush the WordPress object cache for our group.
wp_cache_flush_group( 'polyglot' );
