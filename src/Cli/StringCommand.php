<?php
/**
 * WP-CLI string translation commands for NovaTools Polyglot.
 *
 * Provides `wp polyglot string list|register|translate` subcommands for
 * managing database string translations from the command line.
 *
 * @package NovaTools\Polyglot\Cli
 */

namespace NovaTools\Polyglot\Cli;

use NovaTools\Polyglot\Core\Plugin;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class StringCommand {

	/**
	 * List registered strings and their translation status.
	 *
	 * ## OPTIONS
	 *
	 * [--domain=<domain>]
	 * : Filter by text domain.
	 *
	 * [--status=<status>]
	 * : Filter by translation status for a given language. Requires --language.
	 * ---
	 * options:
	 *   - 0
	 *   - 1
	 *   - 2
	 * ---
	 *
	 * [--language=<code>]
	 * : Filter by translation status for a specific language. Used with --status.
	 *
	 * [--search=<term>]
	 * : Free-text search on string value and name.
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific fields.
	 * ---
	 * default: id,domain,name,value,status
	 * ---
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
	 * ---
	 *
	 * [--per-page=<count>]
	 * : Number of results per page.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--page=<num>]
	 * : Page number for pagination.
	 * ---
	 * default: 1
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all strings for a domain
	 *     wp polyglot string list --domain=mytheme
	 *
	 *     # Find untranslated strings in French
	 *     wp polyglot string list --language=fr --status=0
	 *
	 *     # Search for a specific string
	 *     wp polyglot string list --search="Welcome"
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$plugin = Plugin::getInstance();
		/** @var \NovaTools\Polyglot\String\StringRepository $repo */
		$repo = $plugin->get( 'string.repository' );

		$query_args = array(
			'domain'     => $assoc_args['domain'] ?? '',
			'search'     => $assoc_args['search'] ?? '',
			'language'   => $assoc_args['language'] ?? '',
			'per_page'   => (int) ( $assoc_args['per-page'] ?? 20 ),
			'page'       => (int) ( $assoc_args['page'] ?? 1 ),
			'orderby'    => 'id',
			'order'      => 'ASC',
		);

		if ( isset( $assoc_args['status'] ) && '' !== $assoc_args['language'] ) {
			$query_args['translation_status'] = (int) $assoc_args['status'];
		} elseif ( isset( $assoc_args['status'] ) && '' === $assoc_args['language'] ) {
			$query_args['status'] = (int) $assoc_args['status'];
		}

		$result = $repo->search( $query_args );

		if ( empty( $result['items'] ) ) {
			WP_CLI::success( 'No strings found.' );
			return;
		}

		$items = array();

		foreach ( $result['items'] as $row ) {
			$items[] = array(
				'id'       => $row['id'],
				'domain'   => $row['domain'],
				'name'     => $row['name'],
				'value'    => $row['value'],
				'status'   => $row['status'] ?? 0,
				'hash'     => $row['hash'] ?? '',
			);
		}

		$fields_array = array_map( 'trim', explode( ',', $assoc_args['fields'] ?? 'id,domain,name,value,status' ) );
		$format       = $assoc_args['format'] ?? 'table';

		WP_CLI\Utils\format_items( $format, $items, $fields_array );

		$total    = $result['total'];
		$per_page = $query_args['per_page'];
		$page     = $query_args['page'];
		$last     = (int) ceil( $total / $per_page );

		WP_CLI::log( sprintf( 'Showing page %d of %d (%d total results)', $page, $last, $total ) );
	}

	/**
	 * Register a string for translation.
	 *
	 * Inserts or updates a string in the `polyglot_strings` table. If a
	 * string with the same domain + name already exists, its value is
	 * updated and existing translations are flagged as "needs_update" when
	 * the value has changed.
	 *
	 * ## OPTIONS
	 *
	 * <domain>
	 * : Text domain (e.g. "mytheme").
	 *
	 * <name>
	 * : Machine-readable string identifier.
	 *
	 * <value>
	 * : The source string value.
	 *
	 * [--context=<context>]
	 * : Optional grouping context.
	 *
	 * [--type=<type>]
	 * : String type: LINE, TEXTAREA, VISUAL.
	 * ---
	 * default: LINE
	 * options:
	 *   - LINE
	 *   - TEXTAREA
	 *   - VISUAL
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Register a simple string
	 *     wp polyglot string register mytheme "Site title" "Welcome to our site"
	 *
	 *     # Register with context
	 *     wp polyglot string register mytheme "footer.text" "All rights reserved" --context="footer"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function register( array $args, array $assoc_args ): void {
		$domain = $args[0] ?? '';
		$name   = $args[1] ?? '';
		$value  = $args[2] ?? '';

		if ( '' === $domain || '' === $name || '' === $value ) {
			WP_CLI::error( 'Usage: wp polyglot string register <domain> <name> <value>' );
		}

		$plugin = Plugin::getInstance();
		/** @var \NovaTools\Polyglot\String\StringManager $manager */
		$manager = $plugin->get( 'string.manager' );

		$registration_args = array(
			'type' => $assoc_args['type'] ?? 'LINE',
		);

		$context = $assoc_args['context'] ?? '';

		try {
			$id = $manager->registerString( $domain, $name, $value, $context, $registration_args );

			WP_CLI::success( sprintf( 'String registered with ID %d (domain: %s, name: %s).', $id, $domain, $name ) );
		} catch ( \Throwable $e ) {
			WP_CLI::error( sprintf( 'Failed to register string: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Translate a registered string.
	 *
	 * Saves a translation for a specific string and language in the
	 * `polyglot_string_translations` table.
	 *
	 * ## OPTIONS
	 *
	 * <string-id>
	 * : The numeric ID of the string (from `wp polyglot string list`).
	 *
	 * <language>
	 * : Target language code (e.g. "fr", "de").
	 *
	 * <value>
	 * : The translated string value.
	 *
	 * [--status=<status>]
	 * : Translation status: 0 = not translated, 1 = translated, 2 = needs_update.
	 * ---
	 * default: 1
	 * options:
	 *   - 0
	 *   - 1
	 *   - 2
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Translate string #42 to French
	 *     wp polyglot string translate 42 fr "Bienvenue sur notre site"
	 *
	 *     # Mark a translation as needing update
	 *     wp polyglot string translate 42 fr "Bienvenue" --status=2
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function translate( array $args, array $assoc_args ): void {
		$string_id = (int) ( $args[0] ?? 0 );
		$language  = $args[1] ?? '';
		$value     = $args[2] ?? '';

		if ( 0 === $string_id || '' === $language || '' === $value ) {
			WP_CLI::error( 'Usage: wp polyglot string translate <string-id> <language> <value>' );
		}

		$status = (int) ( $assoc_args['status'] ?? 1 );

		$plugin = Plugin::getInstance();
		/** @var \NovaTools\Polyglot\String\StringRepository $repo */
		$repo = $plugin->get( 'string.repository' );

		// Verify the string exists.
		$existing = $repo->findById( $string_id );

		if ( ! $existing ) {
			WP_CLI::error( sprintf( 'String with ID %d not found.', $string_id ) );
		}

		/** @var \NovaTools\Polyglot\String\StringManager $manager */
		$manager = $plugin->get( 'string.manager' );

		try {
			$translation_id = $manager->saveTranslation( $string_id, $language, $value, $status );

			WP_CLI::success( sprintf(
				'Translation saved (ID: %d) for string %d → %s.',
				$translation_id,
				$string_id,
				$language
			) );
		} catch ( \Throwable $e ) {
			WP_CLI::error( sprintf( 'Failed to save translation: %s', $e->getMessage() ) );
		}
	}
}
