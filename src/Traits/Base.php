<?php
/**
 * Singleton trait for NovaTools Polyglot.
 *
 * Provides a get_instance() singleton accessor matching the pattern
 * used by the NovaTools core and SEO addon.
 *
 * @package NovaTools\Polyglot\Traits
 */

namespace NovaTools\Polyglot\Traits;

defined( 'ABSPATH' ) || exit;

trait Base {

	/**
	 * The singleton instance.
	 *
	 * @var static|null
	 */
	private static $instance;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return static
	 */
	public static function get_instance() {
		if ( ! static::$instance ) {
			static::$instance = new static();
		}

		return static::$instance;
	}
}
