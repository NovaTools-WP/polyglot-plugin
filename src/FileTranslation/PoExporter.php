<?php
/**
 * Database-to-PO exporter for NovaTools Polyglot.
 *
 * Exports string translations from the `polyglot_strings` and
 * `polyglot_string_translations` tables to a standard Gettext `.po` file,
 * allowing round-trip editing (import → edit → export).
 *
 * @package NovaTools\Polyglot\FileTranslation
 */

namespace NovaTools\Polyglot\FileTranslation;

use NovaTools\Polyglot\Database\Schema;

defined( 'ABSPATH' ) || exit;

class PoExporter {

	/**
	 * PO file parser (used for its `generate()` method).
	 *
	 * @var PoFileParser
	 */
	private PoFileParser $parser;

	/**
	 * Constructor.
	 *
	 * @param PoFileParser $parser PO file parser instance.
	 */
	public function __construct( PoFileParser $parser ) {
		$this->parser = $parser;
	}

	/**
	 * Export database strings to a PO file.
	 *
	 * Queries `polyglot_strings` for the given domain, joins with
	 * `polyglot_string_translations` for the target language, and generates
	 * a valid PO file.
	 *
	 * @param string $domain   Text domain (e.g. "mytheme").
	 * @param string $language Target language code (e.g. "fr").
	 * @param string $output   Absolute path for the output PO file.
	 * @return array{
	 *     entries: int,
	 *     translated: int,
	 *     bytes: int|false,
	 *     file: string
	 * }
	 */
	public function export( string $domain, string $language, string $output ): array {
		$rows = $this->fetchStrings( $domain, $language );

		$headers = $this->buildHeaders( $domain, $language );
		$entries = array();

		$translated_count = 0;

		foreach ( $rows as $row ) {
			$entry = $this->rowToEntry( $row );

			if ( '' !== ( $entry['msgstr'][0] ?? '' ) ) {
				++$translated_count;
			}

			$entries[] = $entry;
		}

		$po_content = $this->parser->generate( $headers, $entries );

		// Ensure the output directory exists.
		$dir = dirname( $output );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$bytes = file_put_contents( $output, $po_content );

		return array(
			'entries'    => count( $entries ),
			'translated' => $translated_count,
			'bytes'      => $bytes,
			'file'       => $output,
		);
	}

	/**
	 * Export database strings and return PO content as a string (no file write).
	 *
	 * @param string $domain   Text domain.
	 * @param string $language Target language code.
	 * @return string PO file content.
	 */
	public function exportToString( string $domain, string $language ): string {
		$rows    = $this->fetchStrings( $domain, $language );
		$headers = $this->buildHeaders( $domain, $language );
		$entries = array();

		foreach ( $rows as $row ) {
			$entries[] = $this->rowToEntry( $row );
		}

		return $this->parser->generate( $headers, $entries );
	}

	/**
	 * Fetch strings and their translations from the database.
	 *
	 * Joins `polyglot_strings` with `polyglot_string_translations` for the
	 * given domain and language. Includes strings without translations
	 * (LEFT JOIN) so the exported PO file has all source strings.
	 *
	 * @param string $domain   Text domain.
	 * @param string $language Language code.
	 * @return array[] Database rows.
	 */
	private function fetchStrings( string $domain, string $language ): array {
		global $wpdb;

		$strings_table      = Schema::getTableName( 'polyglot_strings' );
		$translations_table = Schema::getTableName( 'polyglot_string_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.domain, s.context, s.name, s.value AS msgid,
				        t.value AS msgstr, t.status AS trans_status
				 FROM {$strings_table} s
				 LEFT JOIN {$translations_table} t
				    ON t.string_id = s.id AND t.language = %s
				 WHERE s.domain = %s
				 ORDER BY s.id ASC",
				$language,
				$domain
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Build standard PO headers for the exported file.
	 *
	 * @param string $domain   Text domain.
	 * @param string $language Language code.
	 * @return array<string, string>
	 */
	private function buildHeaders( string $domain, string $language ): array {
		return array(
			'Project-Id-Version'  => $domain . ' 1.0',
			'Report-Msgid-Bugs-To' => '',
			'POT-Creation-Date'   => '',
			'PO-Revision-Date'    => gmdate( 'Y-m-d H:i:s+0000' ),
			'Last-Translator'     => 'NovaTools Polyglot',
			'Language-Team'       => $language,
			'Language'            => $language,
			'MIME-Version'        => '1.0',
			'Content-Type'        => 'text/plain; charset=UTF-8',
			'Content-Transfer-Encoding' => '8bit',
			'Plural-Forms'        => 'nplurals=2; plural=(n != 1);',
			'X-Domain'            => $domain,
			'X-Generator'         => 'NovaTools Polyglot',
		);
	}

	/**
	 * Convert a database row to a PO entry structure.
	 *
	 * @param array $row Database row.
	 * @return array PO entry structure compatible with PoFileParser::generate().
	 */
	private function rowToEntry( array $row ): array {
		$msgid  = $row['msgid'] ?? $row['name'] ?? '';
		$msgstr = $row['msgstr'] ?? '';

		// Handle plural translations stored with \0 separator.
		$msgstr_array = array( '' );
		if ( '' !== $msgstr ) {
			if ( str_contains( $msgstr, "\0" ) ) {
				$msgstr_array = explode( "\0", $msgstr );
			} else {
				$msgstr_array = array( $msgstr );
			}
		}

		return array(
			'msgctxt'            => $row['context'] ?? '',
			'msgid'              => $msgid,
			'msgid_plural'       => '', // Plural msgid not stored in DB; single strings only.
			'msgstr'             => $msgstr_array,
			'comments'           => '',
			'extracted_comments' => '',
			'references'         => array(),
			'flags'              => array(),
			'fuzzy'              => false,
		);
	}
}
