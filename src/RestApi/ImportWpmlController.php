<?php
/**
 * REST API controller for WPML import.
 *
 * Registers the `/polyglot/v1/import-wpml` route with endpoints for
 * detecting WPML tables, generating dry-run previews, and executing imports.
 *
 * @package NovaTools\Polyglot\RestApi
 */

namespace NovaTools\Polyglot\RestApi;

use NovaTools\Polyglot\Core\Plugin;
use NovaTools\Polyglot\Database\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

class ImportWpmlController {

	const NAMESPACE = 'polyglot/v1';

	const REST_BASE = 'import-wpml';

	/**
	 * Register the routes for this controller.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/detect',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'detectTables' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/dry-run',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'dryRun' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/execute',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'executeImport' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/verify',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'verifyImport' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
				),
			)
		);
	}

	/**
	 * Detect WPML tables in the database.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response
	 */
	public function detectTables( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$wpml_tables = array(
			'icl_languages',
			'icl_languages_translations',
			'icl_flags',
			'icl_locale_map',
			'icl_translations',
			'icl_translation_status',
			'icl_strings',
			'icl_string_translations',
			'icl_string_packages',
			'icl_string_batches',
			'icl_translation_batches',
			'icl_message_status',
			'icl_content_status',
			'icl_core_status',
			'icl_node',
			'icl_reminders',
			'icl_translate',
			'icl_translate_job',
			'icl_mo_files_domains',
		);

		$found = array();

		$suppress = $wpdb->suppress_errors( true );

		foreach ( $wpml_tables as $table ) {
			$full_name = $wpdb->prefix . $table;
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_name}`" );

			if ( null !== $count ) {
				$found[ $table ] = (int) $count;
			}
		}

		$wpdb->suppress_errors( $suppress );

		$wpml_active = defined( 'ICL_SITEPRESS_VERSION' );
		$has_wc = class_exists( 'WooCommerce' );

		return new WP_REST_Response( array(
			'tables' => $found,
			'wpml_active' => $wpml_active,
			'has_woocommerce' => $has_wc,
		), 200 );
	}

	/**
	 * Generate a dry-run report for the selected import options.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response
	 */
	public function dryRun( WP_REST_Request $request ): WP_REST_Response {
		$options = $request->get_json_params();

		$plugin = Plugin::getInstance();
		if ( ! $plugin->has( 'wpml.migrator' ) ) {
			return new WP_REST_Response( array(), 500 );
		}

		$migrator = $plugin->get( 'wpml.migrator' );
		$migrator->setDryRun( true );

		$report = array();

		// The migrator internally returns reports when setDryRun(true) is used.
		if ( ! empty( $options['languages'] ) ) {
			$res = $migrator->importLanguages();
			if(isset($migrator->getReport()['languages'])) {
				$report[] = array(
					'label'  => __( 'Languages', 'novatools-polyglot' ),
					'source' => 'icl_languages + icl_languages_translations + icl_flags + icl_locale_map',
					'target' => 'polyglot_languages',
					'rows'   => $migrator->getReport()['languages']['source'] ?? 0,
				);
			}
		}
		if ( ! empty( $options['translations'] ) ) {
			$res = $migrator->importTranslations();
			if(isset($migrator->getReport()['translations'])) {
				$report[] = array(
					'label'  => __( 'Content Translations', 'novatools-polyglot' ),
					'source' => 'icl_translations + icl_translation_status',
					'target' => 'polyglot_translations',
					'rows'   => $migrator->getReport()['translations']['source'] ?? 0,
				);
			}
		}
		if ( ! empty( $options['strings'] ) ) {
			$res = $migrator->importStrings();
			if(isset($migrator->getReport()['strings'])) {
				$report[] = array(
					'label'  => __( 'Strings', 'novatools-polyglot' ),
					'source' => 'icl_strings',
					'target' => 'polyglot_strings',
					'rows'   => $migrator->getReport()['strings']['source'] ?? 0,
				);
				$report[] = array(
					'label'  => __( 'String Translations', 'novatools-polyglot' ),
					'source' => 'icl_string_translations',
					'target' => 'polyglot_string_translations',
					'rows'   => $migrator->getReport()['string_translations']['source'] ?? 0,
				);
			}
		}
		if ( ! empty( $options['settings'] ) ) {
			$report[] = array(
				'label'  => __( 'Settings', 'novatools-polyglot' ),
				'source' => 'icl_sitepress_settings (option)',
				'target' => 'polyglot_settings (option)',
				'rows'   => 1,
			);
		}
		if ( ! empty( $options['woocommerce'] ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'icl_translations';
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE element_type LIKE 'post_product%%' OR element_type LIKE 'tax_product%%'" );

			$report[] = array(
				'label'  => __( 'WooCommerce Data', 'novatools-polyglot' ),
				'source' => 'icl_translations (product types)',
				'target' => 'polyglot_translations + postmeta',
				'rows'   => (int) $count,
			);
		}

		return new WP_REST_Response( $report, 200 );
	}

	/**
	 * Execute an import step.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function executeImport( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$step = $params['step'] ?? '';

		$plugin = Plugin::getInstance();
		if ( ! $plugin->has( 'wpml.migrator' ) ) {
			return new WP_Error(
				'polyglot_migrator_missing',
				__( 'Migrator service not found.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		$migrator = $plugin->get( 'wpml.migrator' );
		$migrator->setDryRun( false );

		try {
			switch ( $step ) {
				case 'languages':
					$result = $migrator->importLanguages();
					return new WP_REST_Response( array(
						'message' => sprintf( __( 'Imported %d languages.', 'novatools-polyglot' ), $result['imported'] ?? 0 ),
						'count' => $result['imported'] ?? 0,
					), 200 );

				case 'translations':
					$result = $migrator->importTranslations();
					return new WP_REST_Response( array(
						'message' => sprintf( __( 'Imported %d translations.', 'novatools-polyglot' ), $result['imported'] ?? 0 ),
						'count' => $result['imported'] ?? 0,
					), 200 );

				case 'strings':
					$result = $migrator->importStrings();
					$total  = ( $result['imported'] ?? 0 ) + ( $result['imported_translations'] ?? 0 );
					return new WP_REST_Response( array(
						'message' => sprintf( __( 'Imported %d strings and translations.', 'novatools-polyglot' ), $total ),
						'count' => $total,
					), 200 );

				case 'settings':
					$result = $migrator->importSettings();
					$mappings = $result['mappings'] ?? array();
					$count = count( $mappings );
					return new WP_REST_Response( array(
						'message' => sprintf( __( 'Mapped %d settings.', 'novatools-polyglot' ), $count ),
						'count' => $count,
					), 200 );

				case 'woocommerce':
					$result = $migrator->importWooCommerce();
					$currencies = count( $result['currencies'] ?? array() );
					return new WP_REST_Response( array(
						'message' => sprintf( __( 'Imported WooCommerce data (%d currencies).', 'novatools-polyglot' ), $currencies ),
						'count' => $currencies,
					), 200 );

				default:
					return new WP_Error(
						'polyglot_invalid_step',
						__( 'Invalid import step.', 'novatools-polyglot' ),
						array( 'status' => 400 )
					);
			}
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'polyglot_import_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Verify the import by counting rows in Polyglot tables.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response
	 */
	public function verifyImport( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$tables  = Schema::getTableNames();
		$results = array();

		foreach ( $tables as $short_name ) {
			$full_name = Schema::getTableName( $short_name );
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_name}`" );
			$results[ $short_name ] = (int) $count;
		}

		return new WP_REST_Response( $results, 200 );
	}

	/**
	 * Check if the current user has access to manage imports.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return true|WP_Error
	 */
	public function permissionsCheck( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'polyglot_rest_forbidden',
				__( 'Sorry, you are not allowed to manage imports.', 'novatools-polyglot' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}
}
