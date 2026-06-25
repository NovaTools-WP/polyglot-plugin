<?php
/**
 * Tests for the FlagResolver.
 *
 * @package NovaTools\Polyglot\Tests\Unit
 */

namespace NovaTools\Polyglot\Tests\Unit;

use NovaTools\Polyglot\Language\FlagResolver;

class FlagResolverTest extends PolyglotTestCase {

	/**
	 * Regional-indicator sequences for assertions.
	 */
	private const FLAG_EE = "\u{1F1EA}\u{1F1EA}"; // Estonia
	private const FLAG_ET = "\u{1F1EA}\u{1F1F9}"; // Ethiopia (the bug)

	/**
	 * The reported bug: Estonian must be Estonia, not Ethiopia.
	 */
	public function test_estonian_resolves_to_estonia_not_ethiopia(): void {
		$this->assertSame( 'EE', FlagResolver::countryCode( 'et' ) );
		$this->assertSame( self::FLAG_EE, FlagResolver::emoji( 'et' ) );
		$this->assertNotSame( self::FLAG_ET, FlagResolver::emoji( 'et' ) );
	}

	/**
	 * Languages whose uppercased code coincidentally matches the country.
	 */
	public function test_languages_where_code_equals_country(): void {
		$this->assertSame( 'DE', FlagResolver::countryCode( 'de' ) );
		$this->assertSame( 'FR', FlagResolver::countryCode( 'fr' ) );
		$this->assertSame( 'ES', FlagResolver::countryCode( 'es' ) );
		$this->assertSame( 'IT', FlagResolver::countryCode( 'it' ) );
		$this->assertSame( 'NL', FlagResolver::countryCode( 'nl' ) );
	}

	/**
	 * Languages whose code is NOT the country code — these all rendered
	 * wrong or invalid flags before the resolver was introduced.
	 */
	public function test_languages_where_code_differs_from_country(): void {
		$this->assertSame( 'CZ', FlagResolver::countryCode( 'cs' ) );   // was "CS" (obsolete)
		$this->assertSame( 'GR', FlagResolver::countryCode( 'el' ) );   // was "EL" (not a country)
		$this->assertSame( 'JP', FlagResolver::countryCode( 'ja' ) );   // was "JA" (not a country)
		$this->assertSame( 'KR', FlagResolver::countryCode( 'ko' ) );   // was "KO" (not a country)
		$this->assertSame( 'SE', FlagResolver::countryCode( 'sv' ) );   // was "SV" (El Salvador)
		$this->assertSame( 'SI', FlagResolver::countryCode( 'sl' ) );   // was "SL" (Sierra Leone)
		$this->assertSame( 'UA', FlagResolver::countryCode( 'uk' ) );   // was "UK"
		$this->assertSame( 'IS', FlagResolver::countryCode( 'is' ) );   // was "IS" (Iceland — happened to work)
	}

	/**
	 * Compound codes derive the flag from the country suffix.
	 */
	public function test_compound_codes_use_country_suffix(): void {
		$this->assertSame( 'CH', FlagResolver::countryCode( 'de_CH' ) );
		$this->assertSame( 'BR', FlagResolver::countryCode( 'pt_BR' ) );
		$this->assertSame( 'HK', FlagResolver::countryCode( 'zh_HK' ) );
		$this->assertSame( 'GB', FlagResolver::countryCode( 'en_GB' ) );
		$this->assertSame( 'RS', FlagResolver::countryCode( 'sr_RS' ) );
	}

	/**
	 * Stateless languages and unknown codes fall back to the globe.
	 */
	public function test_stateless_and_unknown_codes_return_globe(): void {
		$this->assertSame( '', FlagResolver::countryCode( 'eo' ) ); // Esperanto
		$this->assertSame( '🌐', FlagResolver::emoji( 'eo' ) );

		$this->assertSame( '', FlagResolver::countryCode( 'xx' ) ); // Unknown
		$this->assertSame( '🌐', FlagResolver::emoji( 'xx' ) );

		$this->assertSame( '', FlagResolver::countryCode( '' ) );
	}

	/**
	 * Resolution is case- and whitespace-insensitive.
	 */
	public function test_resolution_is_case_insensitive(): void {
		$this->assertSame( 'EE', FlagResolver::countryCode( 'ET' ) );
		$this->assertSame( 'EE', FlagResolver::countryCode( 'Et' ) );
		$this->assertSame( 'CH', FlagResolver::countryCode( ' DE_ch ' ) );
	}

	/**
	 * Emoji output is exactly two regional indicator symbols for a real
	 * country (verified against a couple of well-known flags).
	 */
	public function test_emoji_matches_known_flags(): void {
		$this->assertSame( "\u{1F1E9}\u{1F1EA}", FlagResolver::emoji( 'de' ) ); // 🇩🇪 Germany
		$this->assertSame( "\u{1F1EF}\u{1F1F5}", FlagResolver::emoji( 'ja' ) ); // 🇯🇵 Japan
		$this->assertSame( "\u{1F1E8}\u{1F1ED}", FlagResolver::emoji( 'de_CH' ) ); // 🇨🇭 Switzerland
	}

	/**
	 * Guard: every language shipped in data/languages.json resolves to a
	 * real country flag (except stateless Esperanto), so no language is
	 * ever left with a wrong or empty flag.
	 */
	public function test_every_shipped_language_has_a_real_flag(): void {
		$path    = dirname( __DIR__, 2 ) . '/data/languages.json';
		$decoded = json_decode( (string) file_get_contents( $path ), true );

		$this->assertIsArray( $decoded, 'languages.json must parse as an array' );

		$stateless = array( 'eo' ); // Esperanto has no country.

		foreach ( $decoded as $lang ) {
			$code = $lang['code'];

			if ( in_array( $code, $stateless, true ) ) {
				$this->assertSame( '🌐', FlagResolver::emoji( $code ), "Stateless language {$code} should use the globe" );
				continue;
			}

			$country = FlagResolver::countryCode( $code );
			$this->assertNotEmpty( $country, "Language {$code} has no flag mapping" );

			// Flag emoji is exactly two regional-indicator code points (4 UTF-8
			// bytes each), never the globe, for a real country.
			$emoji = FlagResolver::emoji( $code );
			$this->assertNotSame( '🌐', $emoji, "Language {$code} should have a real flag, not the globe" );
			$this->assertSame( 8, strlen( $emoji ), "Language {$code} emoji should be two regional indicators" );
		}
	}
}
