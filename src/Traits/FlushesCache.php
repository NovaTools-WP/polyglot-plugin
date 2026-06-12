<?php
/**
 * Trait for flushing Polyglot object-cache entries.
 *
 * @package NovaTools\Polyglot\Traits
 */

namespace NovaTools\Polyglot\Traits;

use NovaTools\Polyglot\Support\Cache;

defined( 'ABSPATH' ) || exit;

trait FlushesCache {

	/**
	 * Flush all Polyglot object-cache entries.
	 *
	 * @return void
	 */
	protected static function flushCache(): void {
		$cache = new Cache();
		$cache->flushGroup();
	}
}
