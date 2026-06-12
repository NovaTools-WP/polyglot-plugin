<?php
/**
 * WP-CLI language commands for NovaTools Polyglot.
 *
 * Provides `wp polyglot language list|add|remove` subcommands for managing
 * site languages from the command line.
 *
 * @package NovaTools\Polyglot\Cli
 */

namespace NovaTools\Polyglot\Cli;

use NovaTools\Polyglot\Core\Plugin;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class LanguageCommand {

	/**
	 * List all registered languages.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status: active, inactive, all.
	 * ---
	 * default: all
	 * options:
	 *   - active
	 *   - inactive
	 *   - all
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific fields.
	 * ---
	 * default: code,locale,english_name,native_name,is_active,is_default,direction
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
	 * ## EXAMPLES
	 *
	 *     # List all active languages
	 *     wp polyglot language list --status=active
	 *
	 *     # Export languages as JSON
	 *     wp polyglot language list --format=json
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$status  = $assoc_args['status'] ?? 'all';
		$fields  = $assoc_args['fields'] ?? 'code,locale,english_name,native_name,is_active,is_default,direction';
		$format  = $assoc_args['format'] ?? 'table';

		$plugin = Plugin::getInstance();
		/** @var \NovaTools\Polyglot\Language\LanguageRepository $repo */
		$repo = $plugin->get( 'language.repository' );

		if ( 'active' === $status ) {
			$languages = $repo->getActive();
		} elseif ( 'inactive' === $status ) {
			$languages = $repo->getInactive();
		} else {
			$languages = $repo->getAll();
		}

		$items = array();

		foreach ( $languages as $lang ) {
			$items[] = array(
				'code'         => $lang->code,
				'locale'       => $lang->locale,
				'english_name' => $lang->englishName,
				'native_name'  => $lang->nativeName,
				'is_active'    => $lang->isActive ? 'yes' : 'no',
				'is_default'   => $lang->isDefault ? 'yes' : 'no',
				'direction'    => $lang->direction,
				'flag_code'    => $lang->flagCode,
				'sort_order'   => $lang->sortOrder,
			);
		}

		if ( empty( $items ) ) {
			WP_CLI::success( 'No languages found.' );
			return;
		}

		$fields_array = array_map( 'trim', explode( ',', $fields ) );

		WP_CLI\Utils\format_items( $format, $items, $fields_array );
	}

	/**
	 * Add a new language.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : Short language code (e.g. "fr", "de", "es").
	 *
	 * [--locale=<locale>]
	 * : Full WordPress locale (e.g. "fr_FR"). Defaults to code + "_" + uppercase code.
	 *
	 * [--english-name=<english_name>]
	 * : Name in English. Required.
	 *
	 * [--native-name=<native_name>]
	 * : Name in the language itself. Required.
	 *
	 * [--direction=<direction>]
	 * : Text direction: ltr or rtl.
	 * ---
	 * default: ltr
	 * ---
	 *
	 * [--flag-code=<flag_code>]
	 * : ISO flag code. Defaults to language code.
	 *
	 * [--sort-order=<sort_order>]
	 * : Sort order for admin display.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--activate]
	 * : Whether to activate the language immediately.
	 * ---
	 * default: true
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Add French
	 *     wp polyglot language add fr --english_name="French" --native_name="Français"
	 *
	 *     # Add Arabic with RTL direction
	 *     wp polyglot language add ar --english_name="Arabic" --native_name="العربية" --direction=rtl
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function add( array $args, array $assoc_args ): void {
		$code = $args[0] ?? '';

		if ( '' === $code ) {
			WP_CLI::error( 'Language code is required.' );
		}

		$english_name = $assoc_args['english-name'] ?? '';
		$native_name  = $assoc_args['native-name'] ?? '';

		if ( '' === $english_name || '' === $native_name ) {
			WP_CLI::error( 'Both --english_name and --native_name are required.' );
		}

		$locale = $assoc_args['locale'] ?? $code . '_' . strtoupper( $code );

		$data = array(
			'code'          => $code,
			'locale'        => $locale,
			'english_name'  => $english_name,
			'native_name'   => $native_name,
			'direction'     => $assoc_args['direction'] ?? 'ltr',
			'flag_code'     => $assoc_args['flag-code'] ?? $code,
			'sort_order'    => (int) ( $assoc_args['sort-order'] ?? 0 ),
		);

		$plugin = Plugin::getInstance();
		/** @var \NovaTools\Polyglot\Language\LanguageManager $manager */
		$manager = $plugin->get( 'language.manager' );

		try {
			$language = $manager->add( $data );

			WP_CLI::success( sprintf(
				'Language "%s" (%s) added successfully.',
				$language->englishName,
				$language->code
			) );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		} catch ( \Throwable $e ) {
			WP_CLI::error( sprintf( 'Failed to add language: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Remove (deactivate) a language.
	 *
	 * Deactivation preserves all translation data. The default language
	 * cannot be removed.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : Language code to deactivate (e.g. "fr").
	 *
	 * ## EXAMPLES
	 *
	 *     # Deactivate French
	 *     wp polyglot language remove fr
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function remove( array $args, array $assoc_args ): void {
		$code = $args[0] ?? '';

		if ( '' === $code ) {
			WP_CLI::error( 'Language code is required.' );
		}

		$plugin = Plugin::getInstance();
		/** @var \NovaTools\Polyglot\Language\LanguageManager $manager */
		$manager = $plugin->get( 'language.manager' );

		$result = $manager->deactivate( $code );

		if ( $result ) {
			WP_CLI::success( sprintf( 'Language "%s" deactivated.', $code ) );
		} else {
			WP_CLI::error( sprintf(
				'Could not deactivate language "%s". It may be the default language or does not exist.',
				$code
			) );
		}
	}
}
