<?php
/**
 * WP-CLI file translation commands for NovaTools Polyglot.
 *
 * Provides `wp polyglot file extract|compile|sync` subcommands for
 * extracting translatable strings from source code, compiling PO files
 * to MO/PHP/JSON, and synchronising between file-based and database
 * string translations.
 *
 * @package NovaTools\Polyglot\Cli
 */

namespace NovaTools\Polyglot\Cli;

use NovaTools\Polyglot\Core\Plugin;
use NovaTools\Polyglot\FileTranslation\PoUtility;
use NovaTools\Polyglot\FileTranslation\FileDiscoveryService;
use NovaTools\Polyglot\FileTranslation\PoImporter;
use NovaTools\Polyglot\String\StringManager;
use NovaTools\Polyglot\String\StringRepository;
use NovaTools\Polyglot\Database\Schema;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class FileCommand {

	/**
	 * Extract translatable strings from source files.
	 *
	 * Scans PHP and JavaScript source files for WordPress i18n function
	 * calls (`__()`, `_e()`, `_x()`, `_n()`, etc.) and reports the results.
	 * Optionally writes a POT file.
	 *
	 * ## OPTIONS
	 *
	 * [<path>]
	 * : Directory or file to scan. Defaults to the current theme directory.
	 *
	 * [--domain=<domain>]
	 * : Text domain to filter by. When empty, strings from all domains are included.
	 *
	 * [--output=<file>]
	 * : Write results to a POT file at this path instead of displaying them.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * [--register]
	 * : Register extracted strings into the database.
	 *
	 * [--import-translations]
	 * : When used with --register, also discover and import PO file translations.
	 *
	 * ## EXAMPLES
	 *
	 *     # Extract strings from a plugin
	 *     wp polyglot file extract wp-content/plugins/my-plugin
	 *
	 *     # Extract and write a POT file
	 *     wp polyglot file extract wp-content/plugins/my-plugin --domain=my-plugin --output=languages/my-plugin.pot
	 *
	 *     # Show only counts per domain
	 *     wp polyglot file extract wp-content/themes/mytheme --format=count
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function extract( array $args, array $assoc_args ): void {
		$path   = $args[0] ?? get_stylesheet_directory();
		$domain = $assoc_args['domain'] ?? '';
		$output = $assoc_args['output'] ?? '';
		$format = $assoc_args['format'] ?? 'table';

		if ( ! is_dir( $path ) && ! is_file( $path ) ) {
			WP_CLI::error( sprintf( 'Path not found: %s', $path ) );
		}

		$plugin = Plugin::getInstance();
		/** @var \NovaTools\Polyglot\FileTranslation\StringExtractor $extractor */
		$extractor = $plugin->get( 'string.extractor' );

		$base_path = is_dir( $path ) ? $path : dirname( $path );

		WP_CLI::log( sprintf( 'Scanning %s for translatable strings...', $path ) );

		$result = $extractor->extract( $path, $domain, $base_path );

		$strings = $result['strings'];
		$total   = $result['total'];

		if ( 0 === $total ) {
			WP_CLI::success( 'No translatable strings found.' );
			return;
		}

		// Write POT file if requested.
		if ( '' !== $output ) {
			$written = PoUtility::writePotFile( $strings, $output, $domain );

			if ( $written ) {
				WP_CLI::success( sprintf( 'POT file written to %s (%d strings).', $output, count( $strings ) ) );
			} else {
				WP_CLI::warning( sprintf( 'Failed to write POT file to %s.', $output ) );
			}

			return;
		}

		if ( 'count' === $format ) {
			foreach ( $result['domain_counts'] as $dom => $count ) {
				WP_CLI::log( sprintf( '  %s: %d strings', $dom, $count ) );
			}

			WP_CLI::success( sprintf( 'Total: %d strings across %d domains.', $total, count( $result['domain_counts'] ) ) );
			return;
		}

		// Build table items.
		$items = array();

		foreach ( $strings as $key => $entry ) {
			$items[] = array(
				'domain'     => $entry['domain'] ?: '(empty)',
				'msgid'      => $entry['msgid'],
				'msgctxt'    => $entry['msgctxt'],
				'plural'     => $entry['msgid_plural'],
				'references' => implode( ', ', $entry['references'] ),
			);
		}

		$fields = 'domain,msgid,msgctxt,plural,references';

		if ( 'json' === $format || 'yaml' === $format ) {
			// Use simplified output for structured formats.
			$simplified = array_map( static function ( array $entry ): array {
				return array(
					'domain'     => $entry['domain'] ?: '(empty)',
					'msgid'      => $entry['msgid'],
					'context'    => $entry['msgctxt'],
					'plural'     => $entry['msgid_plural'],
					'references' => $entry['references'],
				);
			}, $strings );
		} else {
			$simplified = $items;
		}

		WP_CLI\Utils\format_items( $format, $simplified, explode( ',', $fields ) );
		WP_CLI::log( sprintf( 'Total: %d unique strings across %d domains.', count( $strings ), count( $result['domain_counts'] ) ) );

		// Register strings into the database if --register flag is set.
		$should_register = \WP_CLI\Utils\get_flag_value( $assoc_args, 'register', false );

		if ( $should_register ) {
			/** @var \NovaTools\Polyglot\String\StringManager $manager */
			$manager = $plugin->get( 'string.manager' );

			$registered = 0;
			$updated    = 0;

			foreach ( $strings as $entry ) {
				$string_domain = $entry['domain'];

				if ( empty( $string_domain ) && ! empty( $domain ) ) {
					$string_domain = $domain;
				}

				if ( empty( $string_domain ) ) {
					continue;
				}

				$hash    = md5( $string_domain . '|' . $entry['msgctxt'] . '|' . $entry['msgid'] );
				$repo    = $plugin->get( 'string.repository' );
				$existing = $repo->findByHash( $hash );

				$manager->registerString(
					$string_domain,
					$entry['msgid'],
					$entry['msgid'],
					$entry['msgctxt']
				);

				if ( $existing ) {
					++$updated;
				} else {
					++$registered;
				}
			}

			WP_CLI::log( sprintf( 'Registered: %d, Updated: %d', $registered, $updated ) );

			// Import translations if --import-translations flag is set.
			$should_import = \WP_CLI\Utils\get_flag_value( $assoc_args, 'import-translations', false );

			if ( $should_import ) {
				$detected_domain = $domain;

				if ( empty( $detected_domain ) ) {
					$detected_domain = $this->detectDomainFromPath( $base_path );
				}

				if ( ! empty( $detected_domain ) ) {
					/** @var \NovaTools\Polyglot\FileTranslation\FileDiscoveryService $discovery */
					$discovery = $plugin->get( 'file.discovery' );
					/** @var \NovaTools\Polyglot\FileTranslation\PoImporter $importer */
					$importer = $plugin->get( 'po.importer' );

					$discovered = $discovery->findFiles( $detected_domain, $base_path );
					$po_files   = $discovered['po'] ?? array();

					if ( empty( $po_files ) ) {
						WP_CLI::log( 'No PO files found for translation import.' );
					} else {
						$total_imported = 0;

						foreach ( $po_files as $locale => $locale_files ) {
							foreach ( $locale_files as $po_file ) {
								$result = $importer->import( $po_file, $detected_domain, $locale );
								$total_imported += $result['translations_imported'];
								WP_CLI::log( sprintf( '  %s: %d translations imported', $locale, $result['translations_imported'] ) );
							}
						}

						WP_CLI::success( sprintf( 'Imported %d translations from %d locale(s).', $total_imported, count( $po_files ) ) );
					}
				} else {
					WP_CLI::warning( 'Could not determine text domain for translation import. Use --domain flag.' );
				}
			}

			WP_CLI::success( 'Registration complete.' );
		}
	}

	/**
	 * Compile PO files to MO, PHP, and JSON formats.
	 *
	 * Parses a PO file and compiles it into the corresponding binary `.mo`,
	 * `.l10n.php`, and (when JS references exist) JSON translation files.
	 *
	 * ## OPTIONS
	 *
	 * <po-file>
	 * : Path to the PO file to compile.
	 *
	 * [--domain=<domain>]
	 * : Text domain. Defaults to the filename-derived domain.
	 *
	 * [--locale=<locale>]
	 * : WordPress locale (e.g. "fr_FR"). Defaults to the filename-derived locale.
	 *
	 * [--format=<format>]
	 * : Output format(s). Comma-separated for multiple.
	 * ---
	 * default: mo,php
	 * options:
	 *   - mo
	 *   - php
	 *   - json
	 *   - mo,php
	 *   - mo,php,json
	 *   - all
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Compile a PO file to MO and PHP
	 *     wp polyglot file compile languages/my-plugin-fr_FR.po
	 *
	 *     # Compile all formats including JSON
	 *     wp polyglot file compile languages/my-plugin-fr_FR.po --format=all
	 *
	 *     # Compile only MO
	 *     wp polyglot file compile languages/my-plugin-fr_FR.po --format=mo
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function compile( array $args, array $assoc_args ): void {
		$po_file = $args[0] ?? '';

		if ( '' === $po_file || ! is_readable( $po_file ) ) {
			WP_CLI::error( 'PO file path is required and must be readable.' );
		}

		$plugin = Plugin::getInstance();
		/** @var \NovaTools\Polyglot\FileTranslation\PoFileParser $parser */
		$parser = $plugin->get( 'po.parser' );
		/** @var \NovaTools\Polyglot\FileTranslation\PoMoCompiler $compiler */
		$compiler = $plugin->get( 'po.compiler' );

		// Parse the PO file.
		try {
			$po_data = $parser->parse( $po_file );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( sprintf( 'Failed to parse PO file: %s', $e->getMessage() ) );
		}

		$entry_count = count( $po_data['entries'] ?? array() );
		WP_CLI::log( sprintf( 'Parsed %d entries from %s', $entry_count, basename( $po_file ) ) );

		// Derive domain and locale from filename if not provided.
		$domain = $assoc_args['domain'] ?? PoUtility::deriveDomain( $po_file );
		$locale = $assoc_args['locale'] ?? PoUtility::deriveLocale( $po_file, $domain );

		$format_spec = $assoc_args['format'] ?? 'mo,php';

		if ( 'all' === $format_spec ) {
			$formats = array( 'mo', 'php', 'json' );
		} else {
			$formats = array_map( 'trim', explode( ',', $format_spec ) );
		}

		$dir = dirname( $po_file );

		// Compile MO.
		if ( in_array( 'mo', $formats, true ) ) {
			$mo_path = $dir . '/' . pathinfo( $po_file, PATHINFO_FILENAME ) . '.mo';
			$result  = $compiler->compileMo( $po_data, $mo_path );

			if ( $result ) {
				WP_CLI::log( sprintf( '  ✓ MO: %s', basename( $mo_path ) ) );
			} else {
				WP_CLI::warning( sprintf( '  ✗ Failed to write MO: %s', basename( $mo_path ) ) );
			}
		}

		// Compile PHP (.l10n.php).
		if ( in_array( 'php', $formats, true ) ) {
			$php_path = $dir . '/' . pathinfo( $po_file, PATHINFO_FILENAME ) . '.l10n.php';
			$result   = $compiler->compilePhp( $po_data, $php_path );

			if ( $result ) {
				WP_CLI::log( sprintf( '  ✓ PHP: %s', basename( $php_path ) ) );
			} else {
				WP_CLI::warning( sprintf( '  ✗ Failed to write PHP: %s', basename( $php_path ) ) );
			}
		}

		// Compile JSON.
		if ( in_array( 'json', $formats, true ) ) {
			$json_files = $compiler->compileJson( $po_data, $dir, $domain, $locale );

			if ( empty( $json_files ) ) {
				WP_CLI::log( '  - No JS-referenced strings found; no JSON files generated.' );
			} else {
				foreach ( $json_files as $json_path ) {
					WP_CLI::log( sprintf( '  ✓ JSON: %s', basename( $json_path ) ) );
				}
			}
		}

		WP_CLI::success( sprintf(
			'Compilation complete for %s (%s, %s).',
			basename( $po_file ),
			$domain,
			$locale
		) );
	}

	/**
	 * Synchronise between file-based and database string translations.
	 *
	 * Provides bidirectional sync: import PO file strings into the database,
	 * or export database strings to a PO file.
	 *
	 * ## OPTIONS
	 *
	 * <direction>
	 * : Sync direction.
	 * ---
	 * options:
	 *   - import
	 *   - export
	 * ---
	 *
	 * [--domain=<domain>]
	 * : Text domain to sync. Required.
	 *
	 * [--language=<code>]
	 * : Language code for the sync operation. Required.
	 *
	 * [--po-file=<file>]
	 * : Path to the PO file. Required for import.
	 *
	 * [--output=<file>]
	 * : Output path for the exported PO file. Defaults to WP languages directory.
	 *
	 * ## EXAMPLES
	 *
	 *     # Import a PO file into the database
	 *     wp polyglot file sync import --domain=mytheme --language=fr --po-file=languages/mytheme-fr_FR.po
	 *
	 *     # Export database strings to a PO file
	 *     wp polyglot file sync export --domain=mytheme --language=fr --output=mytheme-fr_FR.po
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sync( array $args, array $assoc_args ): void {
		$direction = $args[0] ?? '';
		$domain    = $assoc_args['domain'] ?? '';
		$language  = $assoc_args['language'] ?? '';

		if ( ! in_array( $direction, array( 'import', 'export' ), true ) ) {
			WP_CLI::error( 'Direction must be "import" or "export".' );
		}

		if ( '' === $domain ) {
			WP_CLI::error( '--domain is required.' );
		}

		if ( '' === $language ) {
			WP_CLI::error( '--language is required.' );
		}

		$plugin = Plugin::getInstance();

		if ( 'import' === $direction ) {
			$po_file = $assoc_args['po-file'] ?? '';

			if ( '' === $po_file || ! is_readable( $po_file ) ) {
				WP_CLI::error( '--po-file is required for import and must be readable.' );
			}

			/** @var \NovaTools\Polyglot\FileTranslation\PoImporter $importer */
			$importer = $plugin->get( 'po.importer' );

			WP_CLI::log( sprintf( 'Importing PO file %s (domain: %s, language: %s)...', basename( $po_file ), $domain, $language ) );

			$result = $importer->import( $po_file, $domain, $language );

			WP_CLI::log( sprintf( '  Strings imported: %d', $result['strings_imported'] ) );
			WP_CLI::log( sprintf( '  Translations imported: %d', $result['translations_imported'] ) );
			WP_CLI::log( sprintf( '  Strings skipped: %d', $result['strings_skipped'] ) );

			if ( ! empty( $result['errors'] ) ) {
				foreach ( $result['errors'] as $error ) {
					WP_CLI::warning( sprintf( '  Error: %s', $error ) );
				}
			}

			WP_CLI::success( 'Import complete.' );
		}

		if ( 'export' === $direction ) {
			$output = $assoc_args['output'] ?? '';

			if ( '' === $output ) {
				// Default to WordPress languages directory.
				$lang_dir = WP_LANG_DIR . '/plugins/';
				$output   = $lang_dir . $domain . '-' . $language . '.po';
			}

			/** @var \NovaTools\Polyglot\FileTranslation\PoExporter $exporter */
			$exporter = $plugin->get( 'po.exporter' );

			WP_CLI::log( sprintf( 'Exporting domain "%s" (%s) to %s...', $domain, $language, $output ) );

			$result = $exporter->export( $domain, $language, $output );

			WP_CLI::log( sprintf( '  Entries: %d', $result['entries'] ) );
			WP_CLI::log( sprintf( '  Translated: %d', $result['translated'] ) );

			if ( false !== $result['bytes'] ) {
				WP_CLI::log( sprintf( '  Written: %s bytes', number_format( $result['bytes'] ) ) );
				WP_CLI::success( sprintf( 'Export complete: %s', $output ) );
			} else {
				WP_CLI::error( sprintf( 'Failed to write PO file: %s', $output ) );
			}
		}
	}

	/**
	 * Detect stale strings for a domain.
	 *
	 * Scans the source directory and compares discovered strings against
	 * the database. Strings registered in the database but not found in
	 * the source are reported as stale.
	 *
	 * ## OPTIONS
	 *
	 * [<path>]
	 * : Directory to scan. Defaults to the current theme directory.
	 *
	 * [--domain=<domain>]
	 * : Text domain to check. Auto-detected if omitted.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Detect stale strings for a plugin
	 *     wp polyglot file detect-stale wp-content/plugins/my-plugin --domain=my-plugin
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function detect_stale( array $args, array $assoc_args ): void {
		$path   = $args[0] ?? get_stylesheet_directory();
		$domain = $assoc_args['domain'] ?? '';
		$format = $assoc_args['format'] ?? 'table';

		if ( ! is_dir( $path ) ) {
			WP_CLI::error( sprintf( 'Directory not found: %s', $path ) );
		}

		$plugin = Plugin::getInstance();
		/** @var \NovaTools\Polyglot\FileTranslation\StringExtractor $extractor */
		$extractor = $plugin->get( 'string.extractor' );

		WP_CLI::log( sprintf( 'Scanning %s for stale strings...', $path ) );

		$result = $extractor->extract( $path, $domain );

		$detected_domain = $domain;
		if ( empty( $detected_domain ) ) {
			$detected_domain = $this->detectDomainFromPath( $path );
		}

		if ( empty( $detected_domain ) ) {
			WP_CLI::error( 'Could not determine text domain. Use --domain flag.' );
		}

		$current_hashes = array();
		foreach ( $result['strings'] as $entry ) {
			$string_domain = $entry['domain'] ?: $detected_domain;
			$current_hashes[] = md5( $string_domain . '|' . $entry['msgctxt'] . '|' . $entry['msgid'] );
		}

		global $wpdb;
		$table = Schema::getTableName( 'polyglot_strings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$registered = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, hash, name, domain, value FROM {$table} WHERE domain = %s",
				$detected_domain
			),
			ARRAY_A
		);

		$stale = array();
		foreach ( $registered as $row ) {
			if ( ! in_array( $row['hash'], $current_hashes, true ) ) {
				$stale[] = $row;
			}
		}

		if ( empty( $stale ) ) {
			WP_CLI::success( 'No stale strings found.' );
			return;
		}

		if ( 'count' === $format ) {
			WP_CLI::log( sprintf( 'Stale strings: %d', count( $stale ) ) );
			return;
		}

		$items = array_map( static function ( array $row ): array {
			return array(
				'id'     => $row['id'],
				'domain' => $row['domain'],
				'name'   => $row['name'],
				'value'  => $row['value'] ?? '',
			);
		}, $stale );

		WP_CLI\Utils\format_items( $format, $items, array( 'id', 'domain', 'name', 'value' ) );
		WP_CLI::log( sprintf( 'Total: %d stale string(s) in domain "%s".', count( $stale ), $detected_domain ) );
	}

	/**
	 * Detect text domain from a plugin/theme directory.
	 *
	 * @param string $directory Directory path.
	 * @return string Detected domain or empty string.
	 */
	private function detectDomainFromPath( string $directory ): string {
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
