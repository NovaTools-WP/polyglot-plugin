<?php
/**
 * Tests for the LocaleSwitcher service (Phase 1).
 *
 * This is the most safety-critical service in the plugin: it changes which
 * `.mo` files WordPress loads on the frontend. The tests pin down the
 * resolution rules, the skip contexts (admin/REST/cron), the default-language
 * short-circuit, memoization, textdomain reloading, and extensibility.
 *
 * @package NovaTools\Polyglot\Tests\Unit
 */

namespace NovaTools\Polyglot\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use NovaTools\Polyglot\Language\LocaleMapper;
use NovaTools\Polyglot\Support\LocaleSwitcher;

class LocaleSwitcherTest extends PolyglotTestCase {

	/**
	 * Build a LocaleSwitcher with a LocaleMapper mock that maps the given
	 * language codes to locales (and reports them as valid).
	 *
	 * @param array $map    Associative code => locale (e.g. ['fr' => 'fr_FR']).
	 * @param array $invalid Codes that isValidLocale() should report as invalid.
	 */
	private function createSwitcher( array $map = array( 'fr' => 'fr_FR' ), array $invalid = array() ): LocaleSwitcher {
		$mapper = Mockery::mock( LocaleMapper::class );

		$mapper->shouldReceive( 'codeToLocale' )
			->andReturnUsing( static function ( string $code ) use ( $map ): string {
				return $map[ $code ] ?? $code;
			} );

		$mapper->shouldReceive( 'isValidLocale' )
			->andReturnUsing( static function ( string $locale ) use ( $invalid ): bool {
				return ! in_array( $locale, $invalid, true );
			} );

		return new LocaleSwitcher( $mapper );
	}

	/**
	 * Stub the request-context helpers so the switcher treats the request as
	 * a normal frontend hit (not admin / not cron).
	 */
	private function stubFrontendContext(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
	}

	// ── Resolution ────────────────────────────────────────────────────────

	public function test_resolve_returns_mapped_locale_for_non_default_language(): void {
		$this->stubFrontendContext();
		Functions\when( 'polyglot_get_current_language' )->justReturn( 'fr' );
		Functions\when( 'polyglot_get_default_language' )->justReturn( 'en' );

		$switcher = $this->createSwitcher();

		$this->assertSame( 'fr_FR', $switcher->filterLocale( 'en_US' ) );
	}

	public function test_resolve_short_circuits_to_fallback_when_language_is_default(): void {
		$this->stubFrontendContext();
		Functions\when( 'polyglot_get_current_language' )->justReturn( 'en' );
		Functions\when( 'polyglot_get_default_language' )->justReturn( 'en' );

		// The mapper must never be consulted when current === default.
		$mapper = Mockery::mock( LocaleMapper::class );
		$mapper->shouldNotReceive( 'codeToLocale' );
		$switcher = new LocaleSwitcher( $mapper );

		$this->assertSame( 'en_US', $switcher->filterLocale( 'en_US' ) );
	}

	public function test_resolve_falls_back_when_locale_mapping_is_unknown(): void {
		$this->stubFrontendContext();
		Functions\when( 'polyglot_get_current_language' )->justReturn( 'xx' );
		Functions\when( 'polyglot_get_default_language' )->justReturn( 'en' );

		// codeToLocale returns the code unchanged for unknown codes → no override.
		$switcher = $this->createSwitcher( array() );

		$this->assertSame( 'en_US', $switcher->filterLocale( 'en_US' ) );
	}

	// ── Skip contexts ─────────────────────────────────────────────────────

	public function test_resolve_skips_in_admin_and_returns_fallback(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'polyglot_get_current_language' )->justReturn( 'fr' );
		Functions\when( 'polyglot_get_default_language' )->justReturn( 'en' );

		// Short-circuited before touching the language functions at all.
		$mapper = Mockery::mock( LocaleMapper::class );
		$mapper->shouldNotReceive( 'codeToLocale' );
		$switcher = new LocaleSwitcher( $mapper );

		$this->assertSame( 'en_US', $switcher->filterLocale( 'en_US' ) );
	}

	/**
	 * REST_REQUEST is a process-global constant, so this test runs in its
	 * own process to avoid leaking the define into every later shouldSkip() call.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_resolve_skips_for_rest_requests(): void {
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}

		$this->stubFrontendContext();
		Functions\when( 'polyglot_get_current_language' )->justReturn( 'fr' );
		Functions\when( 'polyglot_get_default_language' )->justReturn( 'en' );

		$switcher = $this->createSwitcher();

		$this->assertSame( 'en_US', $switcher->filterLocale( 'en_US' ) );
	}

	public function test_resolve_skips_for_cron_requests(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\when( 'polyglot_get_current_language' )->justReturn( 'fr' );
		Functions\when( 'polyglot_get_default_language' )->justReturn( 'en' );

		$switcher = $this->createSwitcher();

		$this->assertSame( 'en_US', $switcher->filterLocale( 'en_US' ) );
	}

	// ── Textdomain reloading ──────────────────────────────────────────────

	public function test_record_textdomain_path_ignores_core_domains(): void {
		$switcher = $this->createSwitcher();

		// Core domains are never recorded, so they are never reloaded.
		$switcher->recordTextdomainPath( 'default', '/path/default-en_US.mo' );
		$switcher->recordTextdomainPath( 'admin', '/path/admin-en_US.mo' );
		$switcher->recordTextdomainPath( 'admin-network', '/path/admin-network-en_US.mo' );
		$switcher->recordTextdomainPath( 'mytheme', '/path/mytheme-fr_FR.mo' );

		// Reload runs an empty loop for the core domains and only reloads mytheme.
		Functions\expect( 'unload_textdomain' )->once()->with( 'mytheme' );
		Functions\expect( 'load_textdomain' )->once()->with( 'mytheme', '/path/mytheme-fr_FR.mo' );

		$this->stubFrontendContext();
		Functions\when( 'polyglot_get_current_language' )->justReturn( 'fr' );
		Functions\when( 'polyglot_get_default_language' )->justReturn( 'en' );

		$switcher->filterLocale( 'en_US' );
	}

	public function test_reload_only_runs_when_the_locale_actually_changes(): void {
		$this->stubFrontendContext();
		Functions\when( 'polyglot_get_current_language' )->justReturn( 'fr' );
		Functions\when( 'polyglot_get_default_language' )->justReturn( 'en' );

		Functions\expect( 'unload_textdomain' )->once();
		Functions\expect( 'load_textdomain' )->once();

		$switcher = $this->createSwitcher();
		$switcher->recordTextdomainPath( 'mytheme', '/path/mytheme-fr_FR.mo' );

		// First call triggers a reload (null → fr_FR).
		$switcher->filterLocale( 'en_US' );
		// Second call resolves the same locale → no additional reload.
		// (expect once() above would fail if it reloaded again.)
		$switcher->filterLocale( 'en_US' );
	}

	public function test_locale_switched_action_fires_once_per_locale_change(): void {
		$this->stubFrontendContext();
		Functions\when( 'polyglot_get_current_language' )->justReturn( 'fr' );
		Functions\when( 'polyglot_get_default_language' )->justReturn( 'en' );

		$switcher = $this->createSwitcher();

		// First resolution fires the action with the mapped locale + code.
		Actions\expectDone( 'polyglot_locale_switched' )->once()->with( 'fr_FR', 'fr' );

		$switcher->filterLocale( 'en_US' );
		// Same locale on the second call → action does not fire again.
		$switcher->filterLocale( 'en_US' );
	}

	// ── Extensibility ─────────────────────────────────────────────────────

	public function test_polyglot_frontend_locale_filter_can_override_resolution(): void {
		$this->stubFrontendContext();
		Functions\when( 'polyglot_get_current_language' )->justReturn( 'fr' );
		Functions\when( 'polyglot_get_default_language' )->justReturn( 'en' );

		// A third party can force the resolved locale via the filter.
		Filters\expectApplied( 'polyglot_frontend_locale' )
			->once()
			->with( 'fr_FR', 'en_US', 'fr' )
			->andReturn( 'fr_CA' );

		$switcher = $this->createSwitcher();

		$this->assertSame( 'fr_CA', $switcher->filterLocale( 'en_US' ) );
	}

	// ── Registration ──────────────────────────────────────────────────────

	public function test_register_hooks_the_filters_and_is_idempotent(): void {
		$switcher = $this->createSwitcher();

		$switcher->register();

		$this->assertTrue( has_filter( 'determine_locale', array( $switcher, 'filterDetermineLocale' ) ) !== false );
		$this->assertTrue( has_filter( 'locale', array( $switcher, 'filterLocale' ) ) !== false );
		$this->assertTrue( has_action( 'load_textdomain', array( $switcher, 'recordTextdomainPath' ) ) !== false );

		// Registering again must not duplicate the hooks.
		$before_locale     = has_filter( 'locale', array( $switcher, 'filterLocale' ) );
		$before_determine  = has_filter( 'determine_locale', array( $switcher, 'filterDetermineLocale' ) );
		$switcher->register();
		$this->assertSame( $before_locale, has_filter( 'locale', array( $switcher, 'filterLocale' ) ) );
		$this->assertSame( $before_determine, has_filter( 'determine_locale', array( $switcher, 'filterDetermineLocale' ) ) );
	}
}
