<?php
/**
 * Flag resolver for NovaTools Polyglot.
 *
 * Maps language codes to their representative ISO 3166-1 alpha-2 country
 * code (the "flag code") and renders that country as a flag emoji.
 *
 * Language codes are NOT country codes, even though they often look alike.
 * Treating a language code as a country code is what produced wrong flags
 * in the admin bar — e.g. Estonian ("et") rendered as 🇪🇹 Ethiopia (ISO
 * country "ET") instead of 🇪🇪 Estonia ("EE"). This class holds the
 * canonical language → country mapping so the correct flag is shown for
 * every language.
 *
 * Compound codes (e.g. "de_CH", "pt_BR", "zh_HK") already encode their
 * country in the suffix and are resolved from there; only base language
 * codes need to be listed explicitly.
 *
 * Stateless languages (e.g. Esperanto) have no country and fall back to a
 * neutral globe glyph.
 *
 * @package NovaTools\Polyglot\Language
 */

namespace NovaTools\Polyglot\Language;

defined( 'ABSPATH' ) || exit;

class FlagResolver {

	/**
	 * Map of base language code → ISO 3166-1 alpha-2 country code.
	 *
	 * Intentionally omits compound codes (de_CH, pt_BR, zh_HK, …): those
	 * carry their country in the suffix and are resolved automatically.
	 *
	 * Each value is the country whose flag best represents the language,
	 * not a strict one-to-one linguistic mapping (e.g. "en" → "US",
	 * "ar" → "SA", "sw" → "KE").
	 *
	 * @var array<string, string>
	 */
	private const COUNTRY_MAP = array(
		'af'  => 'ZA', // Afrikaans        → South Africa
		'am'  => 'ET', // Amharic          → Ethiopia
		'ar'  => 'SA', // Arabic           → Saudi Arabia
		'ary' => 'MA', // Moroccan Arabic  → Morocco
		'as'  => 'IN', // Assamese         → India
		'az'  => 'AZ', // Azerbaijani      → Azerbaijan
		'bel' => 'BY', // Belarusian       → Belarus
		'bho' => 'IN', // Bhojpuri         → India
		'bg'  => 'BG', // Bulgarian        → Bulgaria
		'bn'  => 'BD', // Bengali          → Bangladesh
		'bo'  => 'CN', // Tibetan          → China
		'bre' => 'FR', // Breton           → France
		'bs'  => 'BA', // Bosnian          → Bosnia & Herzegovina
		'ca'  => 'ES', // Catalan          → Spain
		'ceb' => 'PH', // Cebuano          → Philippines
		'ckb' => 'IQ', // Kurdish (Sorani) → Iraq
		'cs'  => 'CZ', // Czech            → Czechia
		'cy'  => 'GB', // Welsh            → United Kingdom
		'da'  => 'DK', // Danish           → Denmark
		'de'  => 'DE', // German           → Germany
		'dsb' => 'DE', // Lower Sorbian    → Germany
		'dv'  => 'MV', // Dhivehi          → Maldives
		'el'  => 'GR', // Greek            → Greece
		'en'  => 'US', // English          → United States (locale en_US)
		'es'  => 'ES', // Spanish          → Spain
		'et'  => 'EE', // Estonian         → Estonia
		'eu'  => 'ES', // Basque           → Spain
		'fa'  => 'IR', // Persian          → Iran
		'fi'  => 'FI', // Finnish          → Finland
		'fil' => 'PH', // Filipino         → Philippines
		'fo'  => 'FO', // Faroese          → Faroe Islands
		'fr'  => 'FR', // French           → France
		'fur' => 'IT', // Friulian         → Italy
		'gd'  => 'GB', // Scottish Gaelic  → United Kingdom
		'gl'  => 'ES', // Galician         → Spain
		'gsw' => 'CH', // Swiss German     → Switzerland
		'gu'  => 'IN', // Gujarati         → India
		'hat' => 'HT', // Haitian Creole   → Haiti
		'haw' => 'US', // Hawaiian         → United States
		'haz' => 'AF', // Hazaragi         → Afghanistan
		'he'  => 'IL', // Hebrew           → Israel
		'hi'  => 'IN', // Hindi            → India
		'hr'  => 'HR', // Croatian         → Croatia
		'hsb' => 'DE', // Upper Sorbian    → Germany
		'hrx' => 'BR', // Hunsrik          → Brazil
		'hu'  => 'HU', // Hungarian        → Hungary
		'hy'  => 'AM', // Armenian         → Armenia
		'iba' => 'MY', // Iban             → Malaysia
		'id'  => 'ID', // Indonesian       → Indonesia
		'is'  => 'IS', // Icelandic        → Iceland
		'it'  => 'IT', // Italian          → Italy
		'ja'  => 'JP', // Japanese         → Japan
		'jv'  => 'ID', // Javanese         → Indonesia
		'ka'  => 'GE', // Georgian         → Georgia
		'kab' => 'DZ', // Kabyle           → Algeria
		'kin' => 'RW', // Kinyarwanda      → Rwanda
		'kk'  => 'KZ', // Kazakh           → Kazakhstan
		'km'  => 'KH', // Khmer            → Cambodia
		'kn'  => 'IN', // Kannada          → India
		'ko'  => 'KR', // Korean           → South Korea
		'lij' => 'IT', // Ligurian         → Italy
		'lo'  => 'LA', // Lao              → Laos
		'lt'  => 'LT', // Lithuanian       → Lithuania
		'lmo' => 'IT', // Lombard          → Italy
		'lv'  => 'LV', // Latvian          → Latvia
		'mai' => 'IN', // Maithili         → India
		'mfe' => 'MU', // Mauritian Creole → Mauritius
		'mk'  => 'MK', // Macedonian       → North Macedonia
		'ml'  => 'IN', // Malayalam        → India
		'mn'  => 'MN', // Mongolian        → Mongolia
		'mr'  => 'IN', // Marathi          → India
		'ms'  => 'MY', // Malay            → Malaysia
		'my'  => 'MM', // Burmese          → Myanmar
		'nb'  => 'NO', // Norwegian Bokmål → Norway
		'ne'  => 'NP', // Nepali           → Nepal
		'nl'  => 'NL', // Dutch            → Netherlands
		'nn'  => 'NO', // Norwegian Nynorsk→ Norway
		'nqo' => 'GN', // N'Ko             → Guinea
		'oci' => 'FR', // Occitan          → France
		'pa'  => 'IN', // Punjabi          → India
		'pap' => 'CW', // Papiamento       → Curaçao
		'pcm' => 'NG', // Nigerian Pidgin  → Nigeria
		'pl'  => 'PL', // Polish           → Poland
		'ps'  => 'AF', // Pashto           → Afghanistan
		'pt'  => 'PT', // Portuguese       → Portugal
		'rhg' => 'MM', // Rohingya         → Myanmar
		'ro'  => 'RO', // Romanian         → Romania
		'roh' => 'CH', // Romansh          → Switzerland
		'ru'  => 'RU', // Russian          → Russia
		'rue' => 'UA', // Rusyn            → Ukraine
		'sah' => 'RU', // Sakha            → Russia
		'sd'  => 'PK', // Sindhi           → Pakistan
		'si'  => 'LK', // Sinhala          → Sri Lanka
		'sk'  => 'SK', // Slovak           → Slovakia
		'skr' => 'PK', // Saraiki          → Pakistan
		'sl'  => 'SI', // Slovenian        → Slovenia
		'sli' => 'PL', // Silesian         → Poland
		'som' => 'SO', // Somali           → Somalia
		'sq'  => 'AL', // Albanian         → Albania
		'srd' => 'IT', // Sardinian        → Italy
		'sr'  => 'RS', // Serbian          → Serbia
		'scn' => 'IT', // Sicilian         → Italy
		'sv'  => 'SE', // Swedish          → Sweden
		'sw'  => 'KE', // Swahili          → Kenya
		'ta'  => 'IN', // Tamil            → India
		'tah' => 'PF', // Tahitian         → French Polynesia
		'tat' => 'RU', // Tatar            → Russia
		'te'  => 'IN', // Telugu           → India
		'th'  => 'TH', // Thai             → Thailand
		'tl'  => 'PH', // Tagalog          → Philippines
		'tr'  => 'TR', // Turkish          → Türkiye
		'tuk' => 'TM', // Turkmen          → Turkmenistan
		'tzm' => 'MA', // Tamazight        → Morocco
		'ug'  => 'CN', // Uyghur           → China
		'uk'  => 'UA', // Ukrainian        → Ukraine
		'ur'  => 'PK', // Urdu             → Pakistan
		'uz'  => 'UZ', // Uzbek            → Uzbekistan
		'vec' => 'IT', // Venetian         → Italy
		'vi'  => 'VN', // Vietnamese       → Vietnam
		'xho' => 'ZA', // Xhosa            → South Africa
		'yid' => 'IL', // Yiddish          → Israel
		'zgh' => 'MA', // Moroccan Tamazight→ Morocco
		'zh'  => 'CN', // Chinese          → China
		'zul' => 'ZA', // Zulu             → South Africa
	);

	/**
	 * Resolve the ISO 3166-1 alpha-2 country (flag) code for a language.
	 *
	 * Compound codes (e.g. "de_CH", "pt_BR", "zh_HK") return the country
	 * from their suffix. Base codes are looked up in the canonical map.
	 *
	 * Returns an empty string when no country can be determined (e.g.
	 * Esperanto, or an unknown code), so callers can fall back to a
	 * neutral glyph instead of producing a meaningless flag.
	 *
	 * @param string $code Language code (e.g. "et", "de_CH").
	 * @return string Two-letter country code (uppercase), or '' if unknown.
	 */
	public static function countryCode( string $code ): string {
		$code = strtolower( trim( $code ) );

		if ( '' === $code ) {
			return '';
		}

		// Compound codes carry the country in the suffix (de_CH → CH).
		if ( str_contains( $code, '_' ) ) {
			$parts  = explode( '_', $code );
			$suffix = strtoupper( $parts[1] ?? '' );

			if ( self::looksLikeCountryCode( $suffix ) ) {
				return $suffix;
			}
		}

		return self::COUNTRY_MAP[ $code ] ?? '';
	}

	/**
	 * Get the flag emoji for a language.
	 *
	 * Returns a globe (🌐) for languages with no associated country.
	 *
	 * @param string $code Language code.
	 * @return string Flag emoji character.
	 */
	public static function emoji( string $code ): string {
		$country = self::countryCode( $code );

		if ( '' === $country ) {
			return '🌐';
		}

		return self::countryCodeToEmoji( $country );
	}

	/**
	 * Convert a two-letter country code to its regional-indicator emoji.
	 *
	 * @param string $country Two-letter ISO country code (case-insensitive).
	 * @return string Flag emoji, or globe if the input is not a valid code.
	 */
	private static function countryCodeToEmoji( string $country ): string {
		$country = strtoupper( $country );

		if ( ! self::looksLikeCountryCode( $country ) ) {
			return '🌐';
		}

		// Regional indicator symbols: A = U+1F1E6, B = U+1F1E7, …
		$base = ord( 'A' );

		return mb_chr( 0x1F1E6 + ( ord( $country[0] ) - $base ), 'UTF-8' )
			. mb_chr( 0x1F1E6 + ( ord( $country[1] ) - $base ), 'UTF-8' );
	}

	/**
	 * Whether a string is a plausible ISO 3166-1 alpha-2 country code.
	 *
	 * Only checks the format (two uppercase ASCII letters); it is used to
	 * guard emoji generation and suffix extraction, not to assert that a
	 * code is assigned by ISO.
	 *
	 * @param string $code String to test.
	 * @return bool
	 */
	private static function looksLikeCountryCode( string $code ): bool {
		return 2 === strlen( $code ) && ctype_upper( $code );
	}
}
