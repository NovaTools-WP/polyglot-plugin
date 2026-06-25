<?php
/**
 * Plugin activation handler for NovaTools Polyglot.
 *
 * Delegates schema creation and data seeding to the Installer for
 * version-based lifecycle management. Sets initial plugin settings
 * on first activation.
 *
 * @package NovaTools\Polyglot\Core
 */

namespace NovaTools\Polyglot\Core;

use NovaTools\Polyglot\Database\Schema;
use NovaTools\Polyglot\Language\FlagResolver;
use NovaTools\Polyglot\TranslationApi\OpenAIProvider;
use NovaTools\Polyglot\WooCommerce\Currency\ExchangeRateService;

defined('ABSPATH') || exit;

class Activator
{

    /**
     * Run all activation routines.
     *
     * Delegates to Installer::run() which handles schema creation,
     * language seeding (fresh installs), and version-based upgrades.
     *
     * Called by the register_activation_hook() in the main plugin file.
     *
     * @return void
     */
    public static function activate(): void
    {
        if (! class_exists('NovaTools') ) {
            wp_die(
                esc_html__('NovaTools - Polyglot requires the NovaTools core plugin to be installed and active. Please activate NovaTools first.', 'novatools-polyglot'),
                esc_html__('Plugin Dependency Error', 'novatools-polyglot'),
                array( 'back_link' => true )
            );
        }

        // Run version-based installer (schema, seeding, upgrades).
        Installer::run();

        // Set initial plugin settings on first activation.
        static::setInitialSettings();

        // Schedule exchange-rate cron event (avoids checking on every init).
        ExchangeRateService::scheduleOnActivation();

        /**
         * Fires after the Polyglot plugin has been activated.
         */
        do_action('polyglot_activated');
    }

    /**
     * Seed the polyglot_languages table with WordPress-supported languages.
     *
     * Only inserts languages that are not already present (idempotent).
     * Called by Installer::freshInstall() during the initial setup.
     *
     * @return void
     */
    public static function seedLanguages(): void
    {
        global $wpdb;

        $table  = Schema::getTableName('polyglot_languages');
        $languages = static::getWpLanguages();

     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing_codes = $wpdb->get_col("SELECT code FROM {$table}");
        if (! is_array($existing_codes) ) {
            $existing_codes = array();
        }

        $existing_codes_map = array_flip($existing_codes);

        foreach ( $languages as $lang ) {
            if (isset($existing_codes_map[ $lang['code'] ]) ) {
                continue;
            }

         // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert(
                $table, array(
                'code'          => $lang['code'],
                'locale'        => $lang['locale'],
                'english_name'  => $lang['english_name'],
                'native_name'   => $lang['native_name'],
                'is_active'     => $lang['is_active'] ?? 0,
                'is_default'    => 0,
                'direction'     => $lang['direction'] ?? 'ltr',
                'flag_code'     => $lang['flag_code'] ?? ( FlagResolver::countryCode( $lang['code'] ) ?: $lang['code'] ),
                'flag_url'      => '',
                'date_format'   => $lang['date_format'] ?? '',
                'time_format'   => $lang['time_format'] ?? '',
                'sort_order'    => $lang['sort_order'] ?? 0,
                )
            );
        }
    }

    /**
     * Set the current site language as the default and mark it active.
     *
     * Uses a three-tier fallback: match by short code → match by locale →
     * fall back to English.
     *
     * Called by Installer::freshInstall() during the initial setup.
     *
     * @return void
     */
    public static function setDefaultLanguage(): void
    {
        global $wpdb;

        $table  = Schema::getTableName('polyglot_languages');
        $locale = get_locale();

        // Derive the short language code from the locale (e.g. "fr_FR" -> "fr").
        $code = strtolower(substr($locale, 0, 2));

        // Intentionally clear all defaults before setting the new one
        // so that at most one row has is_default = 1.
     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            "UPDATE {$table} SET is_default = 0"
        );

     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET is_default = 1, is_active = 1 WHERE code = %s",
                $code
            )
        );

        // If the language code wasn't found, try matching by locale directly.
        if (! $updated ) {
         // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET is_default = 1, is_active = 1 WHERE locale = %s",
                    $locale
                )
            );
        }

        // If still nothing matched, fall back to English.
     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $has_default = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_default = 1");

        if (! $has_default ) {
         // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query(
                "UPDATE {$table} SET is_default = 1, is_active = 1 WHERE code = 'en' LIMIT 1"
            );
        }
    }

    /**
     * Set initial plugin settings on first activation.
     *
     * @return void
     */
    private static function setInitialSettings(): void
    {
        // Only set if no settings exist yet (first activation).
        if (false === get_option('polyglot_settings') ) {
            // Derive the default language code from the current site locale.
            $locale       = get_locale();
            $default_lang = strtolower(substr($locale, 0, 2));

            add_option(
                'polyglot_settings', array(
                'url_strategy'                 => 'directory',
                'hide_default_language_prefix' => true,
                'browser_language_redirect'    => false,
                'default_language'             => $default_lang,
                'custom_fields'                => array(),
                'post_types'                   => array(),
                'taxonomies'                   => array(),
                'auto_scan_on_activation'      => true,
                'api'                          => array(
                'deepl'  => array( 'key' => '', 'tier' => 'free' ),
                'google' => array( 'key' => '' ),
				'openai' => array( 'key' => '', 'model' => OpenAIProvider::DEFAULT_MODEL ),
                ),
                )
            );
        }
    }

    /**
     * Return the list of WordPress-supported languages for seeding.
     *
     * Loads language definitions from data/languages.json which covers
     * all languages that have official WordPress locale packages.
     *
     * @return array[] Array of language definition arrays.
     */
    public static function getWpLanguages(): array
    {
        $file = dirname( __DIR__, 2 ) . '/data/languages.json';
        $json = file_get_contents( $file );

        if ( false === $json ) {
            return array();
        }

        $languages = json_decode( $json, true );

        return is_array( $languages ) ? $languages : array();
    }
}
