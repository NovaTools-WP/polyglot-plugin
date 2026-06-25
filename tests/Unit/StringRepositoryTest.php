<?php
/**
 * Tests for the StringRepository service.
 *
 * @package NovaTools\Polyglot\Tests\Unit
 */

namespace NovaTools\Polyglot\Tests\Unit;

use NovaTools\Polyglot\String\StringRepository;
use NovaTools\Polyglot\Support\Cache;

class StringRepositoryTest extends PolyglotTestCase {

	/**
	 * Build a Cache mock with a sensible default key() implementation.
	 */
	private function mockCache(): \Mockery\MockInterface {
		$cache = \Mockery::mock( Cache::class );
		$cache->shouldReceive( 'key' )
			->andReturnUsing( static fn( string $ns, ...$parts ): string => $ns . ':' . implode( ':', $parts ) );

		return $cache;
	}

	public function test_find_by_id_returns_cached_row_without_querying_the_database(): void {
		$row  = array( 'id' => 1, 'value' => 'Hello' );
		$cache = $this->mockCache();
		$cache->shouldReceive( 'get' )->with( 'string:1' )->andReturn( $row );

		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'get_row' )->never();

		$repo = new StringRepository( $cache );

		$this->assertSame( $row, $repo->findById( 1 ) );
	}

	public function test_find_by_id_queries_database_on_cache_miss_and_caches_result(): void {
		$row  = array( 'id' => 1, 'domain' => 'theme', 'value' => 'Hello' );
		$cache = $this->mockCache();
		$cache->shouldReceive( 'get' )->andReturnNull();
		$cache->shouldReceive( 'set' )->once()->with( 'string:1', $row );

		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $query ) => $query );
		$wpdb->shouldReceive( 'get_row' )
			->once()
			->with( \Mockery::type( 'string' ), ARRAY_A )
			->andReturn( $row );

		$repo = new StringRepository( $cache );

		$this->assertSame( $row, $repo->findById( 1 ) );
	}

	public function test_find_by_id_returns_null_when_row_does_not_exist(): void {
		$cache = $this->mockCache();
		$cache->shouldReceive( 'get' )->andReturnNull();
		$cache->shouldReceive( 'set' )->never();

		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $query ) => $query );
		$wpdb->shouldReceive( 'get_row' )->andReturnNull();

		$repo = new StringRepository( $cache );

		$this->assertNull( $repo->findById( 99 ) );
	}

	public function test_find_by_hash_caches_by_both_hash_and_id(): void {
		$row = array( 'id' => '7', 'domain' => 'theme', 'value' => 'Hello', 'hash' => 'abc123' );
		$cache = $this->mockCache();
		$cache->shouldReceive( 'get' )->andReturnNull();
		// First set: the hash-keyed cache entry; second: the id-keyed entry.
		$cache->shouldReceive( 'set' )->once()->with( 'string_hash:abc123', $row );
		$cache->shouldReceive( 'set' )->once()->with( 'string:7', $row );

		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $query ) => $query );
		$wpdb->shouldReceive( 'get_row' )->andReturn( $row );

		$repo = new StringRepository( $cache );

		$this->assertSame( $row, $repo->findByHash( 'abc123' ) );
	}

	public function test_save_updates_when_id_present(): void {
		$cache = $this->mockCache();
		$wpdb  = $this->mockWpdb();

		$data = array( 'id' => 5, 'domain' => 'theme', 'value' => 'Updated' );

		$wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_polyglot_strings', $data, array( 'id' => 5 ) );

		$repo = new StringRepository( $cache );

		$this->assertSame( 5, $repo->save( $data ) );
	}

	public function test_save_inserts_and_returns_insert_id_when_no_id(): void {
		$cache = $this->mockCache();
		$wpdb  = $this->mockWpdb();
		$wpdb->insert_id = 42;

		$data = array( 'domain' => 'theme', 'value' => 'New string' );

		$wpdb->shouldReceive( 'insert' )
			->once()
			->with( 'wp_polyglot_strings', $data );

		$repo = new StringRepository( $cache );

		$this->assertSame( 42, $repo->save( $data ) );
	}

	public function test_delete_removes_translations_and_string_and_invalidates_cache(): void {
		$cache = $this->mockCache();
		$cache->shouldReceive( 'delete' )->once()->with( 'string:3' );

		$wpdb = $this->mockWpdb();
		$wpdb->shouldReceive( 'delete' )->once()->with( 'wp_polyglot_string_translations', array( 'string_id' => 3 ) );
		$wpdb->shouldReceive( 'delete' )->once()->with( 'wp_polyglot_strings', array( 'id' => 3 ) )->andReturn( 1 );

		$repo = new StringRepository( $cache );

		$this->assertTrue( $repo->delete( 3 ) );
	}
}
