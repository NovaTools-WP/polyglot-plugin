<?php
/**
 * Tests for the LocaleMapper service.
 *
 * @package NovaTools\Polyglot\Tests\Unit
 */

namespace NovaTools\Polyglot\Tests\Unit;

use NovaTools\Polyglot\Language\LocaleMapper;
use NovaTools\Polyglot\Support\Cache;

class LocaleMapperTest extends PolyglotTestCase {

	/**
	 * Build a LocaleMapper whose object cache always misses, so the
	 * static fallback map is exercised. The $wpdb map is empty.
	 */
	private function createMapperWithEmptyDatabase(): LocaleMapper {
		$cache = \Mockery::mock( Cache::class );
		$cache->shouldReceive( 'key' )
			->andReturnUsing( static fn( string $ns, ...$parts ): string => $ns . ':' . implode( ':', $parts ) );
		$cache->shouldReceive( 'get' )->andReturnNull();
		$cache->shouldReceive( 'set' );

		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		return new LocaleMapper( $cache );
	}

	public function test_locale_to_code_maps_common_locales_via_static_fallback(): void {
		$mapper = $this->createMapperWithEmptyDatabase();

		$this->assertSame( 'fr', $mapper->localeToCode( 'fr_FR' ) );
		$this->assertSame( 'en', $mapper->localeToCode( 'en_US' ) );
		$this->assertSame( 'de', $mapper->localeToCode( 'de_DE' ) );
		$this->assertSame( 'zh', $mapper->localeToCode( 'zh_CN' ) );
		$this->assertSame( 'es', $mapper->localeToCode( 'es_ES' ) );
	}

	public function test_locale_to_code_derives_code_for_unknown_locales(): void {
		$mapper = $this->createMapperWithEmptyDatabase();

		// No static entry → derive from the substring before the underscore.
		$this->assertSame( 'xx', $mapper->localeToCode( 'xx_YY' ) );
		// No underscore at all → lowercase the locale as-is.
		$this->assertSame( 'ca', $mapper->localeToCode( 'CA' ) );
	}

	public function test_code_to_locale_maps_via_static_reverse_fallback(): void {
		$mapper = $this->createMapperWithEmptyDatabase();

		$this->assertSame( 'fr_FR', $mapper->codeToLocale( 'fr' ) );
		$this->assertSame( 'en_US', $mapper->codeToLocale( 'en' ) );
		$this->assertSame( 'de_DE', $mapper->codeToLocale( 'de' ) );
	}

	public function test_code_to_locale_passes_through_unknown_codes(): void {
		$mapper = $this->createMapperWithEmptyDatabase();

		$this->assertSame( 'xx', $mapper->codeToLocale( 'xx' ) );
	}

	public function test_database_map_takes_precedence_over_static_fallback(): void {
		$cache = \Mockery::mock( Cache::class );
		$cache->shouldReceive( 'key' )
			->andReturnUsing( static fn( string $ns, ...$parts ): string => $ns . ':' . implode( ':', $parts ) );
		$cache->shouldReceive( 'get' )->andReturnNull();
		$cache->shouldReceive( 'set' );

		$wpdb = $this->mockWpdb();
		// Database carries a custom mapping that should win over the static map.
		$wpdb->shouldReceive( 'get_results' )
			->andReturn( array(
				array(
					'locale' => 'fr_FR',
					'code'   => 'french_custom',
				),
			) );

		$mapper = new LocaleMapper( $cache );

		$this->assertSame( 'french_custom', $mapper->localeToCode( 'fr_FR' ) );
		$this->assertSame( 'fr_FR', $mapper->codeToLocale( 'french_custom' ) );
	}

	public function test_is_valid_locale(): void {
		// isValidLocale() is pure logic — no DB or cache needed.
		$mapper = new LocaleMapper( \Mockery::mock( Cache::class ) );

		$this->assertTrue( $mapper->isValidLocale( 'fr_FR' ) );
		$this->assertTrue( $mapper->isValidLocale( 'en_US' ) );
		$this->assertTrue( $mapper->isValidLocale( 'zh_CN' ) );
		$this->assertTrue( $mapper->isValidLocale( 'sr_RS' ) );
		$this->assertTrue( $mapper->isValidLocale( 'ca' ) );

		$this->assertFalse( $mapper->isValidLocale( '' ) );
		$this->assertFalse( $mapper->isValidLocale( 'invalid!' ) );
		$this->assertFalse( $mapper->isValidLocale( '12' ) );
	}

	public function test_static_maps_round_trip(): void {
		// Every code in the static map should round-trip locale → code → locale,
		// except codes that collide (e.g. regional variants sharing a locale).
		$forward = LocaleMapper::getStaticMap();

		$collisions = 0;

		foreach ( $forward as $locale => $code ) {
			if ( $locale === $code ) {
				// Locale and code identical (e.g. "af" => "af") — trivially round-trips.
				continue;
			}

			$reverse = LocaleMapper::getStaticReverseMap();

			if ( isset( $reverse[ $code ] ) && $reverse[ $code ] === $locale ) {
				continue;
			}

			++$collisions;
		}

		// The vast majority of entries must round-trip cleanly. We allow a small
		// handful for intentionally-colliding regional variants.
		$this->assertLessThan( 5, $collisions );
	}
}
