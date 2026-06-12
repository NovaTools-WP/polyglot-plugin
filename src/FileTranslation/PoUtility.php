<?php
/**
 * PO file utility functions for NovaTools Polyglot.
 *
 * Provides reusable helpers for PO/POT filename parsing, POT file writing,
 * and string encoding. Shared between the CLI FileCommand and the admin
 * PO editor.
 *
 * @package NovaTools\Polyglot\FileTranslation
 */

namespace NovaTools\Polyglot\FileTranslation;

defined( 'ABSPATH' ) || exit;

class PoUtility {

	/**
	 * Derive a text domain from a PO filename.
	 *
	 * Plugin files follow the pattern `{domain}-{locale}.po`.
	 *
	 * @param string $po_file Path to the PO file.
	 * @return string Derived domain, or "default".
	 */
	public static function deriveDomain( string $po_file ): string {
		$basename = pathinfo( $po_file, PATHINFO_FILENAME );

		// Match {domain}-{locale} pattern.
		if ( preg_match( '/^(.+)-[a-z]{2,3}_[A-Z]{2}$/', $basename, $m ) ) {
			return $m[1];
		}

		return 'default';
	}

	/**
	 * Derive a WordPress locale from a PO filename.
	 *
	 * @param string $po_file Path to the PO file.
	 * @param string $domain  Text domain (used for prefix matching).
	 * @return string Derived locale, or "en_US".
	 */
	public static function deriveLocale( string $po_file, string $domain ): string {
		$basename = pathinfo( $po_file, PATHINFO_FILENAME );

		// Match {domain}-{locale} pattern.
		$prefix = $domain . '-';
		if ( 0 === strpos( $basename, $prefix ) ) {
			$remainder = substr( $basename, strlen( $prefix ) );
			if ( preg_match( '/^([a-z]{2,3}_[A-Z]{2})/', $remainder, $m ) ) {
				return $m[1];
			}
		}

		// Standalone locale.
		if ( preg_match( '/^([a-z]{2,3}_[A-Z]{2})$/', $basename, $m ) ) {
			return $m[1];
		}

		return 'en_US';
	}

	/**
	 * Write extracted strings to a POT (PO Template) file.
	 *
	 * Generates a basic POT file from extracted strings. The output includes
	 * standard POT headers and properly escaped msgid/msgstr entries.
	 *
	 * @param array  $strings Extracted strings keyed by unique key. Each entry
	 *                        must contain: domain, msgid, msgctxt, msgid_plural,
	 *                        comments, references.
	 * @param string $output  Output file path.
	 * @param string $domain  Text domain.
	 * @return bool True on success.
	 */
	public static function writePotFile( array $strings, string $output, string $domain ): bool {
		$lines = array();

		// POT header.
		$lines[] = '# Translation template for ' . ( '' !== $domain ? $domain : 'unknown' );
		$lines[] = 'msgid ""';
		$lines[] = 'msgstr ""';
		$lines[] = '"Project-Id-Version: ' . ( '' !== $domain ? $domain : 'unknown' ) . ' 1.0\\n"';
		$lines[] = '"Report-Msgid-Bugs-To: \\n"';
		$lines[] = '"POT-Creation-Date: ' . gmdate( 'Y-m-d H:i:s+0000' ) . '\\n"';
		$lines[] = '"MIME-Version: 1.0\\n"';
		$lines[] = '"Content-Type: text/plain; charset=UTF-8\\n"';
		$lines[] = '"Content-Transfer-Encoding: 8bit\\n"';
		$lines[] = '';

		foreach ( $strings as $entry ) {
			// Translator comments.
			if ( '' !== $entry['comments'] ) {
				$lines[] = '#. ' . $entry['comments'];
			}

			// References.
			if ( ! empty( $entry['references'] ) ) {
				$lines[] = '#: ' . implode( ' ', $entry['references'] );
			}

			// Context.
			if ( '' !== $entry['msgctxt'] ) {
				$lines[] = 'msgctxt ' . self::poEncode( $entry['msgctxt'] );
			}

			// msgid.
			$lines[] = 'msgid ' . self::poEncode( $entry['msgid'] );

			// Plural.
			if ( '' !== $entry['msgid_plural'] ) {
				$lines[] = 'msgid_plural ' . self::poEncode( $entry['msgid_plural'] );
				$lines[] = 'msgstr[0] ""';
				$lines[] = 'msgstr[1] ""';
			} else {
				$lines[] = 'msgstr ""';
			}

			$lines[] = '';
		}

		// Ensure output directory exists.
		$dir = dirname( $output );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return false !== file_put_contents( $output, implode( "\n", $lines ) );
	}

	/**
	 * Encode a string for PO file format.
	 *
	 * Wraps the string in quotes and escapes backslashes, double quotes,
	 * newlines, tabs, and carriage returns per the PO specification.
	 *
	 * @param string $string Raw string value.
	 * @return string PO-encoded string (quoted and escaped).
	 */
	public static function poEncode( string $string ): string {
		return '"' . str_replace(
			array( '\\', '"', "\n", "\t", "\r" ),
			array( '\\\\', '\\"', '\\n', '\\t', '\\r' ),
			$string
		) . '"';
	}
}
