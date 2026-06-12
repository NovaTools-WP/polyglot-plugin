<?php
/**
 * Plugin-level logger for NovaTools Polyglot.
 *
 * Centralises error logging behind a WP_DEBUG gate so that diagnostic
 * messages are only written to the PHP error log when WordPress debug
 * mode is active. All log lines are prefixed with `[Polyglot]` for
 * easy grepping.
 *
 * @package NovaTools\Polyglot\Support
 */

namespace NovaTools\Polyglot\Support;

defined( 'ABSPATH' ) || exit;

class Logger {

	/**
	 * Log prefix prepended to every message.
	 *
	 * @var string
	 */
	const PREFIX = '[Polyglot]';

	/**
	 * Log an error message.
	 *
	 * Only writes to the PHP error log when WP_DEBUG is enabled.
	 *
	 * @param string $message The log message.
	 * @param array  $context Optional. Additional context data encoded as JSON.
	 */
	public static function error( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$extra = $context ? ' ' . wp_json_encode( $context ) : '';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( self::PREFIX . ' ' . $message . $extra );
	}

	/**
	 * Log a warning message.
	 *
	 * Only writes to the PHP error log when WP_DEBUG is enabled.
	 *
	 * @param string $message The log message.
	 * @param array  $context Optional. Additional context data encoded as JSON.
	 */
	public static function warning( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$extra = $context ? ' ' . wp_json_encode( $context ) : '';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( self::PREFIX . ' WARNING: ' . $message . $extra );
	}

	/**
	 * Log an informational message.
	 *
	 * Only writes to the PHP error log when WP_DEBUG is enabled.
	 *
	 * @param string $message The log message.
	 * @param array  $context Optional. Additional context data encoded as JSON.
	 */
	public static function info( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$extra = $context ? ' ' . wp_json_encode( $context ) : '';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( self::PREFIX . ' ' . $message . $extra );
	}
}
