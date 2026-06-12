<?php
/**
 * REST API controller for string scanning and registration.
 *
 * Registers endpoints under `/polyglot/v1/scan` for scanning plugin/theme
 * source files, registering discovered strings, importing PO translations,
 * and detecting/cleaning stale strings.
 *
 * @package NovaTools\Polyglot\RestApi
 */

namespace NovaTools\Polyglot\RestApi;

use NovaTools\Polyglot\Core\Plugin;
use NovaTools\Polyglot\FileTranslation\StringExtractor;
use NovaTools\Polyglot\FileTranslation\PoImporter;
use NovaTools\Polyglot\FileTranslation\FileDiscoveryService;
use NovaTools\Polyglot\String\StringManager;
use NovaTools\Polyglot\String\StringRepository;
use NovaTools\Polyglot\Database\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

class ScanController {

	const NAMESPACE = 'polyglot/v1';

	const REST_BASE = 'scan';

	private StringExtractor $extractor;

	private StringManager $manager;

	private PoImporter $importer;

	private FileDiscoveryService $discovery;

	private StringRepository $repository;

	public function __construct(
		StringExtractor $extractor,
		StringManager $manager,
		PoImporter $importer,
		FileDiscoveryService $discovery,
		StringRepository $repository
	) {
		$this->extractor  = $extractor;
		$this->manager    = $manager;
		$this->importer   = $importer;
		$this->discovery  = $discovery;
		$this->repository = $repository;
	}

	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'scanItem' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
					'args'                => $this->getScanArgs(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/register',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'registerItems' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
					'args'                => $this->getScanArgs(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/import-po',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'importPo' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
					'args'                => $this->getImportPoArgs(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/detect-stale',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'detectStale' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
					'args'                => $this->getScanArgs(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/cleanup-stale',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'cleanupStale' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
					'args'                => $this->getCleanupStaleArgs(),
				),
			)
		);
	}

	public function scanItem( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$scope = sanitize_text_field( $request->get_param( 'scope' ) );
		$slug  = sanitize_text_field( $request->get_param( 'slug' ) ) ?: '';
		$path  = sanitize_text_field( $request->get_param( 'path' ) ) ?: '';
		$domain = sanitize_text_field( $request->get_param( 'domain' ) ) ?: '';

		$directory = $this->resolveDirectory( $scope, $slug, $path );

		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$result = $this->extractor->extract( $directory, $domain );

		$po_files = array();
		$source_path = $directory;

		if ( 'plugin' === $scope && ! empty( $slug ) ) {
			$plugin_file = WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php';
			if ( ! file_exists( $plugin_file ) ) {
				$plugin_file = WP_PLUGIN_DIR . '/' . $slug;
			}
			$source_path = WP_PLUGIN_DIR . '/' . $slug;
		} elseif ( 'theme' === $scope && ! empty( $slug ) ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				$source_path = $theme->get_stylesheet_directory();
			}
		}

		$detected_domain = $domain;
		if ( empty( $detected_domain ) ) {
			$detected_domain = $this->detectDomain( $source_path );
		}

		if ( ! empty( $detected_domain ) ) {
			$discovered = $this->discovery->findFiles( $detected_domain, $source_path );
			if ( ! empty( $discovered['po'] ) ) {
				$po_files = array_keys( $discovered['po'] );
			}
		}

		return new WP_REST_Response(
			array(
				'strings'       => array_values( $result['strings'] ),
				'domain_counts' => $result['domain_counts'],
				'total'         => $result['total'],
				'po_files'      => $po_files,
			),
			200
		);
	}

	public function registerItems( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$scope  = sanitize_text_field( $request->get_param( 'scope' ) );
		$slug   = sanitize_text_field( $request->get_param( 'slug' ) ) ?: '';
		$path   = sanitize_text_field( $request->get_param( 'path' ) ) ?: '';
		$domain = sanitize_text_field( $request->get_param( 'domain' ) ) ?: '';

		$directory = $this->resolveDirectory( $scope, $slug, $path );

		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$result = $this->extractor->extract( $directory, $domain );

		$registered = 0;
		$updated    = 0;
		$skipped    = 0;

		$source_path = $directory;
		if ( 'plugin' === $scope && ! empty( $slug ) ) {
			$source_path = WP_PLUGIN_DIR . '/' . $slug;
		} elseif ( 'theme' === $scope && ! empty( $slug ) ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				$source_path = $theme->get_stylesheet_directory();
			}
		}

		$detected_domain = $domain;
		if ( empty( $detected_domain ) ) {
			$detected_domain = $this->detectDomain( $source_path );
		}

		foreach ( $result['strings'] as $entry ) {
			$string_domain = $entry['domain'] ?: $detected_domain;

			if ( empty( $string_domain ) ) {
				++$skipped;
				continue;
			}

			$hash    = md5( $string_domain . '|' . $entry['msgctxt'] . '|' . $entry['msgid'] );
			$existing = $this->repository->findByHash( $hash );

			$this->manager->registerString(
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

		return new WP_REST_Response(
			array(
				'registered' => $registered,
				'updated'    => $updated,
				'skipped'    => $skipped,
				'total'      => $result['total'],
			),
			200
		);
	}

	public function importPo( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$scope     = sanitize_text_field( $request->get_param( 'scope' ) );
		$slug      = sanitize_text_field( $request->get_param( 'slug' ) ) ?: '';
		$path      = sanitize_text_field( $request->get_param( 'path' ) ) ?: '';
		$domain    = sanitize_text_field( $request->get_param( 'domain' ) ) ?: '';
		$languages = $request->get_param( 'languages' );

		if ( is_array( $languages ) ) {
			$languages = array_map( 'sanitize_text_field', $languages );
		} else {
			$languages = array();
		}

		$directory = $this->resolveDirectory( $scope, $slug, $path );

		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$source_path = $directory;
		if ( 'plugin' === $scope && ! empty( $slug ) ) {
			$source_path = WP_PLUGIN_DIR . '/' . $slug;
		} elseif ( 'theme' === $scope && ! empty( $slug ) ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				$source_path = $theme->get_stylesheet_directory();
			}
		}

		$detected_domain = $domain;
		if ( empty( $detected_domain ) ) {
			$detected_domain = $this->detectDomain( $source_path );
		}

		if ( empty( $detected_domain ) ) {
			return new WP_Error(
				'polyglot_domain_not_found',
				__( 'Could not determine text domain. Please provide a domain parameter.', 'novatools-polyglot' ),
				array( 'status' => 400 )
			);
		}

		$discovered = $this->discovery->findFiles( $detected_domain, $source_path );
		$po_files   = $discovered['po'] ?? array();

		if ( empty( $po_files ) ) {
			return new WP_REST_Response(
				array(
					'imported' => array(),
					'message'  => __( 'No PO files found.', 'novatools-polyglot' ),
				),
				200
			);
		}

		$import_results = array();

		foreach ( $po_files as $locale => $locale_files ) {
			if ( ! empty( $languages ) && ! in_array( $locale, $languages, true ) ) {
				continue;
			}

			foreach ( $locale_files as $po_file ) {
				$result = $this->importer->import( $po_file, $detected_domain, $locale );

				if ( ! isset( $import_results[ $locale ] ) ) {
					$import_results[ $locale ] = array(
						'strings_imported'      => 0,
						'translations_imported' => 0,
						'strings_skipped'       => 0,
						'errors'                => array(),
					);
				}

				$import_results[ $locale ]['strings_imported']      += $result['strings_imported'];
				$import_results[ $locale ]['translations_imported'] += $result['translations_imported'];
				$import_results[ $locale ]['strings_skipped']       += $result['strings_skipped'];
				$import_results[ $locale ]['errors']                = array_merge(
					$import_results[ $locale ]['errors'],
					$result['errors']
				);
			}
		}

		return new WP_REST_Response(
			array(
				'imported' => $import_results,
			),
			200
		);
	}

	public function detectStale( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$scope  = sanitize_text_field( $request->get_param( 'scope' ) );
		$slug   = sanitize_text_field( $request->get_param( 'slug' ) ) ?: '';
		$path   = sanitize_text_field( $request->get_param( 'path' ) ) ?: '';
		$domain = sanitize_text_field( $request->get_param( 'domain' ) ) ?: '';

		$directory = $this->resolveDirectory( $scope, $slug, $path );

		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$result = $this->extractor->extract( $directory, $domain );

		$source_path = $directory;
		if ( 'plugin' === $scope && ! empty( $slug ) ) {
			$source_path = WP_PLUGIN_DIR . '/' . $slug;
		} elseif ( 'theme' === $scope && ! empty( $slug ) ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				$source_path = $theme->get_stylesheet_directory();
			}
		}

		$detected_domain = $domain;
		if ( empty( $detected_domain ) ) {
			$detected_domain = $this->detectDomain( $source_path );
		}

		$current_hashes = array();
		foreach ( $result['strings'] as $entry ) {
			$string_domain = $entry['domain'] ?: $detected_domain;
			if ( ! empty( $string_domain ) ) {
				$current_hashes[] = md5( $string_domain . '|' . $entry['msgctxt'] . '|' . $entry['msgid'] );
			}
		}

		global $wpdb;
		$table = Schema::getTableName( 'polyglot_strings' );

		$stale = array();

		if ( ! empty( $detected_domain ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$registered = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, hash, name, domain, value FROM {$table} WHERE domain = %s",
					$detected_domain
				),
				ARRAY_A
			);

			foreach ( $registered as $row ) {
				if ( ! in_array( $row['hash'], $current_hashes, true ) ) {
					$stale[] = $row;
				}
			}
		}

		return new WP_REST_Response(
			array(
				'stale'    => $stale,
				'total'    => count( $stale ),
				'domain'   => $detected_domain,
			),
			200
		);
	}

	public function cleanupStale( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$scope  = sanitize_text_field( $request->get_param( 'scope' ) );
		$slug   = sanitize_text_field( $request->get_param( 'slug' ) ) ?: '';
		$path   = sanitize_text_field( $request->get_param( 'path' ) ) ?: '';
		$domain = sanitize_text_field( $request->get_param( 'domain' ) ) ?: '';
		$confirm = (bool) $request->get_param( 'confirm' );

		if ( ! $confirm ) {
			return new WP_Error(
				'polyglot_confirm_required',
				__( 'You must pass confirm: true to delete stale strings.', 'novatools-polyglot' ),
				array( 'status' => 400 )
			);
		}

		$directory = $this->resolveDirectory( $scope, $slug, $path );

		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$result = $this->extractor->extract( $directory, $domain );

		$source_path = $directory;
		if ( 'plugin' === $scope && ! empty( $slug ) ) {
			$source_path = WP_PLUGIN_DIR . '/' . $slug;
		} elseif ( 'theme' === $scope && ! empty( $slug ) ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				$source_path = $theme->get_stylesheet_directory();
			}
		}

		$detected_domain = $domain;
		if ( empty( $detected_domain ) ) {
			$detected_domain = $this->detectDomain( $source_path );
		}

		$current_hashes = array();
		foreach ( $result['strings'] as $entry ) {
			$string_domain = $entry['domain'] ?: $detected_domain;
			if ( ! empty( $string_domain ) ) {
				$current_hashes[] = md5( $string_domain . '|' . $entry['msgctxt'] . '|' . $entry['msgid'] );
			}
		}

		global $wpdb;
		$strings_table       = Schema::getTableName( 'polyglot_strings' );
		$translations_table  = Schema::getTableName( 'polyglot_string_translations' );

		$deleted_strings      = 0;
		$deleted_translations = 0;

		if ( ! empty( $detected_domain ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$registered = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, hash FROM {$strings_table} WHERE domain = %s",
					$detected_domain
				),
				ARRAY_A
			);

			foreach ( $registered as $row ) {
				if ( ! in_array( $row['hash'], $current_hashes, true ) ) {
					$string_id = (int) $row['id'];

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$trans_deleted = $wpdb->delete(
						$translations_table,
						array( 'string_id' => $string_id ),
						array( '%d' )
					);

					$deleted_translations += (int) $trans_deleted;

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->delete(
						$strings_table,
						array( 'id' => $string_id ),
						array( '%d' )
					);

					++$deleted_strings;
				}
			}
		}

		return new WP_REST_Response(
			array(
				'deleted_strings'      => $deleted_strings,
				'deleted_translations' => $deleted_translations,
			),
			200
		);
	}

	public function permissionsCheck( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'polyglot_rest_forbidden',
				__( 'Sorry, you are not allowed to manage scanning.', 'novatools-polyglot' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	private function resolveDirectory( string $scope, string $slug, string $path ): string|WP_Error {
		switch ( $scope ) {
			case 'plugin':
				if ( empty( $slug ) ) {
					return new WP_Error(
						'polyglot_missing_slug',
						__( 'Plugin slug is required.', 'novatools-polyglot' ),
						array( 'status' => 400 )
					);
				}

				$dir = WP_PLUGIN_DIR . '/' . $slug;

				if ( ! is_dir( $dir ) ) {
					return new WP_Error(
						'polyglot_plugin_not_found',
						sprintf(
							/* translators: %s: plugin slug */
							__( 'Plugin directory not found: %s', 'novatools-polyglot' ),
							$slug
						),
						array( 'status' => 404 )
					);
				}

				return $dir;

			case 'theme':
				if ( empty( $slug ) ) {
					return new WP_Error(
						'polyglot_missing_slug',
						__( 'Theme slug is required.', 'novatools-polyglot' ),
						array( 'status' => 400 )
					);
				}

				$theme = wp_get_theme( $slug );

				if ( ! $theme->exists() ) {
					return new WP_Error(
						'polyglot_theme_not_found',
						sprintf(
							/* translators: %s: theme slug */
							__( 'Theme not found: %s', 'novatools-polyglot' ),
							$slug
						),
						array( 'status' => 404 )
					);
				}

				return $theme->get_stylesheet_directory();

			case 'path':
				if ( empty( $path ) ) {
					return new WP_Error(
						'polyglot_missing_path',
						__( 'Path is required when scope is "path".', 'novatools-polyglot' ),
						array( 'status' => 400 )
					);
				}

				$real = realpath( $path );

				if ( false === $real || ! is_dir( $real ) ) {
					return new WP_Error(
						'polyglot_path_not_found',
						sprintf(
							/* translators: %s: directory path */
							__( 'Directory not found: %s', 'novatools-polyglot' ),
							$path
						),
						array( 'status' => 404 )
					);
				}

				return $real;

			default:
				return new WP_Error(
					'polyglot_invalid_scope',
					__( 'Scope must be plugin, theme, or path.', 'novatools-polyglot' ),
					array( 'status' => 400 )
				);
		}
	}

	private function detectDomain( string $directory ): string {
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

		$plugin_files = glob( $directory . '/*.php' );
		if ( $plugin_files ) {
			foreach ( $plugin_files as $file ) {
				$headers = get_plugin_data( $file, false, false );
				if ( ! empty( $headers['TextDomain'] ) ) {
					return $headers['TextDomain'];
				}
			}
		}

		return '';
	}

	private function getScanArgs(): array {
		return array(
			'scope' => array(
				'description'       => __( 'Scan scope: plugin, theme, or path.', 'novatools-polyglot' ),
				'type'              => 'string',
				'required'          => true,
				'enum'              => array( 'plugin', 'theme', 'path' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'slug' => array(
				'description'       => __( 'Plugin or theme slug.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'path' => array(
				'description'       => __( 'Custom directory path (when scope is "path").', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'domain' => array(
				'description'       => __( 'Text domain filter. Auto-detected if omitted.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	private function getImportPoArgs(): array {
		$args = $this->getScanArgs();

		$args['languages'] = array(
			'description' => __( 'Array of locale codes to import. Imports all if omitted.', 'novatools-polyglot' ),
			'type'        => 'array',
			'items'       => array( 'type' => 'string' ),
		);

		return $args;
	}

	private function getCleanupStaleArgs(): array {
		$args = $this->getScanArgs();

		$args['confirm'] = array(
			'description' => __( 'Must be true to proceed with deletion.', 'novatools-polyglot' ),
			'type'        => 'boolean',
			'required'    => true,
		);

		return $args;
	}
}
