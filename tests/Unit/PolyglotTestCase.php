<?php
/**
 * Base test case for NovaTools Polyglot unit tests.
 *
 * Wires Brain\Monkey (WordPress function/action/filter stubbing) and
 * Mockery (object mocking) around the Yoast PHPUnit polyfills test case,
 * so every unit test starts with a clean, dependency-free WordPress sandbox.
 *
 * @package NovaTools\Polyglot\Tests\Unit
 */

namespace NovaTools\Polyglot\Tests\Unit;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

abstract class PolyglotTestCase extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Set up a fresh Brain\Monkey sandbox before each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();
	}

	/**
	 * Tear down the Brain\Monkey sandbox after each test.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		Monkey\tearDown();
		// Ensure no $wpdb mock leaks between tests.
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	/**
	 * Create a Mockery mock for the global $wpdb and install it.
	 *
	 * Repositories resolve `global $wpdb` lazily, so tests can override
	 * individual methods (get_row, get_results, insert, …) per expectation.
	 *
	 * @return Mockery\MockInterface&\stdClass
	 */
	protected function mockWpdb() {
		$wpdb = Mockery::mock( '\stdClass' );
		$wpdb->prefix     = 'wp_';
		$wpdb->insert_id  = 0;
		$wpdb->posts      = 'wp_posts';
		$wpdb->postmeta   = 'wp_postmeta';

		$GLOBALS['wpdb'] = $wpdb;

		return $wpdb;
	}
}
