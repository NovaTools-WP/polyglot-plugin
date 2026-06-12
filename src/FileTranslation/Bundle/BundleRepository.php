<?php
/**
 * Translation bundle repository for NovaTools Polyglot.
 *
 * Lists available translation bundles (plugins, themes, core) by combining
 * WordPress plugin/theme registration data with file-system discovery of
 * PO/MO/POT files.
 *
 * @package NovaTools\Polyglot\FileTranslation\Bundle
 */

namespace NovaTools\Polyglot\FileTranslation\Bundle;

use NovaTools\Polyglot\FileTranslation\FileDiscoveryService;
use NovaTools\Polyglot\Support\Cache;

defined( 'ABSPATH' ) || exit;

class BundleRepository {

	/**
	 * File discovery service.
	 *
	 * @var FileDiscoveryService
	 */
	private FileDiscoveryService $discovery;

	/**
	 * Cache wrapper.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Constructor.
	 *
	 * @param FileDiscoveryService $discovery File discovery service.
	 * @param Cache                $cache     Cache wrapper.
	 */
	public function __construct( FileDiscoveryService $discovery, Cache $cache ) {
		$this->discovery = $discovery;
		$this->cache     = $cache;
	}

	/**
	 * Get all available translation bundles.
	 *
	 * Returns plugins, themes, and optionally core, each represented as a
	 * Bundle value object.
	 *
	 * @return Bundle[] Indexed by text domain.
	 */
	public function getAll(): array {
		$key    = $this->cache->key( 'bundles', 'all' );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return $this->hydrate( $cached );
		}

		$bundles = array();

		// Plugins.
		$this->discoverPlugins( $bundles );

		// Themes.
		$this->discoverThemes( $bundles );

		// Core.
		$this->discoverCore( $bundles );

		// Sort by name.
		uasort( $bundles, static fn( Bundle $a, Bundle $b ): int => strcasecmp( $a->name, $b->name ) );

		// Cache serialised data.
		$data = array();
		foreach ( $bundles as $domain => $bundle ) {
			$data[ $domain ] = $bundle->toArray();
		}

		$this->cache->set( $key, $data );

		return $bundles;
	}

	/**
	 * Get a single bundle by text domain.
	 *
	 * @param string $domain Text domain.
	 * @return Bundle|null
	 */
	public function getByDomain( string $domain ): ?Bundle {
		$all = $this->getAll();
		return $all[ $domain ] ?? null;
	}

	/**
	 * Get only plugin bundles.
	 *
	 * @return Bundle[]
	 */
	public function getPlugins(): array {
		return array_filter(
			$this->getAll(),
			static fn( Bundle $b ): bool => $b->isPlugin()
		);
	}

	/**
	 * Get only theme bundles.
	 *
	 * @return Bundle[]
	 */
	public function getThemes(): array {
		return array_filter(
			$this->getAll(),
			static fn( Bundle $b ): bool => $b->isTheme()
		);
	}

	/**
	 * Get the core WordPress bundle, if translations exist.
	 *
	 * @return Bundle|null
	 */
	public function getCore(): ?Bundle {
		$all = $this->getAll();
		foreach ( $all as $bundle ) {
			if ( $bundle->isCore() ) {
				return $bundle;
			}
		}
		return null;
	}

	/**
	 * Invalidate the bundle cache.
	 *
	 * Called when translation files are added, removed, or modified.
	 *
	 * @return void
	 */
	public function flushCache(): void {
		$this->cache->delete( $this->cache->key( 'bundles', 'all' ) );
	}

	/**
	 * Discover plugin bundles from installed plugins.
	 *
	 * @param Bundle[] &$bundles Array to append to (by reference).
	 */
	private function discoverPlugins( array &$bundles ): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$domain = $plugin_data['TextDomain'] ?? '';
			if ( '' === $domain ) {
				$domain = dirname( $plugin_file );
			}

			// Skip if already processed (plugins with same text domain).
			if ( isset( $bundles[ $domain ] ) ) {
				continue;
			}

			$source_path = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
			$files       = $this->discovery->findFiles( $domain, $source_path );
			$pot_file    = $files['pot'][0] ?? '';
			$locales     = $this->extractLocales( $files );

			// Count strings from POT file.
			$string_count = 0;
			if ( '' !== $pot_file && file_exists( $pot_file ) ) {
				$string_count = $this->countPotStrings( $pot_file );
			}

			// Count completed locales.
			$completed = $this->countCompleted( $files );

			$bundles[ $domain ] = new Bundle(
				$domain,
				Bundle::TYPE_PLUGIN,
				$plugin_data['Name'] ?? $domain,
				$plugin_data['Version'] ?? '0.0.0',
				$source_path,
				$pot_file,
				$locales,
				$string_count,
				$completed
			);
		}
	}

	/**
	 * Discover theme bundles from installed themes.
	 *
	 * @param Bundle[] &$bundles Array to append to (by reference).
	 */
	private function discoverThemes( array &$bundles ): void {
		$themes = wp_get_themes();

		foreach ( $themes as $slug => $theme ) {
			/** @var \WP_Theme $theme */
			$domain = $theme->get( 'TextDomain' ) ?: $slug;

			if ( isset( $bundles[ $domain ] ) ) {
				continue;
			}

			$source_path = $theme->get_stylesheet_directory();
			$files       = $this->discovery->findFiles( $domain, $source_path );
			$pot_file    = $files['pot'][0] ?? '';
			$locales     = $this->extractLocales( $files );

			$string_count = 0;
			if ( '' !== $pot_file && file_exists( $pot_file ) ) {
				$string_count = $this->countPotStrings( $pot_file );
			}

			$completed = $this->countCompleted( $files );

			$bundles[ $domain ] = new Bundle(
				$domain,
				Bundle::TYPE_THEME,
				$theme->get( 'Name' ) ?: $domain,
				$theme->get( 'Version' ) ?: '0.0.0',
				$source_path,
				$pot_file,
				$locales,
				$string_count,
				$completed
			);
		}
	}

	/**
	 * Discover the core WordPress translation bundle.
	 *
	 * @param Bundle[] &$bundles Array to append to (by reference).
	 */
	private function discoverCore( array &$bundles ): void {
		$wp_lang_dir = WP_LANG_DIR;
		$core_pot    = '';

		// Check for core POT file.
		$core_pot_candidates = array(
			$wp_lang_dir . '/wordpress.pot',
			ABSPATH . 'wordpress.pot',
		);

		foreach ( $core_pot_candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				$core_pot = $candidate;
				break;
			}
		}

		// Find core PO/MO files in wp-content/languages/.
		$locales = array();

		if ( is_dir( $wp_lang_dir ) ) {
			try {
				$iterator = new \DirectoryIterator( $wp_lang_dir );
				foreach ( $iterator as $file_info ) {
					if ( ! $file_info->isFile() ) {
						continue;
					}

					$filename = $file_info->getFilename();

					// Match {locale}.po pattern (e.g. "fr_FR.po").
					if ( preg_match( '/^([a-z]{2,3}_[A-Z]{2})\.po$/', $filename, $m ) ) {
						$locales[] = $m[1];
					}
				}
			} catch ( \UnexpectedValueException $e ) {
				// Directory not accessible.
			}
		}

		$locales = array_unique( $locales );

		if ( empty( $locales ) && '' === $core_pot ) {
			// No core translations found; skip core bundle.
			return;
		}

		$string_count = 0;
		if ( '' !== $core_pot ) {
			$string_count = $this->countPotStrings( $core_pot );
		}

		$bundles['default'] = new Bundle(
			'default',
			Bundle::TYPE_CORE,
			'WordPress',
			get_bloginfo( 'version' ),
			ABSPATH,
			$core_pot,
			$locales,
			$string_count,
			count( $locales )
		);
	}

	/**
	 * Extract locale codes from a discovered file set.
	 *
	 * @param array $files File discovery result from FileDiscoveryService.
	 * @return string[]
	 */
	private function extractLocales( array $files ): array {
		$locales = array();

		foreach ( $files['po'] as $locale => $po_files ) {
			if ( ! empty( $po_files ) ) {
				$locales[] = $locale;
			}
		}

		// Include locales that have MO but no PO.
		foreach ( $files['mo'] as $locale => $mo_files ) {
			if ( ! empty( $mo_files ) && ! in_array( $locale, $locales, true ) ) {
				$locales[] = $locale;
			}
		}

		return array_unique( $locales );
	}

	/**
	 * Count translatable strings in a POT file.
	 *
	 * Counts msgid entries (excluding the header entry with empty msgid).
	 *
	 * @param string $pot_file Absolute path to POT file.
	 * @return int
	 */
	private function countPotStrings( string $pot_file ): int {
		$content = file_get_contents( $pot_file );

		if ( false === $content ) {
			return 0;
		}

		// Count non-empty msgid lines.
		$count = preg_match_all( '/^msgid\s+"(.+)"$/m', $content );

		return false === $count ? 0 : $count;
	}

	/**
	 * Count fully completed locales (where every PO msgstr is non-empty).
	 *
	 * Uses a lightweight heuristic: counts msgstr "" (empty) lines.
	 * A locale is "completed" if it has fewer than 10% empty translations.
	 *
	 * @param array $files File discovery result.
	 * @return int
	 */
	private function countCompleted( array $files ): int {
		$completed = 0;

		foreach ( $files['po'] as $locale => $po_files ) {
			foreach ( $po_files as $po_file ) {
				if ( ! file_exists( $po_file ) ) {
					continue;
				}

				$content = file_get_contents( $po_file );
				if ( false === $content ) {
					continue;
				}

				$total    = preg_match_all( '/^msgid\s+"(.+)"$/m', $content ) ?: 0;
				$empty    = preg_match_all( '/^msgstr\s+""$/m', $content ) ?: 0;

				// Account for the header entry (empty msgid has a msgstr "").
				$empty = max( 0, $empty - 1 );

				if ( $total > 0 && ( $empty / $total ) < 0.1 ) {
					++$completed;
				}
			}
		}

		return $completed;
	}

	/**
	 * Hydrate cached bundle data back into value objects.
	 *
	 * @param array $cached Serialised bundle data keyed by domain.
	 * @return Bundle[]
	 */
	private function hydrate( array $cached ): array {
		$bundles = array();

		foreach ( $cached as $domain => $data ) {
			$bundles[ $domain ] = Bundle::fromArray( $data );
		}

		return $bundles;
	}
}
