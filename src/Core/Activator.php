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
                'flag_code'     => $lang['flag_code'] ?? $lang['code'],
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
                'openai' => array( 'key' => '', 'model' => 'gpt-4o-mini' ),
                ),
                )
            );
        }
    }

    /**
     * Return the list of WordPress-supported languages for seeding.
     *
     * Covers all languages that have official WordPress locale packages.
     * Entries are ordered alphabetically by code.
     *
     * @return array[] Array of language definition arrays.
     */
    public static function getWpLanguages(): array
    {
        return array(
        array( 'code' => 'af',    'locale' => 'af',          'english_name' => 'Afrikaans',              'native_name' => 'Afrikaans',              'direction' => 'ltr' ),
        array( 'code' => 'am',    'locale' => 'am',          'english_name' => 'Amharic',                'native_name' => 'አማርኛ',                   'direction' => 'ltr' ),
        array( 'code' => 'ar',    'locale' => 'ar',          'english_name' => 'Arabic',                 'native_name' => 'العربية',                 'direction' => 'rtl' ),
        array( 'code' => 'ary',   'locale' => 'ary',         'english_name' => 'Moroccan Arabic',        'native_name' => 'العربية المغربية',        'direction' => 'rtl' ),
        array( 'code' => 'as',    'locale' => 'as',          'english_name' => 'Assamese',               'native_name' => 'অসমীয়া',                   'direction' => 'ltr' ),
        array( 'code' => 'az',    'locale' => 'az',          'english_name' => 'Azerbaijani',            'native_name' => 'Azərbaycan dili',         'direction' => 'ltr' ),
        array( 'code' => 'bel',   'locale' => 'bel',         'english_name' => 'Belarusian',             'native_name' => 'Беларуская мова',          'direction' => 'ltr' ),
        array( 'code' => 'bho',   'locale' => 'bho',         'english_name' => 'Bhojpuri',               'native_name' => 'भोजपुरी',                    'direction' => 'ltr' ),
        array( 'code' => 'bg',    'locale' => 'bg_BG',       'english_name' => 'Bulgarian',              'native_name' => 'Български',                'direction' => 'ltr' ),
        array( 'code' => 'bn',    'locale' => 'bn_BD',       'english_name' => 'Bengali',                'native_name' => 'বাংলা',                     'direction' => 'ltr' ),
        array( 'code' => 'bo',    'locale' => 'bo',          'english_name' => 'Tibetan',                'native_name' => 'བོད་ཡིག',                   'direction' => 'ltr' ),
        array( 'code' => 'bre',   'locale' => 'bre',         'english_name' => 'Breton',                 'native_name' => 'Brezhoneg',                'direction' => 'ltr' ),
        array( 'code' => 'bs',    'locale' => 'bs_BA',       'english_name' => 'Bosnian',                'native_name' => 'Bosanski',                 'direction' => 'ltr' ),
        array( 'code' => 'ca',    'locale' => 'ca',          'english_name' => 'Catalan',                'native_name' => 'Català',                   'direction' => 'ltr' ),
        array( 'code' => 'ceb',   'locale' => 'ceb',         'english_name' => 'Cebuano',                'native_name' => 'Cebuano',                  'direction' => 'ltr' ),
        array( 'code' => 'ckb',   'locale' => 'ckb',         'english_name' => 'Kurdish (Sorani)',       'native_name' => 'كوردی',                    'direction' => 'rtl' ),
        array( 'code' => 'cs',    'locale' => 'cs_CZ',       'english_name' => 'Czech',                  'native_name' => 'Čeština',                  'direction' => 'ltr' ),
        array( 'code' => 'cy',    'locale' => 'cy',          'english_name' => 'Welsh',                  'native_name' => 'Cymraeg',                  'direction' => 'ltr' ),
        array( 'code' => 'da',    'locale' => 'da_DK',       'english_name' => 'Danish',                 'native_name' => 'Dansk',                    'direction' => 'ltr' ),
        array( 'code' => 'de',    'locale' => 'de_DE',       'english_name' => 'German',                 'native_name' => 'Deutsch',                  'direction' => 'ltr' ),
        array( 'code' => 'de_CH', 'locale' => 'de_CH',       'english_name' => 'German (Switzerland)',   'native_name' => 'Deutsch (Schweiz)',         'direction' => 'ltr' ),
        array( 'code' => 'dsb',   'locale' => 'dsb',         'english_name' => 'Lower Sorbian',          'native_name' => 'Dolnoserbšćina',            'direction' => 'ltr' ),
        array( 'code' => 'dv',    'locale' => 'dv',          'english_name' => 'Dhivehi',                'native_name' => 'ދިވެހި',                     'direction' => 'rtl' ),
        array( 'code' => 'el',    'locale' => 'el',          'english_name' => 'Greek',                  'native_name' => 'Ελληνικά',                  'direction' => 'ltr' ),
        array( 'code' => 'en',    'locale' => 'en_US',       'english_name' => 'English',                'native_name' => 'English',                  'direction' => 'ltr' ),
        array( 'code' => 'en_AU', 'locale' => 'en_AU',       'english_name' => 'English (Australia)',    'native_name' => 'English (Australia)',       'direction' => 'ltr' ),
        array( 'code' => 'en_CA', 'locale' => 'en_CA',       'english_name' => 'English (Canada)',       'native_name' => 'English (Canada)',          'direction' => 'ltr' ),
        array( 'code' => 'en_GB', 'locale' => 'en_GB',       'english_name' => 'English (UK)',           'native_name' => 'English (UK)',              'direction' => 'ltr' ),
        array( 'code' => 'en_NZ', 'locale' => 'en_NZ',       'english_name' => 'English (New Zealand)',  'native_name' => 'English (New Zealand)',     'direction' => 'ltr' ),
        array( 'code' => 'en_ZA', 'locale' => 'en_ZA',       'english_name' => 'English (South Africa)', 'native_name' => 'English (South Africa)',    'direction' => 'ltr' ),
        array( 'code' => 'eo',    'locale' => 'eo',          'english_name' => 'Esperanto',              'native_name' => 'Esperanto',                 'direction' => 'ltr' ),
        array( 'code' => 'es',    'locale' => 'es_ES',       'english_name' => 'Spanish',                'native_name' => 'Español',                   'direction' => 'ltr' ),
        array( 'code' => 'es_AR', 'locale' => 'es_AR',       'english_name' => 'Spanish (Argentina)',    'native_name' => 'Español de Argentina',      'direction' => 'ltr' ),
        array( 'code' => 'es_CL', 'locale' => 'es_CL',       'english_name' => 'Spanish (Chile)',        'native_name' => 'Español de Chile',          'direction' => 'ltr' ),
        array( 'code' => 'es_CO', 'locale' => 'es_CO',       'english_name' => 'Spanish (Colombia)',     'native_name' => 'Español de Colombia',       'direction' => 'ltr' ),
        array( 'code' => 'es_MX', 'locale' => 'es_MX',       'english_name' => 'Spanish (Mexico)',       'native_name' => 'Español de México',         'direction' => 'ltr' ),
        array( 'code' => 'es_PE', 'locale' => 'es_PE',       'english_name' => 'Spanish (Peru)',         'native_name' => 'Español de Perú',           'direction' => 'ltr' ),
        array( 'code' => 'es_VE', 'locale' => 'es_VE',       'english_name' => 'Spanish (Venezuela)',    'native_name' => 'Español de Venezuela',      'direction' => 'ltr' ),
        array( 'code' => 'et',    'locale' => 'et',          'english_name' => 'Estonian',               'native_name' => 'Eesti',                     'direction' => 'ltr' ),
        array( 'code' => 'eu',    'locale' => 'eu',          'english_name' => 'Basque',                 'native_name' => 'Euskara',                   'direction' => 'ltr' ),
        array( 'code' => 'fa',    'locale' => 'fa_IR',       'english_name' => 'Persian',                'native_name' => 'فارسی',                     'direction' => 'rtl' ),
        array( 'code' => 'fi',    'locale' => 'fi',          'english_name' => 'Finnish',                'native_name' => 'Suomi',                     'direction' => 'ltr' ),
        array( 'code' => 'fil',   'locale' => 'fil',         'english_name' => 'Filipino',               'native_name' => 'Filipino',                  'direction' => 'ltr' ),
        array( 'code' => 'fo',    'locale' => 'fo',          'english_name' => 'Faroese',                'native_name' => 'Føroyskt',                  'direction' => 'ltr' ),
        array( 'code' => 'fr',    'locale' => 'fr_FR',       'english_name' => 'French',                 'native_name' => 'Français',                  'direction' => 'ltr' ),
        array( 'code' => 'fr_BE', 'locale' => 'fr_BE',       'english_name' => 'French (Belgium)',       'native_name' => 'Français de Belgique',      'direction' => 'ltr' ),
        array( 'code' => 'fr_CA', 'locale' => 'fr_CA',       'english_name' => 'French (Canada)',        'native_name' => 'Français du Canada',        'direction' => 'ltr' ),
        array( 'code' => 'fr_CH', 'locale' => 'fr_CH',       'english_name' => 'French (Switzerland)',   'native_name' => 'Français de Suisse',        'direction' => 'ltr' ),
        array( 'code' => 'fur',   'locale' => 'fur',         'english_name' => 'Friulian',               'native_name' => 'Furlan',                    'direction' => 'ltr' ),
        array( 'code' => 'gd',    'locale' => 'gd',          'english_name' => 'Scottish Gaelic',        'native_name' => 'Gàidhlig',                   'direction' => 'ltr' ),
        array( 'code' => 'gl',    'locale' => 'gl_ES',       'english_name' => 'Galician',               'native_name' => 'Galego',                    'direction' => 'ltr' ),
        array( 'code' => 'gsw',   'locale' => 'gsw',         'english_name' => 'Swiss German',           'native_name' => 'Schwyzerdütsch',            'direction' => 'ltr' ),
        array( 'code' => 'gu',    'locale' => 'gu',          'english_name' => 'Gujarati',               'native_name' => 'ગુજરાતી',                     'direction' => 'ltr' ),
        array( 'code' => 'hat',   'locale' => 'hat',         'english_name' => 'Haitian Creole',         'native_name' => 'Kreyòl ayisyen',            'direction' => 'ltr' ),
        array( 'code' => 'haw',   'locale' => 'haw',         'english_name' => 'Hawaiian',               'native_name' => 'ʻŌlelo Hawaiʻi',            'direction' => 'ltr' ),
        array( 'code' => 'haz',   'locale' => 'haz',         'english_name' => 'Hazaragi',               'native_name' => 'هزاره گی',                  'direction' => 'rtl' ),
        array( 'code' => 'he',    'locale' => 'he_IL',       'english_name' => 'Hebrew',                 'native_name' => 'עִבְרִית',                    'direction' => 'rtl' ),
        array( 'code' => 'hi',    'locale' => 'hi_IN',       'english_name' => 'Hindi',                  'native_name' => 'हिन्दी',                      'direction' => 'ltr' ),
        array( 'code' => 'hr',    'locale' => 'hr',          'english_name' => 'Croatian',               'native_name' => 'Hrvatski',                  'direction' => 'ltr' ),
        array( 'code' => 'hsb',   'locale' => 'hsb',         'english_name' => 'Upper Sorbian',          'native_name' => 'Hornjoserbsce',             'direction' => 'ltr' ),
        array( 'code' => 'hrx',   'locale' => 'hrx',         'english_name' => 'Hunsrik',                'native_name' => 'Hunsrik',                   'direction' => 'ltr' ),
        array( 'code' => 'hu',    'locale' => 'hu_HU',       'english_name' => 'Hungarian',              'native_name' => 'Magyar',                    'direction' => 'ltr' ),
        array( 'code' => 'hy',    'locale' => 'hy',          'english_name' => 'Armenian',               'native_name' => 'Հայերեն',                    'direction' => 'ltr' ),
        array( 'code' => 'iba',   'locale' => 'iba',         'english_name' => 'Iban',                   'native_name' => 'Iban',                      'direction' => 'ltr' ),
        array( 'code' => 'id',    'locale' => 'id_ID',       'english_name' => 'Indonesian',             'native_name' => 'Bahasa Indonesia',           'direction' => 'ltr' ),
        array( 'code' => 'is',    'locale' => 'is_IS',       'english_name' => 'Icelandic',              'native_name' => 'Íslenska',                   'direction' => 'ltr' ),
        array( 'code' => 'it',    'locale' => 'it_IT',       'english_name' => 'Italian',                'native_name' => 'Italiano',                   'direction' => 'ltr' ),
        array( 'code' => 'ja',    'locale' => 'ja',          'english_name' => 'Japanese',               'native_name' => '日本語',                      'direction' => 'ltr' ),
        array( 'code' => 'jv',    'locale' => 'jv_ID',       'english_name' => 'Javanese',               'native_name' => 'Basa Jawa',                  'direction' => 'ltr' ),
        array( 'code' => 'ka',    'locale' => 'ka_GE',       'english_name' => 'Georgian',               'native_name' => 'ქართული',                     'direction' => 'ltr' ),
        array( 'code' => 'kab',   'locale' => 'kab',         'english_name' => 'Kabyle',                 'native_name' => 'Taqbaylit',                  'direction' => 'ltr' ),
        array( 'code' => 'kin',   'locale' => 'kin',         'english_name' => 'Kinyarwanda',            'native_name' => 'Ikinyarwanda',               'direction' => 'ltr' ),
        array( 'code' => 'kk',    'locale' => 'kk',          'english_name' => 'Kazakh',                 'native_name' => 'Қазақ тілі',                  'direction' => 'ltr' ),
        array( 'code' => 'km',    'locale' => 'km',          'english_name' => 'Khmer',                  'native_name' => 'ភាសាខ្មែរ',                      'direction' => 'ltr' ),
        array( 'code' => 'kn',    'locale' => 'kn',          'english_name' => 'Kannada',                'native_name' => 'ಕನ್ನಡ',                        'direction' => 'ltr' ),
        array( 'code' => 'ko',    'locale' => 'ko_KR',       'english_name' => 'Korean',                 'native_name' => '한국어',                       'direction' => 'ltr' ),
        array( 'code' => 'lij',   'locale' => 'lij',         'english_name' => 'Ligurian',               'native_name' => 'Lìgure',                    'direction' => 'ltr' ),
        array( 'code' => 'lo',    'locale' => 'lo',          'english_name' => 'Lao',                    'native_name' => 'ພາສາລາວ',                     'direction' => 'ltr' ),
        array( 'code' => 'lt',    'locale' => 'lt_LT',       'english_name' => 'Lithuanian',             'native_name' => 'Lietuvių kalba',              'direction' => 'ltr' ),
        array( 'code' => 'lmo',   'locale' => 'lmo',         'english_name' => 'Lombard',                'native_name' => 'Lombard',                   'direction' => 'ltr' ),
        array( 'code' => 'lv',    'locale' => 'lv',          'english_name' => 'Latvian',                'native_name' => 'Latviešu valoda',             'direction' => 'ltr' ),
        array( 'code' => 'mai',   'locale' => 'mai',         'english_name' => 'Maithili',               'native_name' => 'मैथिली',                       'direction' => 'ltr' ),
        array( 'code' => 'mfe',   'locale' => 'mfe',         'english_name' => 'Mauritian Creole',       'native_name' => 'Morisyen',                  'direction' => 'ltr' ),
        array( 'code' => 'mk',    'locale' => 'mk_MK',       'english_name' => 'Macedonian',             'native_name' => 'Македонски јазик',            'direction' => 'ltr' ),
        array( 'code' => 'ml',    'locale' => 'ml_IN',       'english_name' => 'Malayalam',              'native_name' => 'മലയാളം',                       'direction' => 'ltr' ),
        array( 'code' => 'mn',    'locale' => 'mn',          'english_name' => 'Mongolian',              'native_name' => 'Монгол',                      'direction' => 'ltr' ),
        array( 'code' => 'mr',    'locale' => 'mr',          'english_name' => 'Marathi',                'native_name' => 'मराठी',                        'direction' => 'ltr' ),
        array( 'code' => 'ms',    'locale' => 'ms_MY',       'english_name' => 'Malay',                  'native_name' => 'Bahasa Melayu',               'direction' => 'ltr' ),
        array( 'code' => 'my',    'locale' => 'my_MM',       'english_name' => 'Myanmar (Burmese)',      'native_name' => 'ဗမာစာ',                          'direction' => 'ltr' ),
        array( 'code' => 'nb',    'locale' => 'nb_NO',       'english_name' => 'Norwegian (Bokmål)',     'native_name' => 'Norsk bokmål',                'direction' => 'ltr' ),
        array( 'code' => 'ne',    'locale' => 'ne_NP',       'english_name' => 'Nepali',                 'native_name' => 'नेपाली',                        'direction' => 'ltr' ),
        array( 'code' => 'nl',    'locale' => 'nl_NL',       'english_name' => 'Dutch',                  'native_name' => 'Nederlands',                  'direction' => 'ltr' ),
        array( 'code' => 'nl_BE', 'locale' => 'nl_BE',       'english_name' => 'Dutch (Belgium)',        'native_name' => 'Nederlands (België)',          'direction' => 'ltr' ),
        array( 'code' => 'nn',    'locale' => 'nn_NO',       'english_name' => 'Norwegian (Nynorsk)',    'native_name' => 'Norsk nynorsk',               'direction' => 'ltr' ),
        array( 'code' => 'nqo',   'locale' => 'nqo',         'english_name' => 'N\'Ko',                  'native_name' => 'ߒߞߏ',                        'direction' => 'rtl' ),
        array( 'code' => 'oci',   'locale' => 'oci',         'english_name' => 'Occitan',                'native_name' => 'Occitan',                     'direction' => 'ltr' ),
        array( 'code' => 'pa',    'locale' => 'pa_IN',       'english_name' => 'Punjabi',                'native_name' => 'ਪੰਜਾਬੀ',                        'direction' => 'ltr' ),
        array( 'code' => 'pap',   'locale' => 'pap',         'english_name' => 'Papiamento',             'native_name' => 'Papiamentu',                 'direction' => 'ltr' ),
        array( 'code' => 'pcm',   'locale' => 'pcm',         'english_name' => 'Nigerian Pidgin',        'native_name' => 'Naijá',                      'direction' => 'ltr' ),
        array( 'code' => 'pl',    'locale' => 'pl_PL',       'english_name' => 'Polish',                 'native_name' => 'Polski',                      'direction' => 'ltr' ),
        array( 'code' => 'ps',    'locale' => 'ps',          'english_name' => 'Pashto',                 'native_name' => 'پښتو',                        'direction' => 'rtl' ),
        array( 'code' => 'pt',    'locale' => 'pt_PT',       'english_name' => 'Portuguese',             'native_name' => 'Português',                   'direction' => 'ltr' ),
        array( 'code' => 'pt_AO', 'locale' => 'pt_AO',       'english_name' => 'Portuguese (Angola)',    'native_name' => 'Português de Angola',         'direction' => 'ltr' ),
        array( 'code' => 'pt_BR', 'locale' => 'pt_BR',       'english_name' => 'Portuguese (Brazil)',    'native_name' => 'Português do Brasil',         'direction' => 'ltr' ),
        array( 'code' => 'rhg',   'locale' => 'rhg',         'english_name' => 'Rohingya',               'native_name' => 'Ruáinga',                    'direction' => 'rtl' ),
        array( 'code' => 'ro',    'locale' => 'ro_RO',       'english_name' => 'Romanian',               'native_name' => 'Română',                      'direction' => 'ltr' ),
        array( 'code' => 'roh',   'locale' => 'roh',         'english_name' => 'Romansh',                'native_name' => 'Rumantsch',                  'direction' => 'ltr' ),
        array( 'code' => 'ru',    'locale' => 'ru_RU',       'english_name' => 'Russian',                'native_name' => 'Русский',                     'direction' => 'ltr' ),
        array( 'code' => 'rue',   'locale' => 'rue',         'english_name' => 'Rusyn',                  'native_name' => 'Русиньскый',                 'direction' => 'ltr' ),
        array( 'code' => 'sah',   'locale' => 'sah',         'english_name' => 'Sakha',                  'native_name' => 'Саха тыла',                   'direction' => 'ltr' ),
        array( 'code' => 'sd',    'locale' => 'sd_PK',       'english_name' => 'Sindhi',                 'native_name' => 'سنڌي',                        'direction' => 'rtl' ),
        array( 'code' => 'si',    'locale' => 'si_LK',       'english_name' => 'Sinhala',                'native_name' => 'සිංහල',                         'direction' => 'ltr' ),
        array( 'code' => 'sk',    'locale' => 'sk_SK',       'english_name' => 'Slovak',                 'native_name' => 'Slovenčina',                  'direction' => 'ltr' ),
        array( 'code' => 'skr',   'locale' => 'skr',         'english_name' => 'Saraiki',                'native_name' => 'سرائیکی',                     'direction' => 'rtl' ),
        array( 'code' => 'sl',    'locale' => 'sl_SI',       'english_name' => 'Slovenian',              'native_name' => 'Slovenščina',                 'direction' => 'ltr' ),
        array( 'code' => 'sli',   'locale' => 'sli',         'english_name' => 'Silesian',               'native_name' => 'Ślōnskŏ gŏdka',              'direction' => 'ltr' ),
        array( 'code' => 'som',   'locale' => 'som',         'english_name' => 'Somali',                 'native_name' => 'Soomaali',                   'direction' => 'ltr' ),
        array( 'code' => 'sq',    'locale' => 'sq',          'english_name' => 'Albanian',               'native_name' => 'Shqip',                       'direction' => 'ltr' ),
        array( 'code' => 'srd',   'locale' => 'srd',         'english_name' => 'Sardinian',              'native_name' => 'Sardu',                      'direction' => 'ltr' ),
        array( 'code' => 'sr',    'locale' => 'sr_RS',       'english_name' => 'Serbian',                'native_name' => 'Српски језик',                'direction' => 'ltr' ),
        array( 'code' => 'scn',   'locale' => 'scn',         'english_name' => 'Sicilian',               'native_name' => 'Sicilianu',                  'direction' => 'ltr' ),
        array( 'code' => 'sv',    'locale' => 'sv_SE',       'english_name' => 'Swedish',                'native_name' => 'Svenska',                     'direction' => 'ltr' ),
        array( 'code' => 'sw',    'locale' => 'sw',          'english_name' => 'Swahili',                'native_name' => 'Kiswahili',                   'direction' => 'ltr' ),
        array( 'code' => 'ta',    'locale' => 'ta_IN',       'english_name' => 'Tamil',                  'native_name' => 'தமிழ்',                         'direction' => 'ltr' ),
        array( 'code' => 'ta_LK', 'locale' => 'ta_LK',       'english_name' => 'Tamil (Sri Lanka)',      'native_name' => 'தமிழ் (இலங்கை)',                  'direction' => 'ltr' ),
        array( 'code' => 'tah',   'locale' => 'tah',         'english_name' => 'Tahitian',               'native_name' => 'Reo Tahiti',                 'direction' => 'ltr' ),
        array( 'code' => 'tat',   'locale' => 'tat',         'english_name' => 'Tatar',                  'native_name' => 'Татар теле',                 'direction' => 'ltr' ),
        array( 'code' => 'te',    'locale' => 'te',          'english_name' => 'Telugu',                 'native_name' => 'తెలుగు',                         'direction' => 'ltr' ),
        array( 'code' => 'th',    'locale' => 'th',          'english_name' => 'Thai',                   'native_name' => 'ไทย',                          'direction' => 'ltr' ),
        array( 'code' => 'tl',    'locale' => 'tl',          'english_name' => 'Tagalog',                'native_name' => 'Tagalog',                     'direction' => 'ltr' ),
        array( 'code' => 'tr',    'locale' => 'tr_TR',       'english_name' => 'Turkish',                'native_name' => 'Türkçe',                      'direction' => 'ltr' ),
        array( 'code' => 'tuk',   'locale' => 'tuk',         'english_name' => 'Turkmen',                'native_name' => 'Türkmençe',                  'direction' => 'ltr' ),
        array( 'code' => 'tzm',   'locale' => 'tzm',         'english_name' => 'Central Atlas Tamazight','native_name' => 'ⵜⴰⵎⴰⵣⵉⵖⵜ',                  'direction' => 'ltr' ),
        array( 'code' => 'ug',    'locale' => 'ug_CN',       'english_name' => 'Uyghur',                 'native_name' => 'ئۇيغۇرچە',                     'direction' => 'rtl' ),
        array( 'code' => 'uk',    'locale' => 'uk',          'english_name' => 'Ukrainian',              'native_name' => 'Українська',                   'direction' => 'ltr' ),
        array( 'code' => 'ur',    'locale' => 'ur',          'english_name' => 'Urdu',                   'native_name' => 'اردو',                         'direction' => 'rtl' ),
        array( 'code' => 'uz',    'locale' => 'uz_UZ',       'english_name' => 'Uzbek',                  'native_name' => 'Oʻzbekcha',                    'direction' => 'ltr' ),
        array( 'code' => 'vec',   'locale' => 'vec',         'english_name' => 'Venetian',               'native_name' => 'Vèneto',                     'direction' => 'ltr' ),
        array( 'code' => 'vi',    'locale' => 'vi',          'english_name' => 'Vietnamese',             'native_name' => 'Tiếng Việt',                   'direction' => 'ltr' ),
        array( 'code' => 'xho',   'locale' => 'xho',         'english_name' => 'Xhosa',                  'native_name' => 'isiXhosa',                   'direction' => 'ltr' ),
        array( 'code' => 'yid',   'locale' => 'yid',         'english_name' => 'Yiddish',                'native_name' => 'ייִדיש',                      'direction' => 'rtl' ),
        array( 'code' => 'zgh',   'locale' => 'zgh',         'english_name' => 'Standard Moroccan Tamazight', 'native_name' => 'ⵜⴰⵎⴰⵣⵉⵖⵜ ⵜⴰⵏⴰⵡⴰⵏⵜ',       'direction' => 'ltr' ),
        array( 'code' => 'zh',    'locale' => 'zh_CN',       'english_name' => 'Chinese (Simplified)',   'native_name' => '简体中文',                       'direction' => 'ltr' ),
        array( 'code' => 'zh_HK', 'locale' => 'zh_HK',       'english_name' => 'Chinese (Hong Kong)',    'native_name' => '香港中文版',                      'direction' => 'ltr' ),
        array( 'code' => 'zh_TW', 'locale' => 'zh_TW',       'english_name' => 'Chinese (Traditional)',  'native_name' => '繁體中文',                       'direction' => 'ltr' ),
        array( 'code' => 'zul',   'locale' => 'zul',         'english_name' => 'Zulu',                   'native_name' => 'isiZulu',                    'direction' => 'ltr' ),
        );
    }
}
