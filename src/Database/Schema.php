<?php
/**
 * Database schema definitions for NovaTools Polyglot.
 *
 * Consolidates WPML's 17 tables into 6 focused tables:
 *   - polyglot_languages          (merged icl_languages + icl_languages_translations + icl_flags + icl_locale_map)
 *   - polyglot_translations       (merged icl_translations + icl_translation_status)
 *   - polyglot_batches            (simplified icl_translation_batches)
 *   - polyglot_strings            (simplified icl_strings)
 *   - polyglot_string_translations (simplified icl_string_translations)
 *   - polyglot_string_packages    (simplified icl_string_packages)
 *
 * @package NovaTools\Polyglot\Database
 */

namespace NovaTools\Polyglot\Database;

defined( 'ABSPATH' ) || exit;

class Schema {

	/**
	 * Create (or update) all six custom tables.
	 *
	 * Uses dbDelta for forward-compatible schema management.
	 * Called during activation and version upgrades.
	 *
	 * @return void
	 */
	public static function create(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$tables          = static::getSchema( $charset_collate );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Return an array of CREATE TABLE SQL statements.
	 *
	 * Each entry is a complete CREATE TABLE string suitable for dbDelta().
	 *
	 * @param string $charset_collate Charset and collation clause from $wpdb.
	 * @return string[]
	 */
	public static function getSchema( string $charset_collate ): array {
		global $wpdb;

		$prefix = $wpdb->prefix;

		return array(
			// ── Languages ────────────────────────────────────────────────
			// Merges: icl_languages + icl_languages_translations + icl_flags + icl_locale_map
			"CREATE TABLE {$prefix}polyglot_languages (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				code VARCHAR(7) NOT NULL,
				locale VARCHAR(35) NOT NULL DEFAULT '',
				english_name VARCHAR(128) NOT NULL DEFAULT '',
				native_name VARCHAR(128) NOT NULL DEFAULT '',
				is_active TINYINT(1) NOT NULL DEFAULT 0,
				is_default TINYINT(1) NOT NULL DEFAULT 0,
				direction VARCHAR(3) NOT NULL DEFAULT 'ltr',
				flag_code VARCHAR(7) NOT NULL DEFAULT '',
				flag_url VARCHAR(255) NOT NULL DEFAULT '',
				date_format VARCHAR(64) NOT NULL DEFAULT '',
				time_format VARCHAR(64) NOT NULL DEFAULT '',
				sort_order INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				KEY is_active (is_active),
				KEY is_default (is_default)
			) {$charset_collate};",

			// ── Translations ─────────────────────────────────────────────
			// Merges: icl_translations + icl_translation_status
			"CREATE TABLE {$prefix}polyglot_translations (
				translation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				element_type VARCHAR(60) NOT NULL DEFAULT 'post_post',
				element_id BIGINT UNSIGNED NULL DEFAULT NULL,
				trid BIGINT UNSIGNED NOT NULL,
				language_code VARCHAR(7) NOT NULL,
				source_language_code VARCHAR(7) NOT NULL DEFAULT '',
				status ENUM('not_translated','in_progress','translated','needs_update','awaiting_review','completed') NOT NULL DEFAULT 'not_translated',
				checksum VARCHAR(32) NOT NULL DEFAULT '',
				translator_id BIGINT UNSIGNED NULL DEFAULT NULL,
				batch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				translation_service VARCHAR(32) NOT NULL DEFAULT '',
				translated_at DATETIME NULL DEFAULT NULL,
				PRIMARY KEY  (translation_id),
				UNIQUE KEY el_type_id (element_type, element_id),
				UNIQUE KEY trid_lang (trid, language_code),
				KEY trid (trid),
				KEY id_type_language (element_id, element_type, language_code),
				KEY status (status),
				KEY batch_id (batch_id)
			) {$charset_collate};",

			// ── Batches ──────────────────────────────────────────────────
			// Simplified: icl_translation_batches
			"CREATE TABLE {$prefix}polyglot_batches (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				batch_name VARCHAR(255) NOT NULL DEFAULT '',
				provider VARCHAR(32) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				last_update DATETIME NULL DEFAULT NULL,
				PRIMARY KEY  (id)
			) {$charset_collate};",

			// ── Strings ──────────────────────────────────────────────────
			// Simplified: icl_strings
			"CREATE TABLE {$prefix}polyglot_strings (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				domain VARCHAR(160) NOT NULL DEFAULT '',
				context TEXT NOT NULL,
				name VARCHAR(160) NOT NULL DEFAULT '',
				value LONGTEXT NOT NULL,
				hash VARCHAR(32) NOT NULL DEFAULT '',
				package_id BIGINT UNSIGNED NULL DEFAULT NULL,
				type VARCHAR(40) NOT NULL DEFAULT 'LINE',
				title VARCHAR(160) NULL DEFAULT NULL,
				status TINYINT NOT NULL DEFAULT 0,
				translation_priority VARCHAR(160) NOT NULL DEFAULT 'optional',
				word_count INT UNSIGNED NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uc_hash (hash),
				KEY domain (domain),
				KEY domain_name (domain, name),
				KEY package_id (package_id),
				KEY status (status)
			) {$charset_collate};",

			// ── String Translations ──────────────────────────────────────
			// Simplified: icl_string_translations
			"CREATE TABLE {$prefix}polyglot_string_translations (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				string_id BIGINT UNSIGNED NOT NULL,
				language VARCHAR(10) NOT NULL,
				status TINYINT NOT NULL DEFAULT 0,
				value LONGTEXT NULL,
				translator_id BIGINT UNSIGNED NULL DEFAULT NULL,
				translation_service VARCHAR(32) NOT NULL DEFAULT '',
				batch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				translated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY string_language (string_id, language),
				KEY language (language),
				KEY status (status)
			) {$charset_collate};",

			// ── String Packages ──────────────────────────────────────────
			// Simplified: icl_string_packages
			"CREATE TABLE {$prefix}polyglot_string_packages (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				kind VARCHAR(32) NOT NULL DEFAULT '',
				kind_slug VARCHAR(160) NOT NULL DEFAULT '',
				name VARCHAR(160) NOT NULL DEFAULT '',
				title VARCHAR(255) NOT NULL DEFAULT '',
				description TEXT NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY kind_kind_slug_name (kind, kind_slug, name),
				KEY kind_slug (kind_slug)
			) {$charset_collate};",
		);
	}

	/**
	 * Return the list of all Polyglot table names (without prefix).
	 *
	 * @return string[]
	 */
	public static function getTableNames(): array {
		return array(
			'polyglot_languages',
			'polyglot_translations',
			'polyglot_batches',
			'polyglot_strings',
			'polyglot_string_translations',
			'polyglot_string_packages',
		);
	}

	/**
	 * Return the full prefixed table name for a given short name.
	 *
	 * @param string $table Short table name (e.g. 'polyglot_languages').
	 * @return string Fully-prefixed table name.
	 */
	public static function getTableName( string $table ): string {
		global $wpdb;

		return $wpdb->prefix . $table;
	}
}
