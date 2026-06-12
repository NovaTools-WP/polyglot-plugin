<?php
/**
 * Locale mapper for NovaTools Polyglot.
 *
 * Provides bidirectional mapping between WordPress locale identifiers
 * (e.g. "fr_FR", "de_DE") and short language codes (e.g. "fr", "de").
 * Used during URL resolution, language detection, and WPML migration.
 *
 * The canonical source of truth is the `polyglot_languages` table, but
 * this class also ships a static fallback map for contexts where the
 * database is not yet available (e.g. early bootstrap).
 *
 * @package NovaTools\Polyglot\Language
 */

namespace NovaTools\Polyglot\Language;

use NovaTools\Polyglot\Database\Schema;
use NovaTools\Polyglot\Support\Cache;

defined( 'ABSPATH' ) || exit;

class LocaleMapper {

	/**
	 * Cache wrapper instance.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Constructor.
	 *
	 * @param Cache $cache The polyglot cache wrapper.
	 */
	public function __construct( Cache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Map a WordPress locale to a short language code.
	 *
	 * Queries the database first. If no match is found, falls back to
	 * the static map, then to simple substring extraction.
	 *
	 * @param string $locale Full WordPress locale (e.g. "fr_FR").
	 * @return string Short language code (e.g. "fr"), or the locale unchanged.
	 */
	public function localeToCode( string $locale ): string {
		// Try the database-backed map first.
		$map = $this->getLocaleMap();

		if ( isset( $map[ $locale ] ) ) {
			return $map[ $locale ];
		}

		// Try the static fallback.
		$static = static::getStaticMap();

		if ( isset( $static[ $locale ] ) ) {
			return $static[ $locale ];
		}

		// Last resort: derive the code from the locale (e.g. "fr_FR" → "fr").
		if ( str_contains( $locale, '_' ) ) {
			return strtolower( substr( $locale, 0, strpos( $locale, '_' ) ) );
		}

		return strtolower( $locale );
	}

	/**
	 * Map a short language code to a WordPress locale.
	 *
	 * Queries the database first. If no match is found, falls back to
	 * the static map.
	 *
	 * @param string $code Short language code (e.g. "fr").
	 * @return string WordPress locale (e.g. "fr_FR"), or the code unchanged.
	 */
	public function codeToLocale( string $code ): string {
		$reverse = $this->getReverseMap();

		if ( isset( $reverse[ $code ] ) ) {
			return $reverse[ $code ];
		}

		$staticReverse = static::getStaticReverseMap();

		if ( isset( $staticReverse[ $code ] ) ) {
			return $staticReverse[ $code ];
		}

		return $code;
	}

	/**
	 * Check whether a string is a valid WordPress locale format.
	 *
	 * A valid locale contains only lowercase letters, digits, underscores,
	 * and hyphens (e.g. "en_US", "zh_CN", "sr_RS").
	 *
	 * @param string $locale String to test.
	 * @return bool
	 */
	public function isValidLocale( string $locale ): bool {
		return (bool) preg_match( '/^[a-z]{2,3}([_-][a-z]{2,4})?$/i', $locale );
	}

	/**
	 * Get the locale → code map from the database, cached.
	 *
	 * @return array<string, string> Map of locale → code.
	 */
	private function getLocaleMap(): array {
		$key    = $this->cache->key( 'locale_map', 'forward' );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = Schema::getTableName( 'polyglot_languages' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			"SELECT locale, code FROM {$table} WHERE locale != ''",
			ARRAY_A
		);

		$map = array();

		foreach ( $rows as $row ) {
			$map[ $row['locale'] ] = $row['code'];
		}

		$this->cache->set( $key, $map );

		return $map;
	}

	/**
	 * Get the code → locale map (reverse of getLocaleMap).
	 *
	 * @return array<string, string> Map of code → locale.
	 */
	private function getReverseMap(): array {
		$key    = $this->cache->key( 'locale_map', 'reverse' );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		$map = array_flip( $this->getLocaleMap() );
		$this->cache->set( $key, $map );

		return $map;
	}

	/**
	 * Static fallback map of common WordPress locales to language codes.
	 *
	 * Used when the database is not yet available (e.g. during early
	 * activation or in the WP-CLI bootstrap phase).
	 *
	 * @return array<string, string>
	 */
	public static function getStaticMap(): array {
		return array(
			'af'          => 'af',
			'am'          => 'am',
			'ar'          => 'ar',
			'ary'         => 'ary',
			'as'          => 'as',
			'az'          => 'az',
			'bel'         => 'bel',
			'bg_BG'       => 'bg',
			'bn_BD'       => 'bn',
			'bs_BA'       => 'bs',
			'ca'          => 'ca',
			'ckb'         => 'ckb',
			'cs_CZ'       => 'cs',
			'cy'          => 'cy',
			'da_DK'       => 'da',
			'de_DE'       => 'de',
			'de_CH'       => 'de_CH',
			'el'          => 'el',
			'en_US'       => 'en',
			'en_AU'       => 'en_AU',
			'en_CA'       => 'en_CA',
			'en_GB'       => 'en_GB',
			'en_NZ'       => 'en_NZ',
			'en_ZA'       => 'en_ZA',
			'eo'          => 'eo',
			'es_ES'       => 'es',
			'es_AR'       => 'es_AR',
			'es_CL'       => 'es_CL',
			'es_CO'       => 'es_CO',
			'es_MX'       => 'es_MX',
			'es_PE'       => 'es_PE',
			'es_VE'       => 'es_VE',
			'et'          => 'et',
			'eu'          => 'eu',
			'fa_IR'       => 'fa',
			'fi'          => 'fi',
			'fr_FR'       => 'fr',
			'fr_BE'       => 'fr_BE',
			'fr_CA'       => 'fr_CA',
			'fr_CH'       => 'fr_CH',
			'gd'          => 'gd',
			'gl_ES'       => 'gl',
			'he_IL'       => 'he',
			'hi_IN'       => 'hi',
			'hr'          => 'hr',
			'hu_HU'       => 'hu',
			'hy'          => 'hy',
			'id_ID'       => 'id',
			'is_IS'       => 'is',
			'it_IT'       => 'it',
			'ja'          => 'ja',
			'ka_GE'       => 'ka',
			'kk'          => 'kk',
			'km'          => 'km',
			'ko_KR'       => 'ko',
			'lo'          => 'lo',
			'lt_LT'       => 'lt',
			'lv'          => 'lv',
			'mk_MK'       => 'mk',
			'ml_IN'       => 'ml',
			'mn'          => 'mn',
			'ms_MY'       => 'ms',
			'my_MM'       => 'my',
			'nb_NO'       => 'nb',
			'ne_NP'       => 'ne',
			'nl_NL'       => 'nl',
			'nl_BE'       => 'nl_BE',
			'nn_NO'       => 'nn',
			'pl_PL'       => 'pl',
			'ps'          => 'ps',
			'pt_PT'       => 'pt',
			'pt_AO'       => 'pt_AO',
			'pt_BR'       => 'pt_BR',
			'ro_RO'       => 'ro',
			'ru_RU'       => 'ru',
			'si_LK'       => 'si',
			'sk_SK'       => 'sk',
			'sl_SI'       => 'sl',
			'sq'          => 'sq',
			'sr_RS'       => 'sr',
			'sv_SE'       => 'sv',
			'sw'          => 'sw',
			'ta_IN'       => 'ta',
			'ta_LK'       => 'ta_LK',
			'te'          => 'te',
			'th'          => 'th',
			'tl'          => 'tl',
			'tr_TR'       => 'tr',
			'uk'          => 'uk',
			'ur'          => 'ur',
			'ug_CN'       => 'ug',
			'uz_UZ'       => 'uz',
			'vi'          => 'vi',
			'zh_CN'       => 'zh',
			'zh_HK'       => 'zh_HK',
			'zh_TW'       => 'zh_TW',
		);
	}

	/**
	 * Static reverse map: code → locale.
	 *
	 * @return array<string, string>
	 */
	public static function getStaticReverseMap(): array {
		return array_flip( static::getStaticMap() );
	}
}
