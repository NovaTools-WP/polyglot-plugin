<?php
/**
 * PO-to-database importer for NovaTools Polyglot.
 *
 * Reads a PO file and populates the `polyglot_strings` and
 * `polyglot_string_translations` tables. Each msgid is registered as a
 * string entry (with domain, context, and MD5 hash), and the corresponding
 * msgstr is stored as a translation for the given language.
 *
 * @package NovaTools\Polyglot\FileTranslation
 */

namespace NovaTools\Polyglot\FileTranslation;

use NovaTools\Polyglot\Database\Schema;
use NovaTools\Polyglot\String\StringManager;

defined( 'ABSPATH' ) || exit;

class PoImporter {

	/**
	 * PO file parser.
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
	 * Import a PO file into the database string translation tables.
	 *
	 * For each msgid/msgstr pair:
	 *   1. Compute the hash of `domain + context + name`.
	 *   2. Insert (or update) a row in `polyglot_strings`.
	 *   3. Insert (or update) a row in `polyglot_string_translations`.
	 *
	 * @param string $po_file   Absolute path to the PO file.
	 * @param string $domain    Text domain (e.g. "mytheme").
	 * @param string $language  Target language code (e.g. "fr").
	 * @return array{
	 *     strings_imported: int,
	 *     translations_imported: int,
	 *     strings_skipped: int,
	 *     errors: string[]
	 * }
	 */
	public function import( string $po_file, string $domain, string $language ): array {
		$result = array(
			'strings_imported'     => 0,
			'translations_imported' => 0,
			'strings_skipped'      => 0,
			'errors'               => array(),
		);

		if ( ! is_readable( $po_file ) ) {
			$result['errors'][] = sprintf( 'PO file not found or unreadable: %s', $po_file );
			return $result;
		}

		try {
			$data = $this->parser->parse( $po_file );
		} catch ( \InvalidArgumentException $e ) {
			$result['errors'][] = $e->getMessage();
			return $result;
		}

		$entries = $data['entries'] ?? array();

		if ( empty( $entries ) ) {
			return $result;
		}

		global $wpdb;

		$strings_table    = Schema::getTableName( 'polyglot_strings' );
		$translations_table = Schema::getTableName( 'polyglot_string_translations' );

		foreach ( $entries as $entry ) {
			$msgid = $entry['msgid'] ?? '';

			// Skip empty msgid (header entry).
			if ( '' === $msgid ) {
				++$result['strings_skipped'];
				continue;
			}

			$msgctxt = $entry['msgctxt'] ?? '';
			$name    = $msgid; // Use msgid as the string name.

			// Compute hash: MD5 of domain + context + name.
			$hash = md5( $domain . $msgctxt . $name );

			// Check if the string already exists.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id FROM {$strings_table} WHERE hash = %s LIMIT 1",
					$hash
				)
			);

			$string_id = null;

			if ( $existing ) {
				// Update existing string.
				$string_id = (int) $existing->id;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->update(
					$strings_table,
					array(
						'value'     => $msgid,
						'context'   => $msgctxt,
						'domain'    => $domain,
					),
					array( 'id' => $string_id ),
				 array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				// Insert new string.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$inserted = $wpdb->insert(
					$strings_table,
					array(
						'domain'  => $domain,
						'context' => $msgctxt,
						'name'    => $name,
						'value'   => $msgid,
						'hash'    => $hash,
						'type'    => 'LINE',
						'status'  => StringManager::STATUS_UNTRANSLATED,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
				);

				if ( $inserted ) {
					$string_id = (int) $wpdb->insert_id;
					++$result['strings_imported'];
				} else {
					$result['errors'][] = sprintf(
						'Failed to insert string: %s',
						wp_specialchars_decode( $msgid )
					);
					++$result['strings_skipped'];
					continue;
				}
			}

			// Determine the translation value.
			$msgstr = $entry['msgstr'][0] ?? '';

			// Handle plural translations: join with \0.
			if ( ! empty( $entry['msgid_plural'] ) ) {
				$plural_parts = array();
				foreach ( $entry['msgstr'] as $str ) {
					$plural_parts[] = $str;
				}
				$msgstr = implode( "\0", $plural_parts );
			}

			// Skip empty translations.
			if ( '' === $msgstr ) {
				continue;
			}

			$status = StringManager::STATUS_TRANSLATED;

			// Check if translation already exists for this string + language.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$existing_trans = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id FROM {$translations_table} WHERE string_id = %d AND language = %s LIMIT 1",
					$string_id,
					$language
				)
			);

			if ( $existing_trans ) {
				// Update existing translation.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->update(
					$translations_table,
					array(
						'value'         => $msgstr,
						'status'        => $status,
						'translated_at' => current_time( 'mysql' ),
					),
					array( 'id' => (int) $existing_trans->id ),
					array( '%s', '%d', '%s' ),
					array( '%d' )
				);
			} else {
				// Insert new translation.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert(
					$translations_table,
					array(
						'string_id'     => $string_id,
						'language'      => $language,
						'status'        => $status,
						'value'         => $msgstr,
						'translated_at' => current_time( 'mysql' ),
					),
					array( '%d', '%s', '%d', '%s', '%s' )
				);
			}

			++$result['translations_imported'];
		}

		return $result;
	}

	/**
	 * Batch-import multiple PO files.
	 *
	 * @param array  $files   Map of language code → PO file path.
	 * @param string $domain  Text domain.
	 * @return array Map of language → import result.
	 */
	public function importBatch( array $files, string $domain ): array {
		$results = array();

		foreach ( $files as $language => $po_file ) {
			$results[ $language ] = $this->import( $po_file, $domain, $language );
		}

		return $results;
	}
}
