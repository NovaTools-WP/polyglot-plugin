<?php
/**
 * WPML import wizard admin page for NovaTools Polyglot.
 *
 * Provides a step-by-step import wizard for migrating data from existing
 * WPML tables. Uses AJAX for progress reporting during the import process.
 *
 * Steps:
 *   1. Detect WPML installation and show overview.
 *   2. Select what to import (languages, translations, strings, settings, WooCommerce).
 *   3. Dry-run preview showing what will be imported.
 *   4. Execute import with AJAX progress.
 *   5. Verification results and cleanup suggestions.
 *
 * @package NovaTools\Polyglot\Admin
 */

namespace NovaTools\Polyglot\Admin;

use NovaTools\Polyglot\Core\Plugin;
use NovaTools\Polyglot\Database\Schema;

defined( 'ABSPATH' ) || exit;

class ImportWpmlPage {

	/**
	 * Plugin instance for service resolution.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Current step in the wizard (1-5).
	 *
	 * @var int
	 */
	private int $step;

	/**
	 * WPML tables detected in the database.
	 *
	 * Lazy-loaded on first access to avoid running COUNT(*) queries in the
	 * constructor before the capability check.
	 *
	 * @var array|null
	 */
	private ?array $detectedTables = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Core plugin singleton.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->step   = max( 1, min( 5, (int) ( $_GET['step'] ?? 1 ) ) );
	}

	/**
	 * Register AJAX handlers for the WPML import wizard.
	 *
	 * Each handler delegates to the MigrateFromWpml service.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_polyglot_wpml_import_languages', array( $this, 'ajaxImportLanguages' ) );
		add_action( 'wp_ajax_polyglot_wpml_import_translations', array( $this, 'ajaxImportTranslations' ) );
		add_action( 'wp_ajax_polyglot_wpml_import_strings', array( $this, 'ajaxImportStrings' ) );
		add_action( 'wp_ajax_polyglot_wpml_import_settings', array( $this, 'ajaxImportSettings' ) );
		add_action( 'wp_ajax_polyglot_wpml_import_woocommerce', array( $this, 'ajaxImportWooCommerce' ) );
	}

	/**
	 * Lazy-load detected WPML tables on first access.
	 *
	 * @return array
	 */
	private function getDetectedTables(): array {
		if ( null === $this->detectedTables ) {
			$this->detectedTables = $this->detectWpmlTables();
		}
		return $this->detectedTables;
	}

	/**
	 * Render the import wizard page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'novatools-polyglot' ) );
		}

		$this->outputHeader();
		$this->outputSteps();

		switch ( $this->step ) {
			case 1:
				$this->outputStep1Detect();
				break;
			case 2:
				$this->outputStep2Select();
				break;
			case 3:
				$this->outputStep3DryRun();
				break;
			case 4:
				$this->outputStep4Import();
				break;
			case 5:
				$this->outputStep5Complete();
				break;
		}

		$this->outputFooter();
	}

	// ─── Step indicators ──────────────────────────────────────────────────

	/**
	 * Output the step indicator bar.
	 *
	 * @return void
	 */
	private function outputSteps(): void {
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? 'novatools-polyglot-import-wpml' ) );

		$steps = array(
			1 => __( 'Detect WPML', 'novatools-polyglot' ),
			2 => __( 'Select Data', 'novatools-polyglot' ),
			3 => __( 'Preview', 'novatools-polyglot' ),
			4 => __( 'Import', 'novatools-polyglot' ),
			5 => __( 'Complete', 'novatools-polyglot' ),
		);

		echo '<div style="display:flex;gap:0;margin:20px 0;border:1px solid #ccd0d4;border-radius:4px;overflow:hidden;">';

		foreach ( $steps as $num => $label ) {
			$is_active  = $num === $this->step;
			$is_done    = $num < $this->step;
			$bg         = $is_active ? '#2271b1' : ( $is_done ? '#00a32a' : '#f0f0f1' );
			$color      = ( $is_active || $is_done ) ? '#fff' : '#2c3338';

			printf(
				'<div style="flex:1;padding:10px 12px;background:%s;color:%s;text-align:center;font-weight:%s;font-size:13px;">',
				esc_attr( $bg ),
				esc_attr( $color ),
				$is_active ? '700' : '400'
			);

			printf( '<span style="display:block;font-size:16px;margin-bottom:2px;">%d</span>%s', $num, esc_html( $label ) );

			echo '</div>';
		}

		echo '</div>';
	}

	// ─── Step 1: Detect WPML ──────────────────────────────────────────────

	/**
	 * Output Step 1: Detect WPML tables.
	 *
	 * @return void
	 */
	private function outputStep1Detect(): void {
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? 'novatools-polyglot-import-wpml' ) );

		if ( empty( $this->getDetectedTables() ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'No WPML tables found in the database. Make sure WPML is (or was) installed before importing.', 'novatools-polyglot' ) . '</p></div>';
			return;
		}

		echo '<div class="notice notice-success"><p>' . esc_html__( 'WPML data detected! The following tables were found:', 'novatools-polyglot' ) . '</p></div>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Table', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Rows', 'novatools-polyglot' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $this->getDetectedTables() as $table => $count ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $table ) . '</code></td>';
			echo '<td>' . number_format_i18n( (int) $count ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		// WPML plugins active check.
		$wpml_active = defined( 'ICL_SITEPRESS_VERSION' );
		if ( $wpml_active ) {
			echo '<div class="notice notice-warning" style="margin-top:16px;"><p>' . esc_html__( 'WPML is currently active. You can keep it running during import — WPML tables are read-only during the process.', 'novatools-polyglot' ) . '</p></div>';
		}

		printf(
			'<p style="margin-top:16px;"><a href="%s" class="button button-primary button-large">%s</a></p>',
			esc_url( add_query_arg( array( 'page' => $page, 'step' => 2 ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'Continue to Data Selection', 'novatools-polyglot' )
		);
	}

	// ─── Step 2: Select Data ──────────────────────────────────────────────

	/**
	 * Output Step 2: Select what to import.
	 *
	 * @return void
	 */
	private function outputStep2Select(): void {
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? 'novatools-polyglot-import-wpml' ) );

		if ( empty( $this->getDetectedTables() ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'No WPML tables detected. Please go back to step 1.', 'novatools-polyglot' )
			);
			return;
		}

		echo '<p class="description">' . esc_html__( 'Choose which data to import from WPML. Unchecked items will be skipped.', 'novatools-polyglot' ) . '</p>';

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $page ) . '" />';
		echo '<input type="hidden" name="step" value="3" />';

		echo '<table class="form-table">';

		// Languages.
		$has_languages = isset( $this->getDetectedTables()['icl_languages'] );
		echo '<tr>';
		echo '<th scope="row"><label><input type="checkbox" name="import_languages" value="1" ' . checked( $has_languages, true, false ) . ' ' . disabled( ! $has_languages, true, false ) . ' /> ' . esc_html__( 'Languages', 'novatools-polyglot' ) . '</label></th>';
		echo '<td>' . esc_html__( 'Import language definitions, flags, and locale mappings from icl_languages / icl_languages_translations.', 'novatools-polyglot' ) . '</td>';
		echo '</tr>';

		// Translations.
		$has_translations = isset( $this->getDetectedTables()['icl_translations'] );
		echo '<tr>';
		echo '<th scope="row"><label><input type="checkbox" name="import_translations" value="1" ' . checked( $has_translations, true, false ) . ' ' . disabled( ! $has_translations, true, false ) . ' /> ' . esc_html__( 'Content Translations', 'novatools-polyglot' ) . '</label></th>';
		echo '<td>' . esc_html__( 'Import post, page, taxonomy term, and custom post type translation relationships from icl_translations.', 'novatools-polyglot' ) . '</td>';
		echo '</tr>';

		// Strings.
		$has_strings = isset( $this->getDetectedTables()['icl_strings'] ) && isset( $this->getDetectedTables()['icl_string_translations'] );
		echo '<tr>';
		echo '<th scope="row"><label><input type="checkbox" name="import_strings" value="1" ' . checked( $has_strings, true, false ) . ' ' . disabled( ! $has_strings, true, false ) . ' /> ' . esc_html__( 'String Translations', 'novatools-polyglot' ) . '</label></th>';
		echo '<td>' . esc_html__( 'Import registered strings and their translations from icl_strings / icl_string_translations.', 'novatools-polyglot' ) . '</td>';
		echo '</tr>';

		// Settings.
		echo '<tr>';
		echo '<th scope="row"><label><input type="checkbox" name="import_settings" value="1" checked="checked" /> ' . esc_html__( 'WPML Settings', 'novatools-polyglot' ) . '</label></th>';
		echo '<td>' . esc_html__( 'Map WPML settings (default language, URL format, etc.) to Polyglot equivalents.', 'novatools-polyglot' ) . '</td>';
		echo '</tr>';

		// WooCommerce.
		$has_wc = isset( $this->getDetectedTables()['icl_translations'] ) && class_exists( 'WooCommerce' );
		if ( $has_wc ) {
			echo '<tr>';
			echo '<th scope="row"><label><input type="checkbox" name="import_woocommerce" value="1" checked="checked" /> ' . esc_html__( 'WooCommerce Data', 'novatools-polyglot' ) . '</label></th>';
			echo '<td>' . esc_html__( 'Import WooCommerce product translations, multi-currency settings, and exchange rates.', 'novatools-polyglot' ) . '</td>';
			echo '</tr>';
		}

		echo '</table>';

		echo '<p>';
		printf(
			'<a href="%s" class="button">%s</a> ',
			esc_url( add_query_arg( array( 'page' => $page, 'step' => 1 ), admin_url( 'admin.php' ) ) ),
			esc_html__( '&larr; Back', 'novatools-polyglot' )
		);
		submit_button( __( 'Preview Import', 'novatools-polyglot' ), 'primary', 'submit', false );
		echo '</p>';
		echo '</form>';
	}

	// ─── Step 3: Dry-Run Preview ──────────────────────────────────────────

	/**
	 * Output Step 3: Dry-run preview.
	 *
	 * Shows what will be imported based on the selections from Step 2.
	 *
	 * @return void
	 */
	private function outputStep3DryRun(): void {
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? 'novatools-polyglot-import-wpml' ) );

		$import_languages   = ! empty( $_GET['import_languages'] );
		$import_translations = ! empty( $_GET['import_translations'] );
		$import_strings     = ! empty( $_GET['import_strings'] );
		$import_settings    = ! empty( $_GET['import_settings'] );
		$import_woocommerce = ! empty( $_GET['import_woocommerce'] );

		$report = $this->generateDryRunReport( array(
			'languages'   => $import_languages,
			'translations' => $import_translations,
			'strings'     => $import_strings,
			'settings'    => $import_settings,
			'woocommerce' => $import_woocommerce,
		) );

		echo '<h2>' . esc_html__( 'Import Preview (Dry Run)', 'novatools-polyglot' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'This is a preview. No data has been modified yet.', 'novatools-polyglot' ) . '</p>';

		echo '<table class="wp-list-table widefat fixed striped" style="margin-top:16px;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Data Type', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'WPML Source', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Polyglot Target', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Estimated Rows', 'novatools-polyglot' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $report as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( $item['label'] ) . '</td>';
			echo '<td><code>' . esc_html( $item['source'] ) . '</code></td>';
			echo '<td><code>' . esc_html( $item['target'] ) . '</code></td>';
			echo '<td>' . number_format_i18n( (int) $item['rows'] ) . '</td>';
			echo '</tr>';
		}

		if ( empty( $report ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Nothing selected for import.', 'novatools-polyglot' ) . '</td></tr>';
		}

		echo '</tbody></table>';

		$total_rows = array_sum( array_column( $report, 'rows' ) );
		printf(
			'<p style="margin-top:12px;"><strong>%s:</strong> %s</p>',
			esc_html__( 'Total rows to import', 'novatools-polyglot' ),
			number_format_i18n( $total_rows )
		);

		if ( $total_rows > 0 ) {
			echo '<div class="notice notice-info" style="margin-top:16px;"><p>' . esc_html__( 'WPML tables are read-only during import. Your existing WPML data will not be modified.', 'novatools-polyglot' ) . '</p></div>';

			// Pass selections as hidden fields to step 4.
			echo '<form method="get" style="margin-top:16px;">';
			echo '<input type="hidden" name="page" value="' . esc_attr( $page ) . '" />';
			echo '<input type="hidden" name="step" value="4" />';

			if ( $import_languages ) {
				echo '<input type="hidden" name="import_languages" value="1" />';
			}
			if ( $import_translations ) {
				echo '<input type="hidden" name="import_translations" value="1" />';
			}
			if ( $import_strings ) {
				echo '<input type="hidden" name="import_strings" value="1" />';
			}
			if ( $import_settings ) {
				echo '<input type="hidden" name="import_settings" value="1" />';
			}
			if ( $import_woocommerce ) {
				echo '<input type="hidden" name="import_woocommerce" value="1" />';
			}

			echo '<p>';
			printf(
				'<a href="%s" class="button">%s</a> ',
				esc_url( add_query_arg( array( 'page' => $page, 'step' => 2 ), admin_url( 'admin.php' ) ) ),
				esc_html__( '&larr; Back', 'novatools-polyglot' )
			);
			submit_button( __( 'Start Import', 'novatools-polyglot' ), 'primary button-large', 'submit', false );
			echo '</p>';
			echo '</form>';
		}
	}

	// ─── Step 4: Execute Import ───────────────────────────────────────────

	/**
	 * Output Step 4: Execute import with AJAX progress.
	 *
	 * @return void
	 */
	private function outputStep4Import(): void {
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? 'novatools-polyglot-import-wpml' ) );

		$import_languages    = ! empty( $_GET['import_languages'] ) ? 1 : 0;
		$import_translations = ! empty( $_GET['import_translations'] ) ? 1 : 0;
		$import_strings      = ! empty( $_GET['import_strings'] ) ? 1 : 0;
		$import_settings     = ! empty( $_GET['import_settings'] ) ? 1 : 0;
		$import_woocommerce  = ! empty( $_GET['import_woocommerce'] ) ? 1 : 0;

		$nonce = wp_create_nonce( 'polyglot_wpml_import' );
		$ajax_url = admin_url( 'admin-ajax.php' );

		echo '<h2>' . esc_html__( 'Importing…', 'novatools-polyglot' ) . '</h2>';

		echo '<div id="polyglot-import-progress" style="margin:20px 0;">';
		echo '<div style="background:#dcdcde;border-radius:3px;height:24px;overflow:hidden;">';
		echo '<div id="polyglot-import-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.3s;"></div>';
		echo '</div>';
		echo '<p id="polyglot-import-status" style="margin-top:8px;color:#2c3338;">' . esc_html__( 'Preparing import…', 'novatools-polyglot' ) . '</p>';
		echo '</div>';

		echo '<div id="polyglot-import-log" style="background:#f0f0f1;border:1px solid #ccd0d4;border-radius:4px;padding:12px;max-height:300px;overflow-y:auto;font-family:monospace;font-size:12px;margin-bottom:16px;">';
		echo '</div>';

		printf(
			'<a id="polyglot-import-next" href="%s" class="button button-primary button-large" style="display:none;">%s</a>',
			esc_url( add_query_arg( array( 'page' => $page, 'step' => 5 ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'View Results', 'novatools-polyglot' )
		);

		// Inline JS for AJAX-driven import.
		?>
		<script type="text/javascript">
		jQuery( document ).ready( function( $ ) {
			var nonce    = '<?php echo esc_js( $nonce ); ?>';
			var ajaxUrl  = '<?php echo esc_url( $ajax_url ); ?>';
			var phases   = [];
			var log      = $( '#polyglot-import-log' );
			var bar      = $( '#polyglot-import-bar' );
			var status   = $( '#polyglot-import-status' );

			<?php if ( $import_languages ) : ?>
			phases.push( { action: 'polyglot_wpml_import_languages', label: '<?php echo esc_js( __( 'Importing languages…', 'novatools-polyglot' ) ); ?>' } );
			<?php endif; ?>
			<?php if ( $import_translations ) : ?>
			phases.push( { action: 'polyglot_wpml_import_translations', label: '<?php echo esc_js( __( 'Importing content translations…', 'novatools-polyglot' ) ); ?>' } );
			<?php endif; ?>
			<?php if ( $import_strings ) : ?>
			phases.push( { action: 'polyglot_wpml_import_strings', label: '<?php echo esc_js( __( 'Importing string translations…', 'novatools-polyglot' ) ); ?>' } );
			<?php endif; ?>
			<?php if ( $import_settings ) : ?>
			phases.push( { action: 'polyglot_wpml_import_settings', label: '<?php echo esc_js( __( 'Importing settings…', 'novatools-polyglot' ) ); ?>' } );
			<?php endif; ?>
			<?php if ( $import_woocommerce ) : ?>
			phases.push( { action: 'polyglot_wpml_import_woocommerce', label: '<?php echo esc_js( __( 'Importing WooCommerce data…', 'novatools-polyglot' ) ); ?>' } );
			<?php endif; ?>

			var totalPhases = phases.length;
			var currentPhase = 0;

			function runPhase() {
				if ( currentPhase >= totalPhases ) {
					status.text( '<?php echo esc_js( __( 'Import complete!', 'novatools-polyglot' ) ); ?>' );
					bar.css( 'width', '100%' );
					log.append( '<div style="color:#00a32a;font-weight:bold;"><?php echo esc_js( __( 'All phases completed successfully.', 'novatools-polyglot' ) ); ?></div>' );
					$( '#polyglot-import-next' ).show();
					return;
				}

				var phase = phases[ currentPhase ];
				status.text( phase.label );
				var progress = Math.round( ( ( currentPhase ) / totalPhases ) * 100 );
				bar.css( 'width', progress + '%' );

				log.append( '<div>→ ' + phase.label + '</div>' );

				$.post( ajaxUrl, {
					action: phase.action,
					nonce: nonce
				}, function( response ) {
					if ( response.success ) {
						var data = response.data || {};
						log.append( '<div style="color:#00a32a;">  ✓ ' + ( data.message || '<?php echo esc_js( __( 'Done', 'novatools-polyglot' ) ); ?>' ) + ' (' + ( data.count || 0 ) + ' rows)</div>' );
					} else {
						log.append( '<div style="color:#d63638;">  ✗ ' + ( ( response.data && response.data.message ) || '<?php echo esc_js( __( 'Error', 'novatools-polyglot' ) ); ?>' ) + '</div>' );
					}
					currentPhase++;
					runPhase();
				} ).fail( function() {
					log.append( '<div style="color:#d63638;">  ✗ <?php echo esc_js( __( 'AJAX request failed.', 'novatools-polyglot' ) ); ?></div>' );
					currentPhase++;
					runPhase();
				} );
			}

			if ( totalPhases > 0 ) {
				runPhase();
			} else {
				status.text( '<?php echo esc_js( __( 'Nothing to import.', 'novatools-polyglot' ) ); ?>' );
				$( '#polyglot-import-next' ).show();
			}
		} );
		</script>
		<?php
	}

	// ─── Step 5: Complete ─────────────────────────────────────────────────

	/**
	 * Output Step 5: Import verification results.
	 *
	 * @return void
	 */
	private function outputStep5Complete(): void {
		$verification = $this->verifyImport();

		echo '<h2>' . esc_html__( 'Import Complete', 'novatools-polyglot' ) . '</h2>';

		if ( ! empty( $verification ) ) {
			echo '<table class="wp-list-table widefat fixed striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Table', 'novatools-polyglot' ) . '</th>';
			echo '<th>' . esc_html__( 'Rows', 'novatools-polyglot' ) . '</th>';
			echo '</tr></thead>';
			echo '<tbody>';

			foreach ( $verification as $table => $count ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( $table ) . '</code></td>';
				echo '<td>' . number_format_i18n( (int) $count ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<div class="notice notice-success" style="margin-top:16px;"><p>' . esc_html__( 'Import completed! You can now start using Polyglot for your multilingual content.', 'novatools-polyglot' ) . '</p></div>';

		echo '<div style="margin-top:16px;">';
		echo '<h3>' . esc_html__( 'Next Steps', 'novatools-polyglot' ) . '</h3>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'Verify your languages are correct on the Languages page.', 'novatools-polyglot' ) . '</li>';
		echo '<li>' . esc_html__( 'Check the Dashboard for translation status.', 'novatools-polyglot' ) . '</li>';
		echo '<li>' . esc_html__( 'Review URL settings in Settings.', 'novatools-polyglot' ) . '</li>';
		echo '<li>' . esc_html__( 'Once verified, you can safely deactivate WPML plugins.', 'novatools-polyglot' ) . '</li>';
		echo '</ol>';
		echo '</div>';
	}

	// ─── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Output the page header.
	 *
	 * @return void
	 */
	// ─── AJAX Handlers ─────────────────────────────────────────────────────

	/**
	 * AJAX handler: Import WPML languages.
	 *
	 * @return void
	 */
	public function ajaxImportLanguages(): void {
		$this->verifyImportAjax();
		$result = $this->runImportStep( 'importLanguages' );
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of imported languages */
				__( 'Imported %d languages.', 'novatools-polyglot' ),
				$result['imported']
			),
			'count' => $result['imported'],
		) );
	}

	/**
	 * AJAX handler: Import WPML content translations.
	 *
	 * @return void
	 */
	public function ajaxImportTranslations(): void {
		$this->verifyImportAjax();
		$result = $this->runImportStep( 'importTranslations' );
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of imported translations */
				__( 'Imported %d translations.', 'novatools-polyglot' ),
				$result['imported']
			),
			'count' => $result['imported'],
		) );
	}

	/**
	 * AJAX handler: Import WPML string translations.
	 *
	 * @return void
	 */
	public function ajaxImportStrings(): void {
		$this->verifyImportAjax();
		$result = $this->runImportStep( 'importStrings' );
		$total  = ( $result['imported'] ?? 0 ) + ( $result['imported_translations'] ?? 0 );
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: total imported count */
				__( 'Imported %d strings and translations.', 'novatools-polyglot' ),
				$total
			),
			'count' => $total,
		) );
	}

	/**
	 * AJAX handler: Import WPML settings.
	 *
	 * @return void
	 */
	public function ajaxImportSettings(): void {
		$this->verifyImportAjax();
		$result   = $this->runImportStep( 'importSettings' );
		$mappings = $result['mappings'] ?? array();
		$count    = count( $mappings );
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of settings mapped */
				__( 'Mapped %d settings.', 'novatools-polyglot' ),
				$count
			),
			'count' => $count,
		) );
	}

	/**
	 * AJAX handler: Import WooCommerce Multilingual data.
	 *
	 * @return void
	 */
	public function ajaxImportWooCommerce(): void {
		$this->verifyImportAjax();
		$result = $this->runImportStep( 'importWooCommerce' );
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of currencies found */
				__( 'Imported WooCommerce data (%d currencies).', 'novatools-polyglot' ),
				count( $result['currencies'] ?? array() )
			),
			'count' => count( $result['currencies'] ?? array() ),
		) );
	}

	/**
	 * Verify the AJAX request nonce and capability.
	 *
	 * Sends a JSON error response and terminates on failure.
	 *
	 * @return void
	 */
	private function verifyImportAjax(): void {
		check_ajax_referer( 'polyglot_wpml_import', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(
				'message' => __( 'Insufficient permissions.', 'novatools-polyglot' ),
			), 403 );
		}
	}

	/**
	 * Run a single import step via the MigrateFromWpml service.
	 *
	 * @param string $method Method name on MigrateFromWpml.
	 * @return array Import result from the migrator.
	 */
	private function runImportStep( string $method ): array {
		try {
			if ( $this->plugin->has( 'wpml.migrator' ) ) {
				$migrator = $this->plugin->get( 'wpml.migrator' );
				return $migrator->{$method}();
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( array(
				'message' => $e->getMessage(),
			) );
		}

		return array();
	}

	// ─── Rendering Helpers ────────────────────────────────────────────────

	private function outputHeader(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Import from WPML', 'novatools-polyglot' ) . '</h1>';
	}

	/**
	 * Output the page footer.
	 *
	 * @return void
	 */
	private function outputFooter(): void {
		echo '</div>';
	}

	/**
	 * Detect WPML tables in the database.
	 *
	 * @return array Associative array of table_name => row_count.
	 */
	private function detectWpmlTables(): array {
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

		foreach ( $wpml_tables as $table ) {
			$full_name = $wpdb->prefix . $table;

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_name}`" );

			if ( null !== $count ) {
				$found[ $table ] = (int) $count;
			}
		}

		return $found;
	}

	/**
	 * Generate a dry-run report for the selected import options.
	 *
	 * @param array $options Import selections.
	 * @return array Report items.
	 */
	private function generateDryRunReport( array $options ): array {
		$report = array();

		if ( $options['languages'] ) {
			$report[] = array(
				'label'  => __( 'Languages', 'novatools-polyglot' ),
				'source' => 'icl_languages + icl_languages_translations + icl_flags + icl_locale_map',
				'target' => 'polyglot_languages',
				'rows'   => $this->getDetectedTables()['icl_languages'] ?? 0,
			);
		}

		if ( $options['translations'] ) {
			$report[] = array(
				'label'  => __( 'Content Translations', 'novatools-polyglot' ),
				'source' => 'icl_translations + icl_translation_status',
				'target' => 'polyglot_translations',
				'rows'   => $this->getDetectedTables()['icl_translations'] ?? 0,
			);
		}

		if ( $options['strings'] ) {
			$report[] = array(
				'label'  => __( 'Strings', 'novatools-polyglot' ),
				'source' => 'icl_strings',
				'target' => 'polyglot_strings',
				'rows'   => $this->getDetectedTables()['icl_strings'] ?? 0,
			);

			$report[] = array(
				'label'  => __( 'String Translations', 'novatools-polyglot' ),
				'source' => 'icl_string_translations',
				'target' => 'polyglot_string_translations',
				'rows'   => $this->getDetectedTables()['icl_string_translations'] ?? 0,
			);
		}

		if ( $options['settings'] ) {
			$report[] = array(
				'label'  => __( 'Settings', 'novatools-polyglot' ),
				'source' => 'icl_sitepress_settings (option)',
				'target' => 'polyglot_settings (option)',
				'rows'   => 1,
			);
		}

		if ( $options['woocommerce'] ) {
			$report[] = array(
				'label'  => __( 'WooCommerce Data', 'novatools-polyglot' ),
				'source' => 'icl_translations (product types)',
				'target' => 'polyglot_translations + postmeta',
				'rows'   => $this->countWooCommerceRows(),
			);
		}

		return $report;
	}

	/**
	 * Count WooCommerce-related translation rows in WPML.
	 *
	 * @return int
	 */
	private function countWooCommerceRows(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'icl_translations';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$table}` WHERE element_type LIKE 'product%%'"
		);

		return (int) $count;
	}

	/**
	 * Verify the import by counting rows in Polyglot tables.
	 *
	 * @return array Table name => row count.
	 */
	private function verifyImport(): array {
		global $wpdb;

		$tables  = Schema::getTableNames();
		$results = array();

		foreach ( $tables as $short_name ) {
			$full_name = Schema::getTableName( $short_name );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_name}`" );

			$results[ $short_name ] = (int) $count;
		}

		return $results;
	}
}
