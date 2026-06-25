<?php
/**
 * Tests for the TranslationRepository service.
 *
 * @package NovaTools\Polyglot\Tests\Unit
 */

namespace NovaTools\Polyglot\Tests\Unit;

use Brain\Monkey\Functions;
use NovaTools\Polyglot\Support\Cache;
use NovaTools\Polyglot\Translation\TranslationRepository;

class TranslationRepositoryTest extends PolyglotTestCase {

	/**
	 * Build a Cache mock with a sensible default key() implementation.
	 */
	private function mockCache(): \Mockery\MockInterface {
		$cache = \Mockery::mock( Cache::class );
		$cache->shouldReceive( 'key' )
			->andReturnUsing( static fn( string $ns, ...$parts ): string => $ns . ':' . implode( ':', $parts ) );

		return $cache;
	}

	public function test_get_by_element_returns_cached_row_without_querying(): void {
		$row  = array( 'translation_id' => '1', 'trid' => '1', 'element_id' => '5' );
		$cache = $this->mockCache();
		$cache->shouldReceive( 'get' )->andReturn( $row );

		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'get_row' )->never();

		$repo = new TranslationRepository( $cache );

		$this->assertSame( $row, $repo->getByElement( 'post_post', 5 ) );
	}

	public function test_get_by_element_queries_and_caches_on_miss(): void {
		$row  = array( 'translation_id' => '1', 'trid' => '10', 'element_id' => '5' );
		$cache = $this->mockCache();
		$cache->shouldReceive( 'get' )->andReturnNull();
		$cache->shouldReceive( 'set' )->once()->with( 'translation:element:post_post:5', $row );

		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $query ) => $query );
		$wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$repo = new TranslationRepository( $cache );

		$this->assertSame( $row, $repo->getByElement( 'post_post', 5 ) );
	}

	public function test_get_by_element_returns_null_when_not_found(): void {
		$cache = $this->mockCache();
		$cache->shouldReceive( 'get' )->andReturnNull();
		$cache->shouldReceive( 'set' )->never();

		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $query ) => $query );
		$wpdb->shouldReceive( 'get_row' )->andReturnNull();

		$repo = new TranslationRepository( $cache );

		$this->assertNull( $repo->getByElement( 'post_post', 99 ) );
	}

	public function test_save_inserts_new_row_when_no_existing(): void {
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$cache = $this->mockCache();
		$cache->shouldReceive( 'get' )->andReturnNull();
		// invalidateCache deletes two cache keys after the insert.
		$cache->shouldReceive( 'delete' )->twice();

		$wpdb = $this->mockWpdb();
		$wpdb->insert_id = 77;
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $query ) => $query );
		// getByElement lookup returns no existing row.
		$wpdb->shouldReceive( 'get_row' )->andReturnNull();
		$wpdb->shouldReceive( 'insert' )
			->once()
			->with( 'wp_polyglot_translations', \Mockery::on( static fn( array $d ): bool => 5 === $d['element_id'] && 'fr' === $d['language_code'] ) )
			->andReturn( 1 );

		$repo = new TranslationRepository( $cache );

		$result = $repo->save( array(
			'element_type'  => 'post_post',
			'element_id'    => 5,
			'trid'          => 10,
			'language_code' => 'fr',
		) );

		$this->assertSame( 77, $result );
	}

	public function test_save_updates_existing_row_when_present(): void {
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$existing = array(
			'translation_id' => '42',
			'trid'           => '10',
			'element_id'     => '5',
			'element_type'   => 'post_post',
		);

		$cache = $this->mockCache();
		$cache->shouldReceive( 'get' )->andReturnNull();
		// getByElement caches the hit it finds.
		$cache->shouldReceive( 'set' );
		$cache->shouldReceive( 'delete' )->twice();

		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $query ) => $query );
		// The getByElement lookup returns the existing row.
		$wpdb->shouldReceive( 'get_row' )->andReturn( $existing );
		$wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_polyglot_translations', \Mockery::type( 'array' ), array( 'element_type' => 'post_post', 'element_id' => 5 ) );

		$repo = new TranslationRepository( $cache );

		$result = $repo->save( array(
			'element_type'  => 'post_post',
			'element_id'    => 5,
			'trid'          => 10,
			'language_code' => 'fr',
		) );

		$this->assertSame( 42, $result );
	}

	public function test_paginate_returns_empty_result_when_total_is_zero(): void {
		$cache = $this->mockCache();

		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $query ) => $query );
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( 0 );
		$wpdb->shouldReceive( 'get_results' )->never();

		$repo = new TranslationRepository( $cache );

		$result = $repo->paginate();

		$this->assertSame( 0, $result['total'] );
		$this->assertSame( array(), $result['items'] );
	}

	public function test_paginate_whitelists_orderby_against_injection(): void {
		$rows = array(
			array( 'translation_id' => '1', 'trid' => '1' ),
			array( 'translation_id' => '2', 'trid' => '1' ),
		);

		$cache = $this->mockCache();

		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $query ) => $query );
		$wpdb->shouldReceive( 'get_var' )->andReturn( 2 );

		$captured_sql = '';
		$wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturnUsing( static function ( $sql ) use ( $rows, &$captured_sql ) {
				$captured_sql = $sql;
				return $rows;
			} );

		$repo = new TranslationRepository( $cache );

		$result = $repo->paginate( array(
			'orderby' => 'trid; DROP TABLE wp_users',
			'order'   => 'EVIL',
		) );

		// An injected orderby/column must collapse to the safe default column
		// and a whitelisted direction.
		$this->assertStringContainsString( 'ORDER BY trid ASC', $captured_sql );
		$this->assertStringNotContainsString( 'DROP', $captured_sql );
		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['items'] );
	}
}
