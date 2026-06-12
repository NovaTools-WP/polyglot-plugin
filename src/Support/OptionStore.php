<?php
/**
 * Option store for NovaTools Polyglot.
 *
 * Wraps get_option('polyglot_settings') / update_option() with dot-notation
 * key-based access. All plugin settings live in a single serialised option
 * to minimise database queries.
 *
 * @package NovaTools\Polyglot\Support
 */

namespace NovaTools\Polyglot\Support;

defined( 'ABSPATH' ) || exit;

class OptionStore {

	/**
	 * WordPress option name used to store all Polyglot settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'polyglot_settings';

	/**
	 * Cached settings array for the current request.
	 *
	 * @var array|null Null until first access.
	 */
	private ?array $cache = null;

	/**
	 * Retrieve a setting value using dot-notation key.
	 *
	 * @param string $key     Dot-delimited key (e.g. 'url_strategy', 'api.deepl.key').
	 * @param mixed  $default Value returned when the key does not exist.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$settings = $this->all();

		$segments = explode( '.', $key );
		$value    = $settings;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Set a single setting value using dot-notation key.
	 *
	 * Persists immediately to the database and updates the internal cache.
	 *
	 * @param string $key   Dot-delimited key.
	 * @param mixed  $value The value to store.
	 * @return bool True if the option was saved successfully.
	 */
	public function set( string $key, mixed $value ): bool {
		$settings = $this->all();

		$this->arraySet( $settings, $key, $value );

		return $this->save( $settings );
	}

	/**
	 * Merge an array of settings into the existing ones.
	 *
	 * @param array $overrides Key-value pairs to merge.
	 * @return bool True if the option was saved successfully.
	 */
	public function merge( array $overrides ): bool {
		$settings = array_replace_recursive( $this->all(), $overrides );

		return $this->save( $settings );
	}

	/**
	 * Delete a setting by dot-notation key.
	 *
	 * @param string $key Dot-delimited key to remove.
	 * @return bool True if the option was saved successfully.
	 */
	public function delete( string $key ): bool {
		$settings = $this->all();

		$this->arrayDelete( $settings, $key );

		return $this->save( $settings );
	}

	/**
	 * Retrieve all settings as an associative array.
	 *
	 * @return array
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$this->cache = get_option( self::OPTION_KEY, array() );
		}

		return $this->cache;
	}

	/**
	 * Persist the settings array to the database and update the cache.
	 *
	 * @param array $settings Full settings array to store.
	 * @return bool
	 */
	private function save( array $settings ): bool {
		$this->cache = $settings;

		return update_option( self::OPTION_KEY, $settings, false );
	}

	/**
	 * Set a value in a nested array using dot notation.
	 *
	 * @param array  $array The settings array (modified by reference).
	 * @param string $key   Dot-delimited key.
	 * @param mixed  $value The value to assign.
	 */
	private function arraySet( array &$array, string $key, mixed $value ): void {
		$segments = explode( '.', $key );
		$current  = &$array;

		foreach ( $segments as $i => $segment ) {
			if ( ! isset( $current[ $segment ] ) || ! is_array( $current[ $segment ] ) ) {
				$current[ $segment ] = array();
			}
			$current = &$current[ $segment ];
		}

		$current = $value;
	}

	/**
	 * Delete a value from a nested array using dot notation.
	 *
	 * @param array  $array The settings array (modified by reference).
	 * @param string $key   Dot-delimited key to remove.
	 */
	private function arrayDelete( array &$array, string $key ): void {
		$segments = explode( '.', $key );
		$current  = &$array;

		foreach ( $segments as $i => $segment ) {
			if ( $i === count( $segments ) - 1 ) {
				unset( $current[ $segment ] );
				return;
			}

			if ( ! isset( $current[ $segment ] ) || ! is_array( $current[ $segment ] ) ) {
				return;
			}

			$current = &$current[ $segment ];
		}
	}
}
