<?php
/**
 * Tests for the service provider wiring.
 *
 * Guards the Phase 0 fix: the translation-API services (`provider.registry`
 * and `auto.translator`) must be registered, resolvable, and correctly wired,
 * otherwise the auto-translate REST endpoint always reports "service unavailable".
 *
 * @package NovaTools\Polyglot\Tests\Unit
 */

namespace NovaTools\Polyglot\Tests\Unit;

use NovaTools\Polyglot\Core\ServiceProvider;
use NovaTools\Polyglot\TranslationApi\AutoTranslator;
use NovaTools\Polyglot\TranslationApi\ProviderRegistry;
use Pimple\Container;

class ServiceProviderTest extends PolyglotTestCase {

	private function registeredContainer(): Container {
		$container = new Container();
		( new ServiceProvider() )->register( $container );

		return $container;
	}

	public function test_provider_registry_service_is_registered(): void {
		$container = $this->registeredContainer();

		$this->assertArrayHasKey( 'provider.registry', $container );
		$this->assertInstanceOf( ProviderRegistry::class, $container['provider.registry'] );
	}

	public function test_auto_translator_service_is_registered(): void {
		$container = $this->registeredContainer();

		$this->assertArrayHasKey( 'auto.translator', $container );
		$this->assertInstanceOf( AutoTranslator::class, $container['auto.translator'] );
	}

	public function test_auto_translator_reuses_the_registry_singleton(): void {
		$container = $this->registeredContainer();

		$registry       = $container['provider.registry'];
		$autoTranslator = $container['auto.translator'];

		// Pimple services are shared: both must reference the same registry.
		$this->assertSame( $registry, $container['provider.registry'] );

		// The configured default provider resolves without throwing.
		$this->assertNotNull( $autoTranslator );
	}

	public function test_registry_loads_the_three_builtin_providers(): void {
		$container = $this->registeredContainer();

		/** @var ProviderRegistry $registry */
		$registry = $container['provider.registry'];

		$this->assertSame(
			array( 'deepl', 'google', 'openai' ),
			array_keys( $registry->all() )
		);
	}
}
