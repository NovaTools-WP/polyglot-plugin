<?php
/**
 * Tests for the UrlConverter facade.
 *
 * Focuses on the strategy wiring and the pure conversion/lookup logic,
 * stubbing the strategy itself so no WordPress request state is needed.
 *
 * @package NovaTools\Polyglot\Tests\Unit
 */

namespace NovaTools\Polyglot\Tests\Unit;

use NovaTools\Polyglot\Support\HookManager;
use NovaTools\Polyglot\Support\OptionStore;
use NovaTools\Polyglot\Url\UrlConverter;
use NovaTools\Polyglot\Url\UrlStrategyInterface;

class UrlConverterTest extends PolyglotTestCase {

	public function test_strategy_map_covers_all_four_url_strategies(): void {
		$expected = array(
			'directory'   => \NovaTools\Polyglot\Url\DirectoryStrategy::class,
			'subdomain'   => \NovaTools\Polyglot\Url\SubdomainStrategy::class,
			'domain'      => \NovaTools\Polyglot\Url\DomainStrategy::class,
			'query_param' => \NovaTools\Polyglot\Url\QueryParamStrategy::class,
		);

		$this->assertSame( $expected, UrlConverter::STRATEGY_MAP );
	}

	public function test_set_current_language_round_trips(): void {
		$converter = new UrlConverter(
			\Mockery::mock( OptionStore::class ),
			\Mockery::mock( HookManager::class )
		);

		$converter->setCurrentLanguage( 'fr' );

		$this->assertSame( 'fr', $converter->getCurrentLanguage() );
	}

	public function test_convert_returns_url_unchanged_for_empty_language(): void {
		$strategy  = \Mockery::mock( UrlStrategyInterface::class );
		$converter = new UrlConverter(
			\Mockery::mock( OptionStore::class ),
			\Mockery::mock( HookManager::class )
		);
		$converter->setStrategy( $strategy );

		// No strategy call should happen for an empty (default) language.
		$strategy->shouldReceive( 'addLanguageToUrl' )->never();

		$this->assertSame( 'http://example.com/', $converter->convert( 'http://example.com/', '' ) );
	}

	public function test_convert_delegates_to_the_active_strategy(): void {
		$strategy  = \Mockery::mock( UrlStrategyInterface::class );
		$strategy->shouldReceive( 'addLanguageToUrl' )
			->once()
			->with( 'http://example.com/', 'fr' )
			->andReturn( 'http://example.com/fr/' );

		$converter = new UrlConverter(
			\Mockery::mock( OptionStore::class ),
			\Mockery::mock( HookManager::class )
		);
		$converter->setStrategy( $strategy );

		$this->assertSame( 'http://example.com/fr/', $converter->convert( 'http://example.com/', 'fr' ) );
	}
}
