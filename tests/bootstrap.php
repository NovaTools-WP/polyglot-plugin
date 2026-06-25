<?php
/**
 * PHPUnit bootstrap for NovaTools Polyglot unit tests.
 *
 * The plugin's source files guard themselves with `defined( 'ABSPATH' ) || exit;`,
 * so we define ABSPATH and the time constants the cron/scheduling code relies on,
 * then hand off to Composer's autoloader. WordPress functions are stubbed
 * per-test via Brain\Monkey (no WordPress or MySQL test environment required).
 *
 * @package NovaTools\Polyglot\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// WordPress time constants used by the WooCommerce cron / currency code.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
	define( 'YEAR_IN_SECONDS', 31536000 );
}

// $wpdb output-mode constants (normally defined in wp-includes/wp-db.php).
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'OBJECT_K' ) ) {
	define( 'OBJECT_K', 'OBJECT_K' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
