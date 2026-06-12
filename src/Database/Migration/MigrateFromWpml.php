<?php
/**
 * WPML-to-PolyGlot migrator for NovaTools Polyglot.
 *
 * Imports data from existing WPML tables (prefixed `icl_`) into the PolyGlot
 * schema (`polyglot_*`). The import is non-destructive — WPML tables are only
 * read, never modified. Supports batched processing, dry-run mode, and
 * post-import verification.
 *
 * Source tables mapped:
 *   - icl_languages + icl_flags + icl_locale_map   → polyglot_languages
 *   - icl_translations + icl_translation_status     → polyglot_translations
 *   - icl_strings + icl_string_translations         → polyglot_strings + polyglot_string_translations
 *   - icl_string_packages                           → polyglot_string_packages
 *   - icl_sitepress_settings (option)               → polyglot_settings (option)
 *   - wcml_settings (option)                        → polyglot_settings.woocommerce
 *
 * @package NovaTools\Polyglot\Database\Migration
 */

namespace NovaTools\Polyglot\Database\Migration;

use NovaTools\Polyglot\Database\Schema;
use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class MigrateFromWpml {

	/**
	 * Number of rows to process per batch.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 1000;

	/**
	 * WPML table names that indicate an active WPML installation.
	 *
	 * @var string[]
	 */
	const DETECT_TABLES = array(
		'icl_translations',
		'icl_strings',
		'icl_string_packages',
		'icl_languages',
	);

	/**
	 * Whether this is a dry run (no database writes).
	 *
	 * @var bool
	 */
	private bool $dryRun = false;

	/**
	 * Collected report entries for dry-run and verification output.
	 *
	 * @var array<string, array{source: int, destination: int, label: string}>
	 */
	private array $report = array();

	/**
	 * Callback invoked for progress reporting.
	 *
	 * Receives (string $step, int $current, int $total).
	 *
	 * @var callable|null
	 */
	private $progressCallback = null;

	/**
	 * Whether term counting is currently deferred (for performance).
	 *
	 * @var bool
	 */
	private bool $termCountingDeferred = false;

	// ─── Detection ─────────────────────────────────────────────────────────

	/**
	 * Detect whether WPML tables exist in the database.
	 *
	 * Returns an associative array describing what was found:
	 *   - 'found'           => bool  — at least one icl_ table exists
	 *   - 'missing_tables'  => string[] — tables that were expected but absent
	 *   - 'found_tables'    => string[] — tables that exist
	 *   - 'has_languages'   => bool
	 *   - 'has_translations' => bool
	 *   - 'has_strings'     => bool
	 *   - 'has_packages'    => bool
	 *   - 'has_settings'    => bool — icl_sitepress_settings option exists
	 *   - 'has_woocommerce' => bool — wcml_settings option exists
	 *
	 * @return array
	 */
	public function detect(): array {
		global $wpdb;

		$result = array(
			'found'            => false,
			'missing_tables'   => array(),
			'found_tables'     => array(),
			'has_languages'    => false,
			'has_translations' => false,
			'has_strings'      => false,
			'has_packages'     => false,
			'has_settings'     => false,
			'has_woocommerce'  => false,
		);

		// Collect all tables with the WP prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_col(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->prefix . 'icl_%'
			)
		);

		$existing = is_array( $existing ) ? $existing : array();

		foreach ( self::DETECT_TABLES as $table_short ) {
			$full_name = $wpdb->prefix . $table_short;

			if ( in_array( $full_name, $existing, true ) ) {
				$result['found_tables'][] = $table_short;
			} else {
				$result['missing_tables'][] = $table_short;
			}
		}

		$result['found']            = count( $result['found_tables'] ) > 0;
		$result['has_languages']    = in_array( 'icl_languages', $result['found_tables'], true );
		$result['has_translations'] = in_array( 'icl_translations', $result['found_tables'], true );
		$result['has_strings']      = in_array( 'icl_strings', $result['found_tables'], true );
		$result['has_packages']     = in_array( 'icl_string_packages', $result['found_tables'], true );

		// Check for WPML settings option.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$settings_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'icl_sitepress_settings'
			)
		);
		$result['has_settings'] = ! empty( $settings_row );

		// Check for WooCommerce Multilingual settings.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wcml_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'wcml_settings'
			)
		);
		$result['has_woocommerce'] = ! empty( $wcml_row );

		return $result;
	}

	// ─── Configuration ─────────────────────────────────────────────────────

	/**
	 * Enable or disable dry-run mode.
	 *
	 * In dry-run mode, all import methods collect statistics and return a
	 * report without modifying the database.
	 *
	 * @param bool $enable True to enable dry-run, false to disable.
	 * @return static
	 */
	public function setDryRun( bool $enable = true ): static {
		$this->dryRun = $enable;
		return $this;
	}

	/**
	 * Set a callback for progress reporting during import.
	 *
	 * The callback receives (string $step, int $current, int $total).
	 *
	 * @param callable $callback Progress callback.
	 * @return static
	 */
	public function setProgressCallback( callable $callback ): static {
		$this->progressCallback = $callback;
		return $this;
	}

	/**
	 * Check whether dry-run mode is active.
	 *
	 * @return bool
	 */
	public function isDryRun(): bool {
		return $this->dryRun;
	}

	// ─── Import Steps ──────────────────────────────────────────────────────

	/**
	 * Import WPML languages into polyglot_languages.
	 *
	 * Maps data from icl_languages, icl_flags, and icl_locale_map into a
	 * single unified language row. Preserves active/default status from WPML.
	 *
	 * @return array{imported: int, skipped: int, errors: string[]}
	 */
	public function importLanguages(): array {
		global $wpdb;

		$result   = array( 'imported' => 0, 'skipped' => 0, 'errors' => array() );
		$detected = $this->detect();

		if ( ! $detected['has_languages'] ) {
			$result['errors'][] = 'icl_languages table not found.';
			return $result;
		}

		// Load locale map: code => locale.
		$locale_map = $this->loadLocaleMap();

		// Load flag URLs: code => flag_url.
		$flag_map = $this->loadFlagMap();

		// Load WPML active languages and default language from settings.
		$wpml_settings = $this->loadWpmlSettings();
		$active_codes  = isset( $wpml_settings['active_languages'] ) && is_array( $wpml_settings['active_languages'] )
			? $wpml_settings['active_languages']
			: array();
		$default_code  = $wpml_settings['default_language'] ?? '';

		// Fetch all WPML language rows.
		$icl_table = $wpdb->prefix . 'icl_languages';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT * FROM {$icl_table}",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$result['errors'][] = 'Failed to query icl_languages.';
			return $result;
		}

		$total   = count( $rows );
		$current = 0;

		$this->reportProgress( 'languages', 0, $total );

		$dest_table = Schema::getTableName( 'polyglot_languages' );

		foreach ( $rows as $row ) {
			$current++;
			$code = $row['code'] ?? '';

			if ( '' === $code ) {
				$result['skipped']++;
				continue;
			}

			$locale     = $locale_map[ $code ] ?? ( $row['locale'] ?? $code );
			$is_active  = in_array( $code, $active_codes, true ) || ! empty( $row['active'] );
			$is_default = ( $code === $default_code );
			$direction  = $row['direction'] ?? 'ltr';
			$flag_code  = $row['code'] ?? '';
			$flag_url   = $flag_map[ $code ] ?? '';
			$sort_order = (int) ( $row['sort_order'] ?? 0 );

			// Get names — WPML stores english_name and native_name directly.
			$english_name = $row['english_name'] ?? $code;
			$native_name  = $row['major'] ?? '';

			// If native_name is empty, try icl_languages_translations.
			if ( '' === $native_name ) {
				$native_name = $this->getNativeName( $code ) ?: $english_name;
			}

			if ( $this->dryRun ) {
				$result['imported']++;
				$this->reportProgress( 'languages', $current, $total );
				continue;
			}

			// Insert or update the language in polyglot_languages.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$existing = $wpdb->get_row(
				$wpdb->prepare( "SELECT id FROM {$dest_table} WHERE code = %s", $code )
			);

			$data = array(
				'code'          => $code,
				'locale'        => $locale,
				'english_name'  => $english_name,
				'native_name'   => $native_name,
				'is_active'     => $is_active ? 1 : 0,
				'is_default'    => $is_default ? 1 : 0,
				'direction'     => $direction,
				'flag_code'     => $flag_code,
				'flag_url'      => $flag_url,
				'sort_order'    => $sort_order,
			);

			if ( $existing ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->update( $dest_table, $data, array( 'code' => $code ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert( $dest_table, $data );
			}

			if ( $wpdb->last_error ) {
				$result['errors'][] = sprintf( 'Language %s: %s', $code, $wpdb->last_error );
				$result['skipped']++;
			} else {
				$result['imported']++;
			}

			$this->reportProgress( 'languages', $current, $total );
		}

		$this->report['languages'] = array(
			'source'      => $total,
			'destination' => $result['imported'],
			'label'       => 'Languages',
		);

		return $result;
	}

	/**
	 * Import WPML content translations into polyglot_translations.
	 *
	 * Reads from icl_translations in batches of BATCH_SIZE and merges
	 * status information from icl_translation_status. Uses
	 * wp_defer_term_counting(true) for performance during bulk inserts.
	 *
	 * @return array{imported: int, skipped: int, errors: string[]}
	 */
	public function importTranslations(): array {
		global $wpdb;

		$result   = array( 'imported' => 0, 'skipped' => 0, 'errors' => array() );
		$detected = $this->detect();

		if ( ! $detected['has_translations'] ) {
			$result['errors'][] = 'icl_translations table not found.';
			return $result;
		}

		// Pre-load translation status map: translation_id => status data.
		$status_map = $this->loadTranslationStatusMap();

		$icl_table  = $wpdb->prefix . 'icl_translations';
		$dest_table = Schema::getTableName( 'polyglot_translations' );

		// Get total count for progress reporting.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$icl_table}" );

		$this->reportProgress( 'translations', 0, $total );

		// Enable performance optimisations for bulk import.
		$this->deferTermCounting();

		$offset   = 0;
		$imported = 0;
		$skipped  = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$icl_table} ORDER BY translation_id ASC LIMIT %d OFFSET %d",
					self::BATCH_SIZE,
					$offset
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$element_type = $row['element_type'] ?? '';
				$element_id   = $row['element_id'] ?? null;

				// Skip rows with null element_id (orphans).
				if ( null === $element_id || '' === $element_type ) {
					$skipped++;
					continue;
				}

				$translation_id = (int) $row['translation_id'];
				$trid           = (int) $row['trid'];
				$language_code  = $row['language_code'] ?? '';
				$source_lang    = $row['source_language_code'] ?? '';

				// Merge status from icl_translation_status.
				$status     = 'not_translated';
				$checksum   = '';
				$translator = null;
				$provider   = '';

				if ( isset( $status_map[ $translation_id ] ) ) {
					$st         = $status_map[ $translation_id ];
					$status     = $this->mapTranslationStatus( $st['status'] ?? 0 );
					$checksum   = $st['checksum'] ?? '';
					$translator = $st['translator_id'] ?? null;
					$provider   = $st['translation_service'] ?? '';
				} else {
					// If there is no status row but the element exists, it's
					// typically "translated" (WPML default for published content).
					$status = 'translated';
				}

				if ( $this->dryRun ) {
					$imported++;
					continue;
				}

				$data = array(
					'element_type'         => $element_type,
					'element_id'           => (int) $element_id,
					'trid'                 => $trid,
					'language_code'        => $language_code,
					'source_language_code' => $source_lang,
					'status'               => $status,
					'checksum'             => $checksum,
					'translator_id'        => $translator,
					'provider'             => $provider,
				);

				// Use replace to handle duplicates gracefully (UNIQUE KEY on element_type+element_id).
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$inserted = $wpdb->replace( $dest_table, $data );

				if ( false === $inserted ) {
					$result['errors'][] = sprintf(
						'Translation trid=%d lang=%s: %s',
						$trid,
						$language_code,
						$wpdb->last_error
					);
					$skipped++;
				} else {
					$imported++;
				}
			}

			$offset += self::BATCH_SIZE;
			$this->reportProgress( 'translations', min( $offset, $total ), $total );
		}

		// Restore term counting.
		$this->restoreTermCounting();

		$result['imported'] = $imported;
		$result['skipped']  = $skipped;

		$this->report['translations'] = array(
			'source'      => $total,
			'destination' => $imported,
			'label'       => 'Translations',
		);

		return $result;
	}

	/**
	 * Import WPML string translations into polyglot_strings and
	 * polyglot_string_translations, plus string packages.
	 *
	 * Reads from icl_strings, icl_string_translations, and
	 * icl_string_packages in batches.
	 *
	 * @return array{imported: int, imported_translations: int, imported_packages: int, errors: string[]}
	 */
	public function importStrings(): array {
		global $wpdb;

		$result   = array(
			'imported'             => 0,
			'imported_translations' => 0,
			'imported_packages'    => 0,
			'errors'               => array(),
		);
		$detected = $this->detect();

		if ( ! $detected['has_strings'] ) {
			$result['errors'][] = 'icl_strings table not found.';
			return $result;
		}

		$this->deferTermCounting();

		// ── Step 1: Import string packages ────────────────────────────
		if ( $detected['has_packages'] ) {
			$pkg_result = $this->importStringPackages();
			$result['imported_packages'] = $pkg_result['imported'];
			if ( ! empty( $pkg_result['errors'] ) ) {
				$result['errors'] = array_merge( $result['errors'], $pkg_result['errors'] );
			}
		}

		// ── Step 2: Import strings in batches ─────────────────────────
		$icl_strings = $wpdb->prefix . 'icl_strings';
		$dest_strings = Schema::getTableName( 'polyglot_strings' );
		$wpml_to_polyglot_id = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_strings = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$icl_strings}" );
		$this->reportProgress( 'strings', 0, $total_strings );

		$offset = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$icl_strings} ORDER BY id ASC LIMIT %d OFFSET %d",
					self::BATCH_SIZE,
					$offset
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$wpml_id = (int) $row['id'];
				$domain  = $row['context'] ?? '';
				$name    = $row['name'] ?? '';
				$value   = $row['value'] ?? '';
				$context = '';

				// Compute hash the same way StringManager does.
				$hash = md5( $domain . '|' . $context . '|' . $name );

				// Map WPML string type.
				$type = $this->mapStringType( $row['type'] ?? 'LINE' );

				// Look up the PolyGlot package ID for this domain.
				$package_id = $this->resolvePackageId( $domain );

				if ( $this->dryRun ) {
					$result['imported']++;
					// Track mapping even in dry-run for string translations step.
					$wpml_to_polyglot_id[ $wpml_id ] = $wpml_id;
					continue;
				}

				$data = array(
					'domain'              => $domain,
					'context'             => $context,
					'name'                => $name,
					'value'               => $value,
					'hash'                => $hash,
					'package_id'          => $package_id > 0 ? $package_id : null,
					'type'                => $type,
					'title'               => $row['title'] ?? $name,
					'status'              => (int) ( $row['status'] ?? 0 ),
					'translation_priority' => $row['translation_priority'] ?? 'optional',
					'word_count'          => str_word_count( strip_tags( $value ) ),
				);

				// Check for existing string by hash.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$existing = $wpdb->get_row(
					$wpdb->prepare( "SELECT id FROM {$dest_strings} WHERE hash = %s", $hash )
				);

				if ( $existing ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->update( $dest_strings, $data, array( 'id' => $existing->id ) );
					$wpml_to_polyglot_id[ $wpml_id ] = (int) $existing->id;
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->insert( $dest_strings, $data );
					$wpml_to_polyglot_id[ $wpml_id ] = (int) $wpdb->insert_id;
				}

				if ( $wpdb->last_error ) {
					$result['errors'][] = sprintf( 'String id=%d: %s', $wpml_id, $wpdb->last_error );
				} else {
					$result['imported']++;
				}
			}

			$offset += self::BATCH_SIZE;
			$this->reportProgress( 'strings', min( $offset, $total_strings ), $total_strings );
		}

		$this->report['strings'] = array(
			'source'      => $total_strings,
			'destination' => $result['imported'],
			'label'       => 'Strings',
		);

		// ── Step 3: Import string translations in batches ─────────────
		$icl_st_table = $wpdb->prefix . 'icl_string_translations';
		$dest_st      = Schema::getTableName( 'polyglot_string_translations' );

		// Check if the source table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$icl_st_table
			)
		);

		if ( $table_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total_translations = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$icl_st_table}" );
			$this->reportProgress( 'string_translations', 0, $total_translations );

			$st_offset = 0;

			while ( true ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$st_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$icl_st_table} ORDER BY id ASC LIMIT %d OFFSET %d",
						self::BATCH_SIZE,
						$st_offset
					),
					ARRAY_A
				);

				if ( ! is_array( $st_rows ) || empty( $st_rows ) ) {
					break;
				}

				foreach ( $st_rows as $st_row ) {
					$wpml_string_id = (int) ( $st_row['string_id'] ?? 0 );

					if ( 0 === $wpml_string_id ) {
						continue;
					}

					// Resolve to PolyGlot string ID.
					$polyglot_string_id = $wpml_to_polyglot_id[ $wpml_string_id ] ?? null;

					if ( null === $polyglot_string_id && ! $this->dryRun ) {
						continue; // Orphan translation, skip.
					}

					if ( $this->dryRun ) {
						$result['imported_translations']++;
						continue;
					}

					$st_data = array(
						'string_id'    => $polyglot_string_id,
						'language'     => $st_row['language'] ?? '',
						'status'       => (int) ( $st_row['status'] ?? 0 ),
						'value'        => $st_row['value'] ?? '',
						'translator_id' => isset( $st_row['translator_id'] ) && $st_row['translator_id'] > 0
							? (int) $st_row['translator_id']
							: null,
						'provider'     => $st_row['translation_service'] ?? '',
					);

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->replace( $dest_st, $st_data );

					if ( $wpdb->last_error ) {
						$result['errors'][] = sprintf(
							'String translation string_id=%d lang=%s: %s',
							$wpml_string_id,
							$st_row['language'] ?? '?',
							$wpdb->last_error
						);
					} else {
						$result['imported_translations']++;
					}
				}

				$st_offset += self::BATCH_SIZE;
				$this->reportProgress( 'string_translations', min( $st_offset, $total_translations ), $total_translations );
			}

			$this->report['string_translations'] = array(
				'source'      => $total_translations,
				'destination' => $result['imported_translations'],
				'label'       => 'String Translations',
			);
		}

		$this->restoreTermCounting();

		return $result;
	}

	/**
	 * Import WPML settings from icl_sitepress_settings into polyglot_settings.
	 *
	 * Maps URL strategy, active languages, custom field configuration,
	 * and browser redirect settings.
	 *
	 * @return array{imported: bool, mappings: array, errors: string[]}
	 */
	public function importSettings(): array {
		global $wpdb;

		$result = array(
			'imported' => false,
			'mappings' => array(),
			'errors'   => array(),
		);

		$wpml_settings = $this->loadWpmlSettings();

		if ( empty( $wpml_settings ) ) {
			$result['errors'][] = 'No WPML settings found (icl_sitepress_settings option missing or empty).';
			return $result;
		}

		$mappings = array();

		// ── URL Strategy ──────────────────────────────────────────────
		$url_strategy_map = array(
			'1' => 'directory',      // /en/, /fr/
			'2' => 'query_param',    // ?lang=xx
			'3' => 'subdomain',      // en.example.com
			'4' => 'domain',         // example.fr
		);

		$negotiation_type = $wpml_settings['language_negotiation_type'] ?? '1';
		$mappings['url_strategy'] = $url_strategy_map[ $negotiation_type ] ?? 'directory';

		// ── Active Languages ──────────────────────────────────────────
		if ( isset( $wpml_settings['active_languages'] ) && is_array( $wpml_settings['active_languages'] ) ) {
			$mappings['active_languages'] = $wpml_settings['active_languages'];
		}

		// ── Default Language ──────────────────────────────────────────
		if ( isset( $wpml_settings['default_language'] ) ) {
			$mappings['default_language'] = $wpml_settings['default_language'];
		}

		// ── Browser Redirect ──────────────────────────────────────────
		$mappings['browser_redirect'] = ! empty( $wpml_settings['automatic_redirect'] );

		// ── Hide Default Language Prefix ──────────────────────────────
		$mappings['hide_default_language_prefix'] = ! empty( $wpml_settings['language_negotiation_type'] )
			&& '1' === $wpml_settings['language_negotiation_type']
			&& ! empty( $wpml_settings['language_domain_base'] );

		// ── Custom Field Configuration ────────────────────────────────
		if ( isset( $wpml_settings['translation-management'] ) && is_array( $wpml_settings['translation-management'] ) ) {
			$tm = $wpml_settings['translation-management'];

			// Custom fields: copy vs translate vs ignore.
			$custom_fields = array();
			if ( isset( $tm['custom_fields_translation'] ) && is_array( $tm['custom_fields_translation'] ) ) {
				foreach ( $tm['custom_fields_translation'] as $field => $mode ) {
					$custom_fields[ $field ] = $this->mapCustomFieldMode( $mode );
				}
			}
			if ( ! empty( $custom_fields ) ) {
				$mappings['custom_fields'] = $custom_fields;
			}

			// Post types to translate.
			if ( isset( $tm['custom_types_readonly_config'] ) && is_array( $tm['custom_types_readonly_config'] ) ) {
				$mappings['post_types'] = array_keys( array_filter( $tm['custom_types_readonly_config'] ) );
			}

			// Taxonomies to translate.
			if ( isset( $tm['taxonomies_readonly_config'] ) && is_array( $tm['taxonomies_readonly_config'] ) ) {
				$mappings['taxonomies'] = array_keys( array_filter( $tm['taxonomies_readonly_config'] ) );
			}
		}

		$result['mappings'] = $mappings;

		if ( $this->dryRun ) {
			$result['imported'] = true;
			return $result;
		}

		// Persist to polyglot_settings option.
		$option_store = new OptionStore();
		$saved = $option_store->merge( $mappings );

		if ( ! $saved ) {
			$result['errors'][] = 'Failed to save polyglot_settings option.';
		} else {
			$result['imported'] = true;
		}

		return $result;
	}

	/**
	 * Import WooCommerce Multilingual settings from wcml_settings.
	 *
	 * Maps multi-currency configuration, exchange rates, and currency
	 * assignments into the polyglot_settings option.
	 *
	 * @return array{imported: bool, currencies: string[], errors: string[]}
	 */
	public function importWooCommerce(): array {
		global $wpdb;

		$result = array(
			'imported'  => false,
			'currencies' => array(),
			'errors'    => array(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wcml_raw = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'wcml_settings'
			)
		);

		if ( empty( $wcml_raw ) ) {
			$result['errors'][] = 'No WooCommerce Multilingual settings found (wcml_settings option missing).';
			return $result;
		}

		$wcml_settings = maybe_unserialize( $wcml_raw->option_value );

		if ( ! is_array( $wcml_settings ) ) {
			$result['errors'][] = 'wcml_settings is not a valid serialized array.';
			return $result;
		}

		$woocommerce = array();

		// ── Multi-currency settings ───────────────────────────────────
		$woocommerce['multi_currency_enabled'] = ! empty( $wcml_settings['enable_multi_currency'] );

		// Enabled currencies.
		if ( isset( $wcml_settings['currency_options'] ) && is_array( $wcml_settings['currency_options'] ) ) {
			$currencies = array();

			foreach ( $wcml_settings['currency_options'] as $currency_code => $options ) {
				if ( ! empty( $options['is_enabled'] ) ) {
					$currencies[] = $currency_code;
				}
			}

			$woocommerce['currencies']         = $currencies;
			$woocommerce['currency_options']    = $wcml_settings['currency_options'];
			$result['currencies']               = $currencies;
		}

		// Exchange rates.
		if ( isset( $wcml_settings['currency_exchange_rates'] ) && is_array( $wcml_settings['currency_exchange_rates'] ) ) {
			$woocommerce['exchange_rates'] = $wcml_settings['currency_exchange_rates'];
		}

		// Currency per language assignments.
		if ( isset( $wcml_settings['default_currencies'] ) && is_array( $wcml_settings['default_currencies'] ) ) {
			$woocommerce['currency_language_map'] = $wcml_settings['default_currencies'];
		}

		// Currency switcher settings.
		if ( isset( $wcml_settings['currency_switcher'] ) ) {
			$woocommerce['currency_switcher'] = $wcml_settings['currency_switcher'];
		}

		if ( $this->dryRun ) {
			$result['imported'] = true;
			return $result;
		}

		// Persist to polyglot_settings.woocommerce.
		$option_store = new OptionStore();
		$saved = $option_store->set( 'woocommerce', $woocommerce );

		if ( ! $saved ) {
			$result['errors'][] = 'Failed to save WooCommerce settings to polyglot_settings.';
		} else {
			$result['imported'] = true;
		}

		return $result;
	}

	// ─── Verification ──────────────────────────────────────────────────────

	/**
	 * Verify import results by comparing source and destination row counts.
	 *
	 * Compares WPML table row counts against PolyGlot table row counts
	 * and returns a detailed report with pass/fail status per step.
	 *
	 * @return array<string, array{source: int, destination: int, match: bool, label: string}>
	 */
	public function verify(): array {
		global $wpdb;

		$verification = array();

		// Languages.
		$verification['languages'] = $this->verifyTableCounts(
			'icl_languages',
			'polyglot_languages',
			'Languages'
		);

		// Translations.
		$verification['translations'] = $this->verifyTableCounts(
			'icl_translations',
			'polyglot_translations',
			'Translations'
		);

		// Strings.
		$verification['strings'] = $this->verifyTableCounts(
			'icl_strings',
			'polyglot_strings',
			'Strings'
		);

		// String translations.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$st_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$wpdb->prefix . 'icl_string_translations'
			)
		);

		if ( $st_exists ) {
			$verification['string_translations'] = $this->verifyTableCounts(
				'icl_string_translations',
				'polyglot_string_translations',
				'String Translations'
			);
		}

		// String packages.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$sp_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$wpdb->prefix . 'icl_string_packages'
			)
		);

		if ( $sp_exists ) {
			$verification['string_packages'] = $this->verifyTableCounts(
				'icl_string_packages',
				'polyglot_string_packages',
				'String Packages'
			);
		}

		return $verification;
	}

	/**
	 * Get the dry-run report (only populated after dry-run import).
	 *
	 * @return array<string, array{source: int, destination: int, label: string}>
	 */
	public function getReport(): array {
		return $this->report;
	}

	/**
	 * Run the full import pipeline in order.
	 *
	 * Steps: (1) languages, (2) translations, (3) strings,
	 *        (4) settings, (5) WooCommerce data.
	 *
	 * @return array{
	 *     languages: array,
	 *     translations: array,
	 *     strings: array,
	 *     settings: array,
	 *     woocommerce: array,
	 *     verification: array,
	 *     dry_run: bool
	 * }
	 */
	public function runFullImport(): array {
		$results = array(
			'languages'    => $this->importLanguages(),
			'translations' => $this->importTranslations(),
			'strings'      => $this->importStrings(),
			'settings'     => $this->importSettings(),
			'woocommerce'  => $this->importWooCommerce(),
			'verification' => array(),
			'dry_run'      => $this->dryRun,
		);

		// Run verification (even in dry-run, to show source counts).
		if ( ! $this->dryRun ) {
			$results['verification'] = $this->verify();
		} else {
			$results['verification'] = $this->report;
		}

		return $results;
	}

	// ─── Private Helpers ───────────────────────────────────────────────────

	/**
	 * Load WPML locale map from icl_locale_map table.
	 *
	 * @return array<string, string> code => locale mapping.
	 */
	private function loadLocaleMap(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'icl_locale_map';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$table
			)
		);

		if ( ! $exists ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT code, locale FROM {$table}", ARRAY_A );

		return is_array( $rows ) ? array_column( $rows, 'locale', 'code' ) : array();
	}

	/**
	 * Load WPML flag URLs from icl_flags table.
	 *
	 * @return array<string, string> code => flag_url mapping.
	 */
	private function loadFlagMap(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'icl_flags';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$table
			)
		);

		if ( ! $exists ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT lang_code, flag_url FROM {$table}", ARRAY_A );

		return is_array( $rows ) ? array_column( $rows, 'flag_url', 'lang_code' ) : array();
	}

	/**
	 * Get the native name for a language code from icl_languages_translations.
	 *
	 * @param string $code Language code.
	 * @return string Native name, or empty string if not found.
	 */
	private function getNativeName( string $code ): string {
		global $wpdb;

		$table = $wpdb->prefix . 'icl_languages_translations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$table
			)
		);

		if ( ! $exists ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT name FROM {$table} WHERE language_code = %s AND display_language_code = %s LIMIT 1",
				$code,
				$code
			)
		);

		return $row ? $row->name : '';
	}

	/**
	 * Load WPML settings from the icl_sitepress_settings option.
	 *
	 * @return array Deserialized settings array.
	 */
	private function loadWpmlSettings(): array {
		$raw = get_option( 'icl_sitepress_settings', '' );

		if ( '' === $raw ) {
			return array();
		}

		$settings = maybe_unserialize( $raw );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Load translation status data from icl_translation_status.
	 *
	 * Returns a map of translation_id => status data for efficient
	 * lookups during importTranslations().
	 *
	 * @return array<int, array>
	 */
	private function loadTranslationStatusMap(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'icl_translation_status';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$table
			)
		);

		if ( ! $exists ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT translation_id, status, checksum, translator_id, translation_service FROM {$table}",
			ARRAY_A
		);

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ (int) $row['translation_id'] ] = $row;
			}
		}

		return $map;
	}

	/**
	 * Map a WPML translation status code to a PolyGlot status string.
	 *
	 * WPML status codes:
	 *   0  = not translated
	 *   1  = in progress
	 *   2  = translated (complete)
	 *   3  = awaiting review
	 *   4  = needs update
	 *   10 = completed (WPML Translation Management)
	 *
	 * @param int $wpml_status WPML numeric status code.
	 * @return string PolyGlot status enum value.
	 */
	private function mapTranslationStatus( int $wpml_status ): string {
		return match ( $wpml_status ) {
			0   => 'not_translated',
			1   => 'in_progress',
			2   => 'translated',
			3   => 'awaiting_review',
			4   => 'needs_update',
			10  => 'completed',
			default => 'not_translated',
		};
	}

	/**
	 * Map a WPML string type to a PolyGlot string type.
	 *
	 * @param string $wpml_type WPML string type.
	 * @return string PolyGlot string type.
	 */
	private function mapStringType( string $wpml_type ): string {
		$wpml_type = strtoupper( trim( $wpml_type ) );

		return match ( $wpml_type ) {
			'LINE'     => 'LINE',
			'TEXTAREA' => 'TEXTAREA',
			'VISUAL'   => 'VISUAL',
			default    => 'LINE',
		};
	}

	/**
	 * Map a WPML custom field translation mode to a PolyGlot mode.
	 *
	 * WPML modes: 0 = ignore, 1 = copy, 2 = translate.
	 *
	 * @param int $mode WPML mode code.
	 * @return string 'ignore', 'copy', or 'translate'.
	 */
	private function mapCustomFieldMode( int $mode ): string {
		return match ( $mode ) {
			0   => 'ignore',
			1   => 'copy',
			2   => 'translate',
			default => 'ignore',
		};
	}

	/**
	 * Import string packages from icl_string_packages.
	 *
	 * @return array{imported: int, errors: string[]}
	 */
	private function importStringPackages(): array {
		global $wpdb;

		$result = array( 'imported' => 0, 'errors' => array() );

		$icl_table = $wpdb->prefix . 'icl_string_packages';
		$dest_table = Schema::getTableName( 'polyglot_string_packages' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$icl_table}", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return $result;
		}

		foreach ( $rows as $row ) {
			if ( $this->dryRun ) {
				$result['imported']++;
				continue;
			}

			$data = array(
				'kind'        => $row['kind'] ?? '',
				'kind_slug'   => $row['kind_slug'] ?? '',
				'name'        => $row['name'] ?? '',
				'title'       => $row['title'] ?? '',
				'description' => $row['description'] ?? '',
			);

			// Use replace to handle the unique key (kind, kind_slug, name).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->replace( $dest_table, $data );

			if ( false === $inserted ) {
				$result['errors'][] = sprintf(
					'Package %s/%s: %s',
					$data['kind'],
					$data['name'],
					$wpdb->last_error
				);
			} else {
				$result['imported']++;
			}
		}

		$this->report['string_packages'] = array(
			'source'      => count( $rows ),
			'destination' => $result['imported'],
			'label'       => 'String Packages',
		);

		return $result;
	}

	/**
	 * Resolve a text domain to a PolyGlot package ID.
	 *
	 * @param string $domain Text domain.
	 * @return int Package ID, or 0 if not found.
	 */
	private function resolvePackageId( string $domain ): int {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_string_packages' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s LIMIT 1", $domain )
		);

		return $row ? (int) $row->id : 0;
	}

	/**
	 * Compare row counts between a WPML source table and a PolyGlot table.
	 *
	 * @param string $source_short  WPML table short name (without prefix).
	 * @param string $dest_short    PolyGlot table short name (without prefix).
	 * @param string $label         Human-readable label for the report.
	 * @return array{source: int, destination: int, match: bool, label: string}
	 */
	private function verifyTableCounts( string $source_short, string $dest_short, string $label ): array {
		global $wpdb;

		$source_table = $wpdb->prefix . $source_short;
		$dest_table   = Schema::getTableName( $dest_short );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$source_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$source_table}" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$dest_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$dest_table}" );

		return array(
			'source'      => $source_count,
			'destination' => $dest_count,
			'match'       => ( $source_count === $dest_count ),
			'label'       => $label,
		);
	}

	/**
	 * Enable deferred term counting for import performance.
	 *
	 * @return void
	 */
	private function deferTermCounting(): void {
		if ( function_exists( 'wp_defer_term_counting' ) ) {
			wp_defer_term_counting( true );
			$this->termCountingDeferred = true;
		}
	}

	/**
	 * Restore term counting after bulk imports.
	 *
	 * @return void
	 */
	private function restoreTermCounting(): void {
		if ( $this->termCountingDeferred && function_exists( 'wp_defer_term_counting' ) ) {
			wp_defer_term_counting( false );
			$this->termCountingDeferred = false;
		}
	}

	/**
	 * Report progress via the configured callback.
	 *
	 * @param string $step    Current step name.
	 * @param int    $current Current row count.
	 * @param int    $total   Total row count.
	 */
	private function reportProgress( string $step, int $current, int $total ): void {
		if ( is_callable( $this->progressCallback ) ) {
			call_user_func( $this->progressCallback, $step, $current, $total );
		}
	}
}
