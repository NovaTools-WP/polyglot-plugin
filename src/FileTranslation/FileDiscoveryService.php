<?php
/**
 * Translation file discovery service for NovaTools Polyglot.
 *
 * Discovers existing PO/MO/POT files for installed themes and plugins by
 * scanning standard WordPress language directories and plugin/theme bundled
 * language directories.
 *
 * Follows the WordPress language file convention:
 *   - `wp-content/languages/`           — Core translations.
 *   - `wp-content/languages/plugins/`   — Plugin translations.
 *   - `wp-content/languages/themes/`    — Theme translations.
 *   - Plugin/theme `languages/` dirs    — Bundled translations.
 *
 * @package NovaTools\Polyglot\FileTranslation
 */

namespace NovaTools\Polyglot\FileTranslation;

defined( 'ABSPATH' ) || exit;

class FileDiscoveryService {

	/**
	 * File extensions considered as translation source files.
	 *
	 * @var string[]
	 */
	const SOURCE_EXTENSIONS = array( 'pot' );

	/**
	 * File extensions considered as translation files.
	 *
	 * @var string[]
	 */
	const TRANSLATION_EXTENSIONS = array( 'po', 'mo', 'php', 'json' );

	/**
	 * Standard WordPress language subdirectories.
	 *
	 * @var string[]
	 */
	const WP_LANG_DIRS = array(
		'plugins',
		'themes',
	);

	/**
	 * Find all translation files for a given text domain.
	 *
	 * Searches both the WordPress core languages directory and the
	 * plugin/theme's own `languages/` or `lang/` directory.
	 *
	 * @param string      $domain Text domain (e.g. "woocommerce").
	 * @param string|null $source_path Optional source path of the plugin/theme.
	 * @return array{
	 *     pot: string[],
	 *     po: array<string, string[]>,
	 *     mo: array<string, string[]>,
	 *     php: array<string, string[]>,
	 *     json: string[]
	 * }
	 */
	public function findFiles( string $domain, ?string $source_path = null ): array {
		$result = array(
			'pot'  => array(),
			'po'   => array(),
			'mo'   => array(),
			'php'  => array(),
			'json' => array(),
		);

		// Search WordPress core language directories.
		$wp_lang_dir = $this->getWpLangDir();

		// Plugin translation files: wp-content/languages/plugins/{domain}-*.po
		$plugin_dir = $wp_lang_dir . 'plugins/';
		$this->scanDirectory( $plugin_dir, $domain, $result );

		// Theme translation files: wp-content/languages/themes/{domain}-*.po
		$theme_dir = $wp_lang_dir . 'themes/';
		$this->scanDirectory( $theme_dir, $domain, $result );

		// Core translation files: wp-content/languages/{locale}.po
		$this->scanDirectory( $wp_lang_dir, $domain, $result, true );

		// Bundled translations in the plugin/theme directory.
		if ( null !== $source_path && is_dir( $source_path ) ) {
			$this->scanBundledLanguages( $source_path, $domain, $result );
		}

		return $result;
	}

	/**
	 * Find all translation files for all installed plugins and themes.
	 *
	 * Returns a map of text domain → file list.
	 *
	 * @return array<string, array> Domain → file list (same structure as findFiles()).
	 */
	public function findAll(): array {
		$all = array();

		// Discover plugin translation files.
		$plugins = get_plugins();
		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$domain = $plugin_data['TextDomain'] ?? '';
			if ( '' === $domain ) {
				$domain = dirname( $plugin_file );
			}

			if ( ! isset( $all[ $domain ] ) ) {
				$source_path = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
				$all[ $domain ] = $this->findFiles( $domain, $source_path );
			}
		}

		// Discover theme translation files.
		$themes = wp_get_themes();
		foreach ( $themes as $slug => $theme ) {
			$domain = $theme->get( 'TextDomain' ) ?: $slug;

			if ( ! isset( $all[ $domain ] ) ) {
				$all[ $domain ] = $this->findFiles( $domain, $theme->get_stylesheet_directory() );
			}
		}

		return $all;
	}

	/**
	 * Find available locales for a given text domain.
	 *
	 * @param string      $domain      Text domain.
	 * @param string|null $source_path Optional source path.
	 * @return string[] Array of locale strings (e.g. ["fr_FR", "de_DE"]).
	 */
	public function findLocales( string $domain, ?string $source_path = null ): array {
		$files   = $this->findFiles( $domain, $source_path );
		$locales = array();

		foreach ( $files['po'] as $locale => $po_files ) {
			if ( ! empty( $po_files ) ) {
				$locales[] = $locale;
			}
		}

		// Also check MO files (may not have PO equivalents).
		foreach ( $files['mo'] as $locale => $mo_files ) {
			if ( ! empty( $mo_files ) && ! in_array( $locale, $locales, true ) ) {
				$locales[] = $locale;
			}
		}

		return $locales;
	}

	/**
	 * Get the path to a specific translation file.
	 *
	 * @param string $domain Text domain.
	 * @param string $locale WordPress locale.
	 * @param string $type   File type ('po', 'mo', 'php', 'pot').
	 * @return string|null Absolute file path or null if not found.
	 */
	public function getFilePath( string $domain, string $locale, string $type = 'po' ): ?string {
		$files = $this->findFiles( $domain );

		if ( 'pot' === $type ) {
			return $files['pot'][0] ?? null;
		}

		if ( isset ( $files[ $type ][ $locale ] ) ) {
			return $files[ $type ][ $locale ][0] ?? null;
		}

		return null;
	}

	/**
	 * Scan a WordPress language directory for translation files.
	 *
	 * @param string $dir        Directory path.
	 * @param string $domain     Text domain.
	 * @param array  $result     Result array to populate (by reference).
	 * @param bool   $is_core    Whether scanning the core language directory.
	 */
	private function scanDirectory( string $dir, string $domain, array &$result, bool $is_core = false ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$prefix = $is_core ? '' : $domain . '-';

		try {
			$iterator = new \DirectoryIterator( $dir );
		} catch ( \UnexpectedValueException $e ) {
			return;
		}

		foreach ( $iterator as $file_info ) {
			/** @var \SplFileInfo $file_info */
			if ( ! $file_info->isFile() ) {
				continue;
			}

			$filename = $file_info->getFilename();
			$ext      = strtolower( $file_info->getExtension() );

			if ( ! in_array( $ext, array_merge( self::SOURCE_EXTENSIONS, self::TRANSLATION_EXTENSIONS ), true ) ) {
				continue;
			}

			// Match domain prefix for plugin/theme files.
			if ( ! $is_core && 0 !== strpos( $filename, $prefix ) ) {
				continue;
			}

			// Core files: match {locale}.{ext} pattern.
			if ( $is_core && ! preg_match( '/^([a-z]{2,3}_[A-Z]{2})\.' . $ext . '$/', $filename ) ) {
				continue;
			}

			$path = $file_info->getPathname();

			// Route file to the right bucket.
			if ( 'pot' === $ext ) {
				$result['pot'][] = $path;
			} elseif ( 'json' === $ext ) {
				$result['json'][] = $path;
			} else {
				// Extract locale from filename.
				$locale = $this->extractLocale( $filename, $domain, $is_core );
				if ( null !== $locale ) {
					if ( ! isset( $result[ $ext ][ $locale ] ) ) {
						$result[ $ext ][ $locale ] = array();
					}
					$result[ $ext ][ $locale ][] = $path;
				}
			}
		}
	}

	/**
	 * Scan bundled language directories inside a plugin or theme.
	 *
	 * @param string $source_path Plugin or theme root directory.
	 * @param string $domain      Text domain.
	 * @param array  $result      Result array to populate (by reference).
	 */
	private function scanBundledLanguages( string $source_path, string $domain, array &$result ): void {
		// Check common language directory names.
		$lang_dirs = array( 'languages', 'lang', 'i18n', 'locale' );

		foreach ( $lang_dirs as $lang_dir ) {
			$path = rtrim( $source_path, '/' ) . '/' . $lang_dir;
			if ( ! is_dir( $path ) ) {
				continue;
			}

			try {
				$iterator = new \DirectoryIterator( $path );
			} catch ( \UnexpectedValueException $e ) {
				continue;
			}

			foreach ( $iterator as $file_info ) {
				if ( ! $file_info->isFile() ) {
					continue;
				}

				$filename = $file_info->getFilename();
				$ext      = strtolower( $file_info->getExtension() );

				if ( ! in_array( $ext, array_merge( self::SOURCE_EXTENSIONS, self::TRANSLATION_EXTENSIONS ), true ) ) {
					continue;
				}

				$file_path = $file_info->getPathname();

				if ( 'pot' === $ext ) {
					$result['pot'][] = $file_path;
				} elseif ( 'json' === $ext ) {
					$result['json'][] = $file_path;
				} else {
					$locale = $this->extractLocaleFromBundled( $filename, $domain );
					if ( null !== $locale ) {
						if ( ! isset( $result[ $ext ][ $locale ] ) ) {
							$result[ $ext ][ $locale ] = array();
						}
						$result[ $ext ][ $locale ][] = $file_path;
					}
				}
			}
		}
	}

	/**
	 * Extract locale string from a WordPress language filename.
	 *
	 * WordPress convention: `{domain}-{locale}.{ext}` for plugins/themes,
	 * or `{locale}.{ext}` for core.
	 *
	 * @param string $filename  Filename.
	 * @param string $domain    Text domain.
	 * @param bool   $is_core   Whether this is a core file.
	 * @return string|null Locale string or null.
	 */
	private function extractLocale( string $filename, string $domain, bool $is_core = false ): ?string {
		// Remove extension.
		$basename = pathinfo( $filename, PATHINFO_FILENAME );

		if ( $is_core ) {
			// Core: "fr_FR" from "fr_FR.po".
			return $basename;
		}

		// Plugin/theme: "fr_FR" from "woocommerce-fr_FR.po".
		$prefix = $domain . '-';
		if ( 0 === strpos( $basename, $prefix ) ) {
			$locale = substr( $basename, strlen( $prefix ) );

			// Validate locale format.
			if ( preg_match( '/^[a-z]{2,3}_[A-Z]{2}/', $locale ) ) {
				return $locale;
			}
		}

		return null;
	}

	/**
	 * Extract locale from a bundled translation filename.
	 *
	 * Bundled files may use patterns like `{domain}-{locale}`, `{locale}`,
	 * or `{domain}-{locale}-{handle}` (for JSON).
	 *
	 * @param string $filename Filename.
	 * @param string $domain   Text domain.
	 * @return string|null
	 */
	private function extractLocaleFromBundled( string $filename, string $domain ): ?string {
		$basename = pathinfo( $filename, PATHINFO_FILENAME );

		// Pattern: {domain}-{locale}.
		$prefix = $domain . '-';
		if ( 0 === strpos( $basename, $prefix ) ) {
			$remainder = substr( $basename, strlen( $prefix ) );
			// Extract the locale portion (before any additional suffixes like -admin).
			if ( preg_match( '/^([a-z]{2,3}_[A-Z]{2})/', $remainder, $m ) ) {
				return $m[1];
			}
		}

		// Pattern: {locale} (standalone locale filename).
		if ( preg_match( '/^([a-z]{2,3}_[A-Z]{2})$/', $basename, $m ) ) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Get the WordPress content languages directory path.
	 *
	 * @return string Absolute path to `wp-content/languages/`.
	 */
	private function getWpLangDir(): string {
		return trailingslashit( WP_LANG_DIR );
	}
}
