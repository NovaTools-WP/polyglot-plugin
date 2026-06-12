<?php
/**
 * PO-to-MO/PHP/JSON compiler for NovaTools Polyglot.
 *
 * Compiles parsed PO data into binary `.mo` files suitable for WordPress
 * `load_textdomain()`, `.l10n.php` files (WordPress 6.x format), and
 * JSON files for `wp_set_script_translations()`.
 *
 * MO binary format follows the GNU Gettext specification. The PHP and JSON
 * output formats match the structures expected by WordPress core.
 *
 * Adapted from patterns in Loco Translate's `Loco_gettext_Compiler` and
 * `Loco_gettext_PhpCache`.
 *
 * @package NovaTools\Polyglot\FileTranslation
 */

namespace NovaTools\Polyglot\FileTranslation;

defined( 'ABSPATH' ) || exit;

class PoMoCompiler {

	/**
	 * MO file magic number indicating little-endian byte order.
	 *
	 * @var int
	 */
	const MAGIC_LE = 0x950412DE;

	/**
	 * MO file magic number indicating big-endian byte order.
	 *
	 * @var int
	 */
	const MAGIC_BE = 0xDE120495;

	/**
	 * Compile parsed PO data into a binary MO file.
	 *
	 * @param array  $po_data  Structured data from PoFileParser::parse().
	 * @param string $path     Absolute path for the output `.mo` file.
	 * @return bool True on success.
	 */
	public function compileMo( array $po_data, string $path ): bool {
		$mo_content = $this->buildMoBinary( $po_data );
		return false !== file_put_contents( $path, $mo_content );
	}

	/**
	 * Compile parsed PO data into a WordPress 6.x `.l10n.php` file.
	 *
	 * Produces a PHP file that returns an array of translations, compatible
	 * with WordPress's `WP_Translation_File_PHP` loader.
	 *
	 * @param array  $po_data Structured data from PoFileParser::parse().
	 * @param string $path    Absolute path for the output `.l10n.php` file.
	 * @return bool True on success.
	 */
	public function compilePhp( array $po_data, string $path ): bool {
		$php_content = $this->buildPhpContent( $po_data );
		return false !== file_put_contents( $path, $php_content );
	}

	/**
	 * Compile parsed PO data into JSON translation files for JavaScript.
	 *
	 * Generates one JSON file per script handle, following the WordPress
	 * naming convention: `{domain}-{handle}-{locale}.json`.
	 *
	 * @param array  $po_data Structured data from PoFileParser::parse().
	 * @param string $dir     Output directory for JSON files.
	 * @param string $domain  Text domain.
	 * @param string $locale  WordPress locale (e.g. "fr_FR").
	 * @return string[] List of generated file paths.
	 */
	public function compileJson(
		array $po_data,
		string $dir,
		string $domain,
		string $locale
	): array {
		$js_strings = $this->extractJsStrings( $po_data );
		$generated  = array();

		if ( empty( $js_strings ) ) {
			return $generated;
		}

		// Group strings by script handle if available, otherwise single file.
		$groups = array();

		foreach ( $js_strings as $entry ) {
			$handle = $this->extractScriptHandle( $entry['references'] );
			if ( ! isset( $groups[ $handle ] ) ) {
				$groups[ $handle ] = array();
			}
			$groups[ $handle ][] = $entry;
		}

		foreach ( $groups as $handle => $entries ) {
			$filename = sprintf( '%s-%s-%s.json', $domain, $handle, $locale );
			$filepath = rtrim( $dir, '/' ) . '/' . $filename;

			$jed = $this->buildJedFormat( $entries, $domain, $po_data['headers'] ?? array() );

			$result = file_put_contents( $filepath, wp_json_encode( $jed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

			if ( false !== $result ) {
				$generated[] = $filepath;
			}
		}

		return $generated;
	}

	/**
	 * Compile PO data and write all output formats at once.
	 *
	 * @param array  $po_data Structured data from PoFileParser::parse().
	 * @param string $po_path Path to the source PO file (used to derive output paths).
	 * @param string $domain  Text domain.
	 * @param string $locale  WordPress locale.
	 * @return array{mo: bool, php: bool, json: string[]} Results per format.
	 */
	public function compileAll( array $po_data, string $po_path, string $domain, string $locale ): array {
		$dir  = dirname( $po_path );
		$base = pathinfo( $po_path, PATHINFO_FILENAME );

		$mo_path  = $dir . '/' . $base . '.mo';
		$php_path = $dir . '/' . $base . '.l10n.php';

		return array(
			'mo'   => $this->compileMo( $po_data, $mo_path ),
			'php'  => $this->compilePhp( $po_data, $php_path ),
			'json' => $this->compileJson( $po_data, $dir, $domain, $locale ),
		);
	}

	/**
	 * Build the binary MO file content from parsed PO data.
	 *
	 * Follows the GNU Gettext MO file format:
	 *   - 4-byte magic number
	 *   - 4-byte format revision (0)
	 *   - Number of strings
	 *   - Offset to original strings table
	 *   - Offset to translated strings table
	 *   - Size of hash table
	 *   - Offset to hash table
	 *   - String tables (length + offset pairs)
	 *   - Actual string data
	 *
	 * @param array $po_data Structured data from PoFileParser::parse().
	 * @return string Binary MO content.
	 */
	private function buildMoBinary( array $po_data ): string {
		$headers = $po_data['headers'] ?? array();
		$entries = $po_data['entries'] ?? array();

		// Build header string.
		$header_parts = array();
		foreach ( $headers as $key => $value ) {
			$header_parts[] = $key . ': ' . $value;
		}
		$header_str = implode( "\n", $header_parts );

		// Collect all originals and translations.
		$originals    = array();
		$translations = array();

		// Add the header entry (empty msgid → header msgstr).
		$originals[]    = '';
		$translations[] = $header_str;

		foreach ( $entries as $entry ) {
			$originals[] = $this->buildMoOriginal( $entry );
			$translations[] = $this->buildMoTranslation( $entry );
		}

		$count = count( $originals );

		// Sort entries by original string (required by MO format).
		$indices = range( 0, $count - 1 );
		usort( $indices, static function ( $a, $b ) use ( $originals ): int {
			return strcmp( $originals[ $a ], $originals[ $b ] );
		});

		$sorted_originals    = array();
		$sorted_translations = array();

		foreach ( $indices as $i ) {
			$sorted_originals[]    = $originals[ $i ];
			$sorted_translations[] = $translations[ $i ];
		}

		// Calculate offsets.
		// Header: magic(4) + revision(4) + count(4) + orig_offset(4) +
		//         trans_offset(4) + hash_size(4) + hash_offset(4) = 28 bytes.
		$header_size     = 28;
		$entries_offset  = $header_size;
		$tables_size     = 8 * $count; // 4 bytes length + 4 bytes offset per entry.
		$strings_offset  = $entries_offset + ( 2 * $tables_size ); // Both tables.

		// Build string data and offset tables.
		$orig_table    = '';
		$trans_table   = '';
		$orig_strings  = '';
		$trans_strings = '';

		$orig_offset  = $strings_offset;
		$trans_offset = $strings_offset;

		for ( $i = 0; $i < $count; $i++ ) {
			$o = $sorted_originals[ $i ];
			$t = $sorted_translations[ $i ];

			$o_len = strlen( $o );
			$t_len = strlen( $t );

			// Originals table: length + offset.
			$orig_table .= pack( 'V', $o_len );
			$orig_table .= pack( 'V', $orig_offset );
			$orig_strings .= $o;
			$orig_offset += $o_len + 1; // +1 for NUL terminator.

			// Translations table: length + offset.
			$trans_table .= pack( 'V', $t_len );
			$trans_table .= pack( 'V', $trans_offset );
			$trans_strings .= $t;
			$trans_offset += $t_len + 1;
		}

		// Assemble the MO file.
		$mo  = pack( 'V', self::MAGIC_LE );               // Magic.
		$mo .= pack( 'V', 0 );                              // Revision.
		$mo .= pack( 'V', $count );                         // String count.
		$mo .= pack( 'V', $entries_offset );                // Originals table offset.
		$mo .= pack( 'V', $entries_offset + $tables_size ); // Translations table offset.
		$mo .= pack( 'V', 0 );                              // Hash table size (0 = no hash).
		$mo .= pack( 'V', 0 );                              // Hash table offset.

		$mo .= $orig_table;
		$mo .= $trans_table;
		$mo .= $orig_strings;
		$mo .= $trans_strings;

		return $mo;
	}

	/**
	 * Build the MO-format original string for an entry.
	 *
	 * Context is prepended with \x04 separator, plurals joined with \0.
	 *
	 * @param array $entry PO entry.
	 * @return string
	 */
	private function buildMoOriginal( array $entry ): string {
		$original = '';

		if ( ! empty( $entry['msgctxt'] ) ) {
			$original .= $entry['msgctxt'] . "\x04";
		}

		$original .= $entry['msgid'];

		if ( ! empty( $entry['msgid_plural'] ) ) {
			$original .= "\0" . $entry['msgid_plural'];
		}

		return $original;
	}

	/**
	 * Build the MO-format translation string for an entry.
	 *
	 * Plural translations are joined with \0.
	 *
	 * @param array $entry PO entry.
	 * @return string
	 */
	private function buildMoTranslation( array $entry ): string {
		if ( ! empty( $entry['msgid_plural'] ) ) {
			// Plural: join all msgstr[n] with \0.
			return implode( "\0", $entry['msgstr'] );
		}

		return $entry['msgstr'][0] ?? '';
	}

	/**
	 * Build a WordPress 6.x `.l10n.php` file content.
	 *
	 * @param array $po_data Structured data from PoFileParser::parse().
	 * @return string PHP file content.
	 */
	private function buildPhpContent( array $po_data ): string {
		$headers = $po_data['headers'] ?? array();
		$entries = $po_data['entries'] ?? array();

		$php_headers = array();
		foreach ( $headers as $key => $value ) {
			$php_headers[ strtolower( $key ) ] = $value;
		}

		$messages = array();

		foreach ( $entries as $entry ) {
			// Skip fuzzy entries.
			if ( ! empty( $entry['fuzzy'] ) ) {
				continue;
			}

			$key = $this->buildEntryKey( $entry );
			if ( '' === $key ) {
				continue;
			}

			if ( ! empty( $entry['msgid_plural'] ) ) {
				// Plural: join translations with \0 for PHP format.
				$plural_trans = array();
				foreach ( $entry['msgstr'] as $str ) {
					$plural_trans[] = $str;
				}
				$messages[ $key ] = implode( "\0", $plural_trans );
			} else {
				$val = $entry['msgstr'][0] ?? '';
				if ( '' !== $val ) {
					$messages[ $key ] = $val;
				}
			}
		}

		// Build PHP return array.
		$output = "<?php\nreturn array(\n";

		// Headers section.
		$output .= "'headers' => array(\n";
		foreach ( $php_headers as $key => $value ) {
			$output .= sprintf( "    '%s' => '%s',\n", $key, addslashes( $value ) );
		}
		$output .= "),\n\n";

		// Messages section.
		$output .= "'messages' => array(\n";
		foreach ( $messages as $key => $value ) {
			$output .= sprintf(
				"    '%s' => '%s',\n",
				addslashes( $key ),
				addslashes( $value )
			);
		}
		$output .= "),\n);\n";

		return $output;
	}

	/**
	 * Build the lookup key for a PO entry (used in PHP/JSON compilation).
	 *
	 * Context is prepended with \x04 separator, matching MO format.
	 *
	 * @param array $entry PO entry.
	 * @return string
	 */
	private function buildEntryKey( array $entry ): string {
		$key = $entry['msgid'];

		if ( '' === $key ) {
			return '';
		}

		if ( ! empty( $entry['msgctxt'] ) ) {
			$key = $entry['msgctxt'] . "\x04" . $key;
		}

		return $key;
	}

	/**
	 * Extract JavaScript-referenced strings from PO entries.
	 *
	 * Only entries with file references pointing to `.js` files are included
	 * in JSON output, following the WordPress `wp_set_script_translations()` model.
	 *
	 * @param array $po_data Parsed PO data.
	 * @return array Filtered entries with JS references.
	 */
	private function extractJsStrings( array $po_data ): array {
		$js_entries = array();

		foreach ( $po_data['entries'] ?? array() as $entry ) {
			$has_js = false;

			foreach ( $entry['references'] ?? array() as $ref ) {
				if ( preg_match( '/\.js(?:\?|$)/i', $ref ) ) {
					$has_js = true;
					break;
				}
			}

			if ( $has_js ) {
				$js_entries[] = $entry;
			}
		}

		return $js_entries;
	}

	/**
	 * Extract a script handle from file references.
	 *
	 * Attempts to derive a WordPress script handle from JS file references.
	 * Falls back to "index" if no handle can be determined.
	 *
	 * @param string[] $references File references from PO entry.
	 * @return string Script handle.
	 */
	private function extractScriptHandle( array $references ): string {
		foreach ( $references as $ref ) {
			if ( preg_match( '#(?:^|/)([a-zA-Z0-9_-]+)\.js#', $ref, $m ) ) {
				return $m[1];
			}
		}

		return 'index';
	}

	/**
	 * Build a JED (jQuery Gettext) format structure for JSON output.
	 *
	 * Compatible with WordPress's `wp_set_script_translations()` expectations.
	 *
	 * @param array  $entries PO entries for JS.
	 * @param string $domain  Text domain.
	 * @param array  $headers PO headers.
	 * @return array JED-format data structure.
	 */
	private function buildJedFormat( array $entries, string $domain, array $headers ): array {
		$messages = array();

		foreach ( $entries as $entry ) {
			$key = $entry['msgid'];

			if ( ! empty( $entry['msgctxt'] ) ) {
				$key = $entry['msgctxt'] . "\x04" . $key;
			}

			if ( ! empty( $entry['msgid_plural'] ) ) {
				$messages[ $key ] = $entry['msgstr'];
			} else {
				$messages[ $key ] = $entry['msgstr'][0] ?? '';
			}
		}

		return array(
			'translation-revision-date' => $headers['PO-Revision-Date'] ?? '',
			'generator'                 => 'NovaTools Polyglot',
			'domain'                    => $domain,
			'locale_data'               => array(
				$domain => array_merge(
					array( '' => array( 'domain' => $domain ) ),
					$messages
				),
			),
		);
	}
}
