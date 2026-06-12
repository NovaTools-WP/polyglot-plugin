<?php
/**
 * Auto-scanner for plugin/theme activation.
 *
 * Hooks into WordPress `activated_plugin` and `switch_theme` actions
 * to automatically scan and register translatable strings when a
 * plugin or theme is activated, if the setting is enabled.
 *
 * @package NovaTools\Polyglot\String
 */

namespace NovaTools\Polyglot\String;

use NovaTools\Polyglot\FileTranslation\StringExtractor;
use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class AutoScanner {

	private StringExtractor $extractor;

	private StringManager $manager;

	private OptionStore $options;

	public function __construct(
		StringExtractor $extractor,
		StringManager $manager,
		OptionStore $options
	) {
		$this->extractor = $extractor;
		$this->manager   = $manager;
		$this->options    = $options;
	}

	public function register(): void {
		add_action( 'activated_plugin', array( $this, 'onPluginActivated' ) );
		add_action( 'switch_theme', array( $this, 'onThemeSwitched' ) );
		add_action( 'polyglot_scheduled_scan', array( $this, 'executeScheduledScan' ), 10, 2 );
	}

	public function onPluginActivated( string $plugin ): void {
		if ( ! $this->isEnabled() ) {
			return;
		}

		$slug = dirname( $plugin );

		if ( '.' === $slug ) {
			$slug = basename( $plugin, '.php' );
		}

		wp_schedule_single_event( time(), 'polyglot_scheduled_scan', array( 'plugin', $slug ) );
	}

	public function onThemeSwitched( string $new_theme ): void {
		if ( ! $this->isEnabled() ) {
			return;
		}

		$theme = wp_get_theme( $new_theme );

		if ( ! $theme->exists() ) {
			return;
		}

		$slug = $theme->get_template();

		wp_schedule_single_event( time(), 'polyglot_scheduled_scan', array( 'theme', $slug ) );
	}

	public function executeScheduledScan( string $scope, string $slug ): void {
		$directory = null;

		if ( 'plugin' === $scope ) {
			$dir = WP_PLUGIN_DIR . '/' . $slug;
			if ( is_dir( $dir ) ) {
				$directory = $dir;
			}
		} elseif ( 'theme' === $scope ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				$directory = $theme->get_stylesheet_directory();
			}
		}

		if ( null === $directory ) {
			return;
		}

		$result = $this->extractor->extract( $directory );

		$registered = 0;

		foreach ( $result['strings'] as $entry ) {
			$domain = $entry['domain'];

			if ( empty( $domain ) ) {
				$domain = $this->detectDomain( $directory );
			}

			if ( empty( $domain ) ) {
				continue;
			}

			$this->manager->registerString(
				$domain,
				$entry['msgid'],
				$entry['msgid'],
				$entry['msgctxt']
			);

			++$registered;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[Polyglot] Auto-scan %s "%s": %d strings registered from %d total',
				$scope,
				$slug,
				$registered,
				$result['total']
			) );
		}
	}

	private function isEnabled(): bool {
		$settings = $this->options->get( 'polyglot_settings', array() );

		return ! empty( $settings['auto_scan_on_activation'] );
	}

	private function detectDomain( string $directory ): string {
		$plugin_files = glob( $directory . '/*.php' );

		if ( $plugin_files ) {
			foreach ( $plugin_files as $file ) {
				$headers = get_plugin_data( $file, false, false );
				if ( ! empty( $headers['TextDomain'] ) ) {
					return $headers['TextDomain'];
				}
			}
		}

		$style_css = $directory . '/style.css';
		if ( file_exists( $style_css ) ) {
			$theme = wp_get_theme( basename( $directory ) );
			if ( $theme->exists() ) {
				$text_domain = $theme->get( 'TextDomain' );
				if ( ! empty( $text_domain ) ) {
					return $text_domain;
				}
			}
		}

		return '';
	}
}
