<?php
/**
 * WP-CLI translation commands for NovaTools Polyglot.
 *
 * Provides `wp polyglot translation list|sync` subcommands for listing
 * translation groups and synchronising content checksums.
 *
 * @package NovaTools\Polyglot\Cli
 */

namespace NovaTools\Polyglot\Cli;

use NovaTools\Polyglot\Core\Plugin;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class TranslationCommand {

	/**
	 * List translation groups and their status.
	 *
	 * Displays translation entries from `polyglot_translations`, optionally
	 * filtered by element type, language, or status.
	 *
	 * ## OPTIONS
	 *
	 * [--element-type=<type>]
	 * : Filter by element type (e.g. "post_post", "post_page", "tax_category").
	 *
	 * [--language=<code>]
	 * : Filter by language code.
	 *
	 * [--status=<status>]
	 * : Filter by translation status.
	 * ---
	 * options:
	 *   - not_translated
	 *   - in_progress
	 *   - completed
	 *   - needs_update
	 *   - awaiting_review
	 * ---
	 *
	 * [--trid=<trid>]
	 * : Show only entries in a specific translation group.
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific fields.
	 * ---
	 * default: translation_id,trid,element_type,element_id,language_code,source_language_code,status
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
	 * default: 50
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
	 *     # List all translations for posts
	 *     wp polyglot translation list --element_type=post_post
	 *
	 *     # Show French translations needing update
	 *     wp polyglot translation list --language=fr --status=needs_update
	 *
	 *     # Inspect a specific translation group
	 *     wp polyglot translation list --trid=42
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$plugin = Plugin::getInstance();
		/** @var \NovaTools\Polyglot\Translation\TranslationRepository $repo */
		$repo = $plugin->get( 'translation.repository' );

		$result = $repo->paginate( array(
			'element_type' => $assoc_args['element-type'] ?? '',
			'language'     => $assoc_args['language'] ?? '',
			'status'       => $assoc_args['status'] ?? '',
			'trid'         => $assoc_args['trid'] ?? '',
			'per_page'     => (int) ( $assoc_args['per-page'] ?? 50 ),
			'page'         => (int) ( $assoc_args['page'] ?? 1 ),
		) );

		if ( 0 === $result['total'] ) {
			WP_CLI::success( 'No translations found.' );
			return;
		}

		$items = array();

		foreach ( $result['items'] as $row ) {
			$items[] = array(
				'translation_id'       => $row['translation_id'],
				'trid'                 => $row['trid'],
				'element_type'         => $row['element_type'],
				'element_id'           => $row['element_id'],
				'language_code'        => $row['language_code'],
				'source_language_code' => $row['source_language_code'],
				'status'               => $row['status'],
			);
		}

		$fields_array = array_map( 'trim', explode( ',', $assoc_args['fields'] ?? 'translation_id,trid,element_type,element_id,language_code,source_language_code,status' ) );
		$format       = $assoc_args['format'] ?? 'table';

		WP_CLI\Utils\format_items( $format, $items, $fields_array );

		$last_page = (int) ceil( $result['total'] / $result['per_page'] );
		WP_CLI::log( sprintf( 'Showing page %d of %d (%d total results)', $result['page'], $last_page, $result['total'] ) );
	}

	/**
	 * Synchronise translation checksums.
	 *
	 * Recalculates content checksums for translated posts and flags
	 * translations whose source content has changed as "needs_update".
	 *
	 * ## OPTIONS
	 *
	 * [<post-type>]
	 * : Post type to synchronise. Default "post".
	 * ---
	 * default: post
	 * ---
	 *
	 * [--all]
	 * : Synchronise all public post types.
	 *
	 * [--dry-run]
	 * : Report what would change without making updates.
	 *
	 * ## EXAMPLES
	 *
	 *     # Sync checksums for posts
	 *     wp polyglot translation sync post
	 *
	 *     # Sync all post types with a dry run
	 *     wp polyglot translation sync --all --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sync( array $args, array $assoc_args ): void {
		$plugin = Plugin::getInstance();
		/** @var \NovaTools\Polyglot\Translation\PostTranslation\PostSyncService $sync_service */
		$sync_service = $plugin->get( 'post.sync' );
		$dry_run      = ! empty( $assoc_args['dry-run'] );

		if ( ! empty( $assoc_args['all'] ) ) {
			$post_types = get_post_types( array( 'public' => true ), 'names' );
		} else {
			$post_type  = $args[0] ?? 'post';
			$post_types = array( $post_type );
		}

		$total_updated = 0;

		foreach ( $post_types as $type ) {
			if ( $dry_run ) {
				// In dry-run mode, just count posts that would be checked.
				$posts = get_posts( array(
					'post_type'      => $type,
					'posts_per_page' => -1,
					'post_status'    => 'any',
					'fields'         => 'ids',
				) );

				$count = count( $posts );
				WP_CLI::log( sprintf( '[dry-run] Would sync %d "%s" posts.', $count, $type ) );
				continue;
			}

			$updated = $sync_service->recalculateChecksums( $type );
			$total_updated += $updated;

			WP_CLI::log( sprintf( 'Synced %d "%s" posts (%d checksums updated).', count( get_posts( array(
				'post_type'      => $type,
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
			) ) ), $type, $updated ) );
		}

		if ( $dry_run ) {
			WP_CLI::success( 'Dry run complete. No changes were made.' );
		} else {
			WP_CLI::success( sprintf( 'Sync complete. %d checksums updated across %d post types.', $total_updated, count( $post_types ) ) );
		}
	}
}
