<?php
/**
 * PO file parser for NovaTools Polyglot.
 *
 * Parses standard Gettext `.po` files including headers, msgid/msgstr pairs,
 * plural forms, and translator comments. Returns a structured array suitable
 * for compilation (MO/PHP/JSON) or database import.
 *
 * Adapted from patterns in Loco Translate's `Loco_gettext_Data` and the
 * bundled `LocoPoParser` in `lib/compiled/gettext.php`.
 *
 * @package NovaTools\Polyglot\FileTranslation
 */

namespace NovaTools\Polyglot\FileTranslation;

defined( 'ABSPATH' ) || exit;

class PoFileParser {

	/**
	 * Parse a PO file and return structured translation data.
	 *
	 * @param string $path Absolute path to the `.po` file.
	 * @return array{
	 *     headers: array<string, string>,
	 *     entries: array<int, array{
	 *         msgctxt: string,
	 *         msgid: string,
	 *         msgid_plural: string,
	 *         msgstr: string[],
	 *         comments: string,
	 *         extracted_comments: string,
	 *         references: string[],
	 *         flags: string[],
	 *         fuzzy: bool
	 *     }>
	 * }
	 *
	 * @throws \InvalidArgumentException If the file does not exist or is unreadable.
	 */
	public function parse( string $path ): array {
		if ( ! is_readable( $path ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'PO file not found or unreadable: %s', $path )
			);
		}

		$content = file_get_contents( $path );

		if ( false === $content ) {
			throw new \InvalidArgumentException(
				sprintf( 'Failed to read PO file: %s', $path )
			);
		}

		return $this->parseContent( $content );
	}

	/**
	 * Parse a PO file from a string.
	 *
	 * @param string $content Raw PO file content.
	 * @return array Same structure as `parse()`.
	 */
	public function parseContent( string $content ): array {
		$lines  = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $content ) );
		$entries = array();
		$headers = array();

		// Parser state.
		$current        = null;
		$key            = '';
		$plural_index   = 0;
		$is_header      = false;
		$line_number    = 0;

		foreach ( $lines as $line_number => $line ) {
			$line = rtrim( $line, "\n" );

			// ── Blank line: finalise the current entry ────────────────────
			if ( '' === trim( $line ) ) {
				if ( null !== $current ) {
					if ( $is_header && '' === $current['msgid'] ) {
						$headers = $this->parseHeaders( $current['msgstr'][0] ?? '' );
					} else {
						$entries[] = $current;
					}
					$current      = null;
					$is_header    = false;
				}
				continue;
			}

			// ── Translator / automatic comments ───────────────────────────
			if ( preg_match( '/^#\s*(.*)$/', $line, $m ) ) {
				$current = $current ?? $this->newEntry();
				$current['comments'] .= $m[1] . "\n";
				continue;
			}

			// ── Extracted comments (developer notes) ──────────────────────
			if ( preg_match( '/^#\.\s*(.*)$/', $line, $m ) ) {
				$current = $current ?? $this->newEntry();
				$current['extracted_comments'] .= $m[1] . "\n";
				continue;
			}

			// ── File references ───────────────────────────────────────────
			if ( preg_match( '/^#:\s*(.*)$/', $line, $m ) ) {
				$current = $current ?? $this->newEntry();
				foreach ( preg_split( '/\s+/', trim( $m[1] ) ) as $ref ) {
					if ( '' !== $ref ) {
						$current['references'][] = $ref;
					}
				}
				continue;
			}

			// ── Flags ─────────────────────────────────────────────────────
			if ( preg_match( '/^#,\s*(.*)$/', $line, $m ) ) {
				$current = $current ?? $this->newEntry();
				foreach ( array_map( 'trim', explode( ',', $m[1] ) ) as $flag ) {
					if ( '' !== $flag ) {
						$current['flags'][] = $flag;
						if ( 'fuzzy' === $flag ) {
							$current['fuzzy'] = true;
						}
					}
				}
				continue;
			}

			// ── msgctxt ───────────────────────────────────────────────────
			if ( preg_match( '/^msgctxt\s+"(.*)"$/s', $line, $m ) ) {
				$current          = $current ?? $this->newEntry();
				$current['msgctxt'] = $this->unescape( $m[1] );
				$key              = 'msgctxt';
				continue;
			}

			// ── msgid ─────────────────────────────────────────────────────
			if ( preg_match( '/^msgid\s+"(.*)"$/s', $line, $m ) ) {
				$current       = $current ?? $this->newEntry();
				$current['msgid'] = $this->unescape( $m[1] );
				$key           = 'msgid';

				// Empty msgid means this is the header entry.
				if ( '' === $current['msgid'] ) {
					$is_header = true;
				}
				continue;
			}

			// ── msgid_plural ──────────────────────────────────────────────
			if ( preg_match( '/^msgid_plural\s+"(.*)"$/s', $line, $m ) ) {
				$current['msgid_plural'] = $this->unescape( $m[1] );
				$key                     = 'msgid_plural';
				continue;
			}

			// ── msgstr[n] ────────────────────────────────────────────────
			if ( preg_match( '/^msgstr\[([0-9]+)\]\s+"(.*)"$/s', $line, $m ) ) {
				$plural_index                          = (int) $m[1];
				$current['msgstr'][ $plural_index ]     = $this->unescape( $m[2] );
				$key                                   = 'msgstr_plural';
				continue;
			}

			// ── msgstr (singular) ─────────────────────────────────────────
			if ( preg_match( '/^msgstr\s+"(.*)"$/s', $line, $m ) ) {
				$current['msgstr'][0] = $this->unescape( $m[1] );
				$key                 = 'msgstr';
				continue;
			}

			// ── Continuation line (quoted string) ─────────────────────────
			if ( preg_match( '/^"(.*)"$/s', $line, $m ) ) {
				$fragment = $this->unescape( $m[1] );

				switch ( $key ) {
					case 'msgctxt':
						$current['msgctxt'] .= $fragment;
						break;
					case 'msgid':
						$current['msgid'] .= $fragment;
						break;
					case 'msgid_plural':
						$current['msgid_plural'] .= $fragment;
						break;
					case 'msgstr':
						$current['msgstr'][0] .= $fragment;
						break;
					case 'msgstr_plural':
						$current['msgstr'][ $plural_index ] .= $fragment;
						break;
				}
				continue;
			}
		}

		// Finalise the last entry if the file doesn't end with a blank line.
		if ( null !== $current ) {
			if ( $is_header && '' === $current['msgid'] ) {
				$headers = $this->parseHeaders( $current['msgstr'][0] ?? '' );
			} else {
				$entries[] = $current;
			}
		}

		return array(
			'headers' => $headers,
			'entries' => $entries,
		);
	}

	/**
	 * Create a blank PO entry structure.
	 *
	 * @return array
	 */
	private function newEntry(): array {
		return array(
			'msgctxt'            => '',
			'msgid'              => '',
			'msgid_plural'       => '',
			'msgstr'             => array( '' ),
			'comments'           => '',
			'extracted_comments' => '',
			'references'         => array(),
			'flags'              => array(),
			'fuzzy'              => false,
		);
	}

	/**
	 * Parse PO header string into an associative array.
	 *
	 * The PO header is the msgstr of the empty-msgid entry and contains
	 * key: value pairs like "Plural-Forms: nplurals=2; plural=(n != 1);".
	 *
	 * @param string $header_str Raw header string from msgstr.
	 * @return array<string, string>
	 */
	private function parseHeaders( string $header_str ): array {
		$headers = array();

		foreach ( explode( "\n", $header_str ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$parts = explode( ':', $line, 2 );
			if ( 2 === count( $parts ) ) {
				$headers[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}

		return $headers;
	}

	/**
	 * Unescape a PO-encoded string.
	 *
	 * Handles Gettext escape sequences: \\, \n, \t, \".
	 *
	 * @param string $string Escaped PO string.
	 * @return string Unescaped string.
	 */
	private function unescape( string $string ): string {
		return str_replace(
			array( '\\\\', '\\n', '\\t', '\\"' ),
			array( '\\',   "\n",  "\t",  '"' ),
			$string
		);
	}

	/**
	 * Escape a string for PO file format.
	 *
	 * @param string $string Raw string.
	 * @return string PO-escaped string.
	 */
	public static function escape( string $string ): string {
		return str_replace(
			array( '\\',  "\n",  "\t",  '"' ),
			array( '\\\\', '\\n', '\\t', '\\"' ),
			$string
		);
	}

	/**
	 * Get the plural form count from PO headers.
	 *
	 * @param array $headers Parsed PO headers.
	 * @return int Number of plural forms (defaults to 2).
	 */
	public function getPluralCount( array $headers ): int {
		if ( isset( $headers['Plural-Forms'] ) &&
			preg_match( '/nplurals\s*=\s*(\d+)/', $headers['Plural-Forms'], $m ) ) {
			return (int) $m[1];
		}

		return 2;
	}

	/**
	 * Get the plural expression from PO headers.
	 *
	 * @param array $headers Parsed PO headers.
	 * @return string JavaScript-compatible plural expression (defaults to "(n != 1)").
	 */
	public function getPluralExpression( array $headers ): string {
		if ( isset( $headers['Plural-Forms'] ) &&
			preg_match( '/plural\s*=\s*(.+);?\s*$/', $headers['Plural-Forms'], $m ) ) {
			return rtrim( $m[1], ';' );
		}

		return '(n != 1)';
	}

	/**
	 * Generate a PO file content string from structured data.
	 *
	 * Used for DB-to-PO export (task 7.9).
	 *
	 * @param array $headers Header key-value pairs.
	 * @param array $entries Array of entry structures (same format as parse() output).
	 * @return string Complete PO file content.
	 */
	public function generate( array $headers, array $entries ): string {
		$lines = array();

		// ── Header entry ──────────────────────────────────────────────────
		$header_lines = array();
		foreach ( $headers as $key => $value ) {
			$header_lines[] = sprintf( '%s: %s', $key, $value );
		}

		$header_str = implode( "\\n\n", $header_lines ) . "\\n";
		$lines[]    = 'msgstr ""';
		$lines[]    = '"' . $header_str . '"';
		$lines[]    = '';

		// ── Translation entries ───────────────────────────────────────────
		foreach ( $entries as $entry ) {
			// Comments.
			if ( ! empty( $entry['extracted_comments'] ) ) {
				foreach ( explode( "\n", rtrim( $entry['extracted_comments'] ) ) as $comment_line ) {
					$lines[] = '#. ' . $comment_line;
				}
			}

			if ( ! empty( $entry['references'] ) ) {
				$lines[] = '#: ' . implode( ' ', $entry['references'] );
			}

			if ( ! empty( $entry['flags'] ) ) {
				$lines[] = '#, ' . implode( ', ', $entry['flags'] );
			}

			if ( ! empty( $entry['comments'] ) ) {
				foreach ( explode( "\n", rtrim( $entry['comments'] ) ) as $comment_line ) {
					if ( '' !== $comment_line ) {
						$lines[] = '# ' . $comment_line;
					}
				}
			}

			// msgctxt.
			if ( ! empty( $entry['msgctxt'] ) ) {
				$lines[] = 'msgctxt "' . static::escape( $entry['msgctxt'] ) . '"';
			}

			// msgid — split long strings across continuation lines.
			$lines[] = 'msgid "' . static::escape( $entry['msgid'] ) . '"';

			// msgid_plural.
			if ( ! empty( $entry['msgid_plural'] ) ) {
				$lines[] = 'msgid_plural "' . static::escape( $entry['msgid_plural'] ) . '"';

				// Plural msgstr[n] entries.
				$plural_count = count( $entry['msgstr'] );
				for ( $i = 0; $i < $plural_count; $i++ ) {
					$val = $entry['msgstr'][ $i ] ?? '';
					$lines[] = sprintf(
						'msgstr[%d] "%s"',
						$i,
						static::escape( $val )
					);
				}
			} else {
				// Singular msgstr.
				$lines[] = 'msgstr "' . static::escape( $entry['msgstr'][0] ?? '' ) . '"';
			}

			$lines[] = '';
		}

		return implode( "\n", $lines );
	}
}
