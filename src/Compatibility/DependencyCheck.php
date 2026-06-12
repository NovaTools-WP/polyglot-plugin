<?php
/**
 * Dependency checker for NovaTools Polyglot.
 *
 * Follows the same pattern as the NovaTools SEO addon: checks whether
 * the NovaTools core plugin is active and displays an admin notice when
 * it is absent. The plugin works in both integrated and standalone modes.
 *
 * @package NovaTools\Polyglot\Compatibility
 */

namespace NovaTools\Polyglot\Compatibility;

defined( 'ABSPATH' ) || exit;

class DependencyCheck {

	/**
	 * Check whether the NovaTools core plugin is active.
	 *
	 * @return bool
	 */
	public static function is_novatools_active(): bool {
		return class_exists( 'NovaTools' );
	}

	/**
	 * Render an admin notice when NovaTools is not active.
	 *
	 * Hooked to `admin_notices` by the main plugin file.
	 * Displays a dismissible warning — the plugin still works
	 * in standalone mode but some integrations are limited.
	 *
	 * @return void
	 */
	public static function admin_notice(): void {
		if ( ! static::is_novatools_active() ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html__(
					'NovaTools - Polyglot works best with NovaTools core installed and active. Some features may be limited.',
					'novatools-polyglot'
				)
			);
		}
	}

	/**
	 * Check whether the minimum PHP version requirement is met.
	 *
	 * @return bool
	 */
	public static function php_version_ok(): bool {
		return version_compare( PHP_VERSION, '8.1.0', '>=' );
	}

	/**
	 * Check whether the minimum WordPress version requirement is met.
	 *
	 * @return bool
	 */
	public static function wordpress_version_ok(): bool {
		return version_compare( get_bloginfo( 'version' ), '6.0', '>=' );
	}

	/**
	 * Render a notice when PHP or WordPress version requirements are not met.
	 *
	 * @return void
	 */
	public static function version_notice(): void {
		if ( ! static::php_version_ok() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				sprintf(
					/* translators: %s: minimum PHP version */
					esc_html__(
						'NovaTools Polyglot requires PHP %s or higher.',
						'novatools-polyglot'
					),
					'8.1'
				)
			);
		}

		if ( ! static::wordpress_version_ok() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				sprintf(
					/* translators: %s: minimum WordPress version */
					esc_html__(
						'NovaTools Polyglot requires WordPress %s or higher.',
						'novatools-polyglot'
					),
					'6.0'
				)
			);
		}
	}
}
