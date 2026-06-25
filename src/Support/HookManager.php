<?php
/**
 * Centralised hook manager for NovaTools Polyglot.
 *
 * Provides a clean API for registering WordPress actions and filters
 * with automatic namespacing and group-based removal support.
 *
 * @package NovaTools\Polyglot\Support
 */

namespace NovaTools\Polyglot\Support;

defined( 'ABSPATH' ) || exit;

class HookManager {

	/**
	 * Tracks all hooks registered via this manager, keyed by group.
	 *
	 * Structure:
	 *   [group][]
	 *     ['type']     string 'action' | 'filter'
	 *     ['hook']     string Full hook tag (without prefix)
	 *     ['callback'] callable
	 *     ['priority'] int
	 *
	 * @var array<string, list<array{type:string, hook:string, callback:callable, priority:int}>>
	 */
	private array $registered = array();

	/**
	 * Register a WordPress action.
	 *
	 * @param string   $hook     The action hook name.
	 * @param callable $callback The callback to run.
	 * @param int      $priority Priority level (default 10).
	 * @param int      $args     Number of arguments the callback accepts.
	 * @param string   $group    Optional group for bulk removal.
	 * @return void
	 */
	public function addAction(
		string $hook,
		callable $callback,
		int $priority = 10,
		int $args = 1,
		string $group = 'default'
	): void {
		add_action( $hook, $callback, $priority, $args );

		$this->track( 'action', $hook, $callback, $priority, $group );
	}

	/**
	 * Register a WordPress filter.
	 *
	 * @param string   $hook     The filter hook name.
	 * @param callable $callback The callback to run.
	 * @param int      $priority Priority level (default 10).
	 * @param int      $args     Number of arguments the callback accepts.
	 * @param string   $group    Optional group for bulk removal.
	 * @return void
	 */
	public function addFilter(
		string $hook,
		callable $callback,
		int $priority = 10,
		int $args = 1,
		string $group = 'default'
	): void {
		add_filter( $hook, $callback, $priority, $args );

		$this->track( 'filter', $hook, $callback, $priority, $group );
	}

	/**
	 * Apply a WordPress filter and return the filtered value.
	 *
	 * Symmetric counterpart to addFilter(): lets services dispatch filters
	 * through the same manager they use to register them, without reaching
	 * for the global apply_filters() directly.
	 *
	 * @param string $hook    The filter hook name.
	 * @param mixed   $value   The value being filtered.
	 * @param mixed   ...$args Additional arguments passed to the callbacks.
	 * @return mixed The filtered value.
	 */
	public function applyFilters( string $hook, mixed $value, mixed ...$args ): mixed {
		return apply_filters( $hook, $value, ...$args );
	}

	/**
	 * Fire a WordPress action.
	 *
	 * Symmetric counterpart to addAction(): lets services dispatch actions
	 * through the same manager they use to register them.
	 *
	 * @param string $hook    The action hook name.
	 * @param mixed   ...$args Arguments passed to the callbacks.
	 * @return void
	 */
	public function doAction( string $hook, mixed ...$args ): void {
		do_action( $hook, ...$args );
	}

	/**
	 * Remove all hooks registered in a given group.
	 *
	 * Useful for disabling a module's hooks without tracking individual
	 * hook names.
	 *
	 * @param string $group The group identifier.
	 * @return int Number of hooks removed.
	 */
	public function removeGroup( string $group ): int {
		if ( ! isset( $this->registered[ $group ] ) ) {
			return 0;
		}

		$removed = 0;

		foreach ( $this->registered[ $group ] as $entry ) {
			if ( 'action' === $entry['type'] ) {
				remove_action( $entry['hook'], $entry['callback'], $entry['priority'] );
			} else {
				remove_filter( $entry['hook'], $entry['callback'], $entry['priority'] );
			}
			++$removed;
		}

		unset( $this->registered[ $group ] );

		return $removed;
	}

	/**
	 * Check whether a group has been registered.
	 *
	 * @param string $group Group identifier.
	 * @return bool
	 */
	public function hasGroup( string $group ): bool {
		return isset( $this->registered[ $group ] );
	}

	/**
	 * Return all registered hooks (for debugging / introspection).
	 *
	 * @return array<string, list<array{type:string, hook:string, callback:callable, priority:int}>>
	 */
	public function getRegistered(): array {
		return $this->registered;
	}

	/**
	 * Track a registered hook internally.
	 *
	 * @param string   $type     'action' or 'filter'.
	 * @param string   $hook     Hook name.
	 * @param callable $callback The registered callback.
	 * @param int      $priority Hook priority.
	 * @param string   $group    Group identifier.
	 */
	private function track(
		string $type,
		string $hook,
		callable $callback,
		int $priority,
		string $group
	): void {
		$this->registered[ $group ][] = array(
			'type'     => $type,
			'hook'     => $hook,
			'callback' => $callback,
			'priority' => $priority,
		);
	}
}
