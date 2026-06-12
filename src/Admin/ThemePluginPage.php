<?php
/**
 * Theme & Plugin translation admin page for NovaTools Polyglot.
 *
 * Provides Loco Translate–style file management for theme and plugin
 * translation files. Lists available bundles (themes/plugins), their
 * PO/MO/POT files, and links to the PO editor for inline editing.
 *
 * @package NovaTools\Polyglot\Admin
 */

namespace NovaTools\Polyglot\Admin;

use NovaTools\Polyglot\Core\Plugin;
use NovaTools\Polyglot\FileTranslation\FileDiscoveryService;

defined( 'ABSPATH' ) || exit;

class ThemePluginPage {

	/**
	 * Plugin instance for service resolution.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Core plugin singleton.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Render the theme & plugin translation page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'novatools-polyglot' ) );
		}

		$view = sanitize_text_field( wp_unslash( $_GET['view'] ?? 'list' ) );

		$this->outputHeader();

		switch ( $view ) {
			case 'editor':
				$this->outputPoEditor();
				break;
			case 'bundle':
				$this->outputBundleDetail();
				break;
			default:
				$this->outputBundleList();
				break;
		}

		$this->outputFooter();
	}

	/**
	 * Output the page header.
	 *
	 * @return void
	 */
	private function outputHeader(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Theme & Plugin Translations', 'novatools-polyglot' ) . '</h1>';
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
	 * Output the list of all available translation bundles.
	 *
	 * Groups bundles by kind (Theme / Plugin) and shows file counts
	 * and translation status per language.
	 *
	 * @return void
	 */
	private function outputBundleList(): void {
		$bundles = $this->getBundles();

		if ( empty( $bundles ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No translation files found.', 'novatools-polyglot' ) . '</p></div>';
			return;
		}

		// Group by kind.
		$grouped = array();
		foreach ( $bundles as $bundle ) {
			$kind = $bundle['kind'] ?? 'unknown';
			$grouped[ $kind ][] = $bundle;
		}

		foreach ( $grouped as $kind => $items ) {
			$kind_label = 'theme' === $kind
				? __( 'Themes', 'novatools-polyglot' )
				: __( 'Plugins', 'novatools-polyglot' );

			echo '<h2>' . esc_html( $kind_label ) . '</h2>';
			echo '<table class="wp-list-table widefat fixed striped">';
			echo '<thead>';
			echo '<tr>';
			echo '<th>' . esc_html__( 'Name', 'novatools-polyglot' ) . '</th>';
			echo '<th>' . esc_html__( 'Text Domain', 'novatools-polyglot' ) . '</th>';
			echo '<th>' . esc_html__( 'Languages', 'novatools-polyglot' ) . '</th>';
			echo '<th>' . esc_html__( 'Files', 'novatools-polyglot' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'novatools-polyglot' ) . '</th>';
			echo '</tr>';
			echo '</thead>';
			echo '<tbody>';

			foreach ( $items as $bundle ) {
				$name       = $bundle['title'] ?? $bundle['name'];
				$domain     = $bundle['name'] ?? '';
				$lang_count = count( $bundle['languages'] ?? array() );
				$file_count = $bundle['file_count'] ?? 0;
				$slug       = $bundle['kind_slug'] ?? '';
				$page       = sanitize_text_field( wp_unslash( $_GET['page'] ?? 'novatools-polyglot-files' ) );

				echo '<tr>';
				echo '<td><strong>' . esc_html( $name ) . '</strong></td>';
				echo '<td><code>' . esc_html( $domain ) . '</code></td>';
				echo '<td>' . esc_html( $lang_count ) . '</td>';
				echo '<td>' . esc_html( $file_count ) . '</td>';
				echo '<td>';
				printf(
					'<a href="%s" class="button button-small">%s</a>',
					esc_url( add_query_arg(
						array( 'view' => 'bundle', 'kind' => $kind, 'slug' => $slug ),
						admin_url( "admin.php?page={$page}" )
					) ),
					esc_html__( 'Manage', 'novatools-polyglot' )
				);
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody>';
			echo '</table>';
		}
	}

	/**
	 * Output detail view for a single bundle showing per-language files.
	 *
	 * @return void
	 */
	private function outputBundleDetail(): void {
		$kind = sanitize_text_field( wp_unslash( $_GET['kind'] ?? '' ) );
		$slug = sanitize_text_field( wp_unslash( $_GET['slug'] ?? '' ) );
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? 'novatools-polyglot-files' ) );

		$bundle = $this->findBundle( $kind, $slug );

		if ( ! $bundle ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Bundle not found.', 'novatools-polyglot' ) . '</p></div>';
			return;
		}

		$name = $bundle['title'] ?? $bundle['name'];

		// Breadcrumb.
		printf(
			'<p><a href="%s">%s</a> &raquo; %s</p>',
			esc_url( admin_url( "admin.php?page={$page}" ) ),
			esc_html__( 'All bundles', 'novatools-polyglot' ),
			esc_html( $name )
		);

		echo '<h2>' . esc_html( $name ) . '</h2>';
		echo '<p class="description">' . esc_html( $bundle['description'] ?? '' ) . '</p>';

		$files = $bundle['files'] ?? array();

		if ( empty( $files ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No translation files found for this bundle.', 'novatools-polyglot' ) . '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>' . esc_html__( 'File', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Language', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Last Modified', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Size', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'novatools-polyglot' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		foreach ( $files as $file ) {
			$path     = $file['path'] ?? '';
			$basename = basename( $path );
			$lang     = $file['language'] ?? '—';
			$type     = strtoupper( $file['type'] ?? '' );
			$modified = $file['modified'] ? date_i18n( get_option( 'date_format' ), $file['modified'] ) : '—';
			$size     = isset( $file['size'] ) ? size_format( $file['size'] ) : '—';

			echo '<tr>';
			echo '<td><code>' . esc_html( $basename ) . '</code></td>';
			echo '<td>' . esc_html( $lang ) . '</td>';
			echo '<td>' . esc_html( $type ) . '</td>';
			echo '<td>' . esc_html( $modified ) . '</td>';
			echo '<td>' . esc_html( $size ) . '</td>';
			echo '<td>';

			if ( 'po' === ( $file['type'] ?? '' ) && is_writable( $path ) ) {
				printf(
					'<a href="%s" class="button button-small">%s</a>',
					esc_url( add_query_arg(
						array(
							'view'    => 'editor',
							'file'    => $path,
							'domain'  => $bundle['name'] ?? '',
							'locale'  => $file['locale'] ?? '',
							'kind'    => $kind,
							'slug'    => $slug,
						),
						admin_url( "admin.php?page={$page}" )
					) ),
					esc_html__( 'Edit', 'novatools-polyglot' )
				);
			} elseif ( 'pot' === ( $file['type'] ?? '' ) ) {
				echo '<span class="description">' . esc_html__( 'Template', 'novatools-polyglot' ) . '</span>';
			} else {
				echo '<span class="description">' . esc_html__( 'Binary', 'novatools-polyglot' ) . '</span>';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * Output the PO file editor view.
	 *
	 * Loads a PO file via the PoEditorController AJAX endpoint and renders
	 * an inline editing interface. Falls back to a server-side rendered
	 * textarea editor when JavaScript is unavailable.
	 *
	 * @return void
	 */
	private function outputPoEditor(): void {
		$file_path   = sanitize_text_field( wp_unslash( $_GET['file'] ?? '' ) );
		$domain      = sanitize_text_field( wp_unslash( $_GET['domain'] ?? '' ) );
		$locale      = sanitize_text_field( wp_unslash( $_GET['locale'] ?? '' ) );
		$kind        = sanitize_text_field( wp_unslash( $_GET['kind'] ?? '' ) );
		$slug        = sanitize_text_field( wp_unslash( $_GET['slug'] ?? '' ) );
		$page        = sanitize_text_field( wp_unslash( $_GET['page'] ?? 'novatools-polyglot-files' ) );

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'File not found.', 'novatools-polyglot' ) . '</p></div>';
			return;
		}

		// Breadcrumb.
		printf(
			'<p><a href="%s">%s</a> &raquo; <a href="%s">%s</a> &raquo; %s</p>',
			esc_url( admin_url( "admin.php?page={$page}" ) ),
			esc_html__( 'All bundles', 'novatools-polyglot' ),
			esc_url( add_query_arg(
				array( 'view' => 'bundle', 'kind' => $kind, 'slug' => $slug ),
				admin_url( "admin.php?page={$page}" )
			) ),
			esc_html( $slug ),
			esc_html( basename( $file_path ) )
		);

		echo '<h2>' . esc_html__( 'Edit Translations', 'novatools-polyglot' ) . '</h2>';
		printf(
			'<p class="description">%s: <code>%s</code> | %s: <code>%s</code></p>',
			esc_html__( 'File', 'novatools-polyglot' ),
			esc_html( basename( $file_path ) ),
			esc_html__( 'Domain', 'novatools-polyglot' ),
			esc_html( $domain )
		);

		// Parse the PO file for server-side rendering.
		$entries = $this->parsePoFile( $file_path );

		$ajax_url     = admin_url( 'admin-ajax.php' );
		$po_nonce     = wp_create_nonce( 'polyglot_po_editor' );
		$compile_text = esc_js( __( 'Compile MO/PHP/JSON', 'novatools-polyglot' ) );
		$saving_text  = esc_js( __( 'Saving…', 'novatools-polyglot' ) );
		$saved_text   = esc_js( __( 'Saved!', 'novatools-polyglot' ) );
		$error_text   = esc_js( __( 'Error saving file.', 'novatools-polyglot' ) );

		// Hidden fields for the JS editor.
		echo '<div id="polyglot-po-editor" data-file="' . esc_attr( $file_path ) . '" data-domain="' . esc_attr( $domain ) . '" data-locale="' . esc_attr( $locale ) . '">';
		echo '</div>';

		// Server-side fallback editor form.
		echo '<form method="post" class="polyglot-po-fallback-editor">';
		echo '<input type="hidden" name="polyglot_action" value="save_po_file" />';
		echo '<input type="hidden" name="file" value="' . esc_attr( $file_path ) . '" />';
		echo '<input type="hidden" name="domain" value="' . esc_attr( $domain ) . '" />';
		echo '<input type="hidden" name="locale" value="' . esc_attr( $locale ) . '" />';
		wp_nonce_field( 'polyglot_save_po_' . md5( $file_path ) );

		echo '<table class="wp-list-table widefat fixed striped polyglot-po-table">';
		echo '<thead>';
		echo '<tr>';
		echo '<th style="width:35%;">' . esc_html__( 'Original', 'novatools-polyglot' ) . '</th>';
		echo '<th style="width:50%;">' . esc_html__( 'Translation', 'novatools-polyglot' ) . '</th>';
		echo '<th style="width:15%;">' . esc_html__( 'Status', 'novatools-polyglot' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		if ( ! empty( $entries ) ) {
			foreach ( $entries as $i => $entry ) {
				$msgid  = $entry['msgid'] ?? '';
				$msgstr = $entry['msgstr'][0] ?? '';
				$has_translation = '' !== $msgstr;

				echo '<tr>';
				echo '<td><span title="' . esc_attr( $msgid ) . '">' . esc_html( wp_trim_words( $msgid, 20, '…' ) ) . '</span>';
				if ( ! empty( $entry['msgctxt'] ) ) {
					echo '<br><small style="color:#646970;">' . esc_html( $entry['msgctxt'] ) . '</small>';
				}
				echo '</td>';
				printf(
					'<td><textarea name="entries[%d][msgstr]" rows="2" class="large-text polyglot-po-input">%s</textarea></td>',
					$i,
					esc_textarea( $msgstr )
				);
				echo '<td>';
				if ( $has_translation ) {
					echo '<span style="color:#00a32a;">' . esc_html__( 'Translated', 'novatools-polyglot' ) . '</span>';
				} else {
					echo '<span style="color:#d63638;">' . esc_html__( 'Untranslated', 'novatools-polyglot' ) . '</span>';
				}
				echo '</td>';
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="3">' . esc_html__( 'No entries found in this file.', 'novatools-polyglot' ) . '</td></tr>';
		}

		echo '</tbody>';
		echo '</table>';

		echo '<p class="submit">';
		submit_button( __( 'Save PO File', 'novatools-polyglot' ), 'primary', 'polyglot_save_po', false );
		echo ' <button type="button" id="polyglot-compile-btn" class="button button-secondary">' . esc_html__( 'Compile MO/PHP/JSON', 'novatools-polyglot' ) . '</button>';
		echo '</p>';
		echo '</form>';

		// Inline JS for AJAX save/compile using PoEditorController.
		?>
		<script type="text/javascript">
		jQuery( document ).ready( function( $ ) {
			var nonce = <?php echo wp_json_encode( $po_nonce ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;

			// Save PO file via AJAX.
			$( '#polyglot_save_po' ).closest( 'form' ).on( 'submit', function( e ) {
				e.preventDefault();

				var entries = [];
				$( '.polyglot-po-input' ).each( function( i ) {
					var row = $( this ).closest( 'tr' );
					entries.push( {
						msgid: row.find( 'td:first span' ).attr( 'title' ),
						msgstr: [ $( this ).val() ]
					} );
				} );

				$( '#polyglot_save_po' ).prop( 'disabled', true ).val( '<?php echo $saving_text; ?>' );

				$.post( ajaxUrl, {
					action: 'polyglot_po_save',
					nonce: nonce,
					file: '<?php echo esc_js( $file_path ); ?>',
					domain: '<?php echo esc_js( $domain ); ?>',
					locale: '<?php echo esc_js( $locale ); ?>',
					entries: JSON.stringify( entries ),
					compile: true
				}, function( response ) {
					if ( response.success ) {
						$( '#polyglot_save_po' ).val( '<?php echo $saved_text; ?>' );
						setTimeout( function() {
							$( '#polyglot_save_po' ).prop( 'disabled', false ).val( 'Save PO File' );
						}, 2000 );
					} else {
						alert( response.data.message || '<?php echo $error_text; ?>' );
						$( '#polyglot_save_po' ).prop( 'disabled', false ).val( 'Save PO File' );
					}
				} );
			} );

			// Compile only.
			$( '#polyglot-compile-btn' ).on( 'click', function() {
				var btn = $( this );
				btn.prop( 'disabled', true ).text( '<?php echo esc_js( __( 'Compiling…', 'novatools-polyglot' ) ); ?>' );

				$.post( ajaxUrl, {
					action: 'polyglot_po_compile',
					nonce: nonce,
					file: '<?php echo esc_js( $file_path ); ?>',
					domain: '<?php echo esc_js( $domain ); ?>',
					locale: '<?php echo esc_js( $locale ); ?>'
				}, function( response ) {
					if ( response.success ) {
						btn.text( '<?php echo esc_js( __( 'Compiled!', 'novatools-polyglot' ) ); ?>' );
						setTimeout( function() {
							btn.prop( 'disabled', false ).text( '<?php echo $compile_text; ?>' );
						}, 2000 );
					} else {
						alert( response.data.message || '<?php echo $error_text; ?>' );
						btn.prop( 'disabled', false ).text( '<?php echo $compile_text; ?>' );
					}
				} );
			} );
		} );
		</script>
		<?php
	}

	// ─── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Get all available translation bundles.
	 *
	 * Maps Bundle value objects to arrays expected by the rendering methods,
	 * using FileDiscoveryService to build per-bundle file listings.
	 *
	 * @return array[]
	 */
	private function getBundles(): array {
		try {
			if ( ! $this->plugin->has( 'bundle.repository' ) ) {
				return array();
			}

			$bundle_repo = $this->plugin->get( 'bundle.repository' );
			$all         = $bundle_repo->getAll();

			// Get the file discovery service for per-bundle file listings.
			$discovery = $this->plugin->has( 'file.discovery' )
				? $this->plugin->get( 'file.discovery' )
				: null;

			$result = array();
			foreach ( $all as $bundle ) {
				// Derive kind from Bundle type constant.
				$kind = $bundle->type;

				// Derive kind_slug from the bundle path (directory basename).
				$kind_slug = '' !== $bundle->path ? basename( $bundle->path ) : $bundle->domain;

				// Build file listings from FileDiscoveryService.
				$files = array();
				if ( $discovery instanceof FileDiscoveryService ) {
					$discovered  = $discovery->findFiles( $bundle->domain, $bundle->path );
					$files       = $this->buildFileEntries( $discovered, $bundle->domain );
				}

				$result[] = array(
					'kind'        => $kind,
					'kind_slug'   => $kind_slug,
					'name'        => $bundle->domain,
					'title'       => $bundle->name,
					'description' => $bundle->isPlugin()
						? sprintf( 'Plugin — v%s', $bundle->version )
						: ( $bundle->isTheme()
							? sprintf( 'Theme — v%s', $bundle->version )
							: 'WordPress Core' ),
					'languages'   => $bundle->locales,
					'files'       => $files,
					'file_count'  => count( $files ),
				);
			}
			return $result;
		} catch ( \Throwable ) {
			// Fall through.
		}
		return array();
	}

	/**
	 * Build file entry arrays from FileDiscoveryService results.
	 *
	 * @param array  $discovered Discovered files from FileDiscoveryService::findFiles().
	 * @param string $domain     Text domain.
	 * @return array[] Flat list of file entry arrays.
	 */
	private function buildFileEntries( array $discovered, string $domain ): array {
		$files = array();

		// POT files.
		foreach ( $discovered['pot'] ?? array() as $path ) {
			$files[] = $this->makeFileEntry( $path, '', 'pot', $domain );
		}

		// PO files.
		foreach ( $discovered['po'] ?? array() as $locale => $po_files ) {
			foreach ( $po_files as $path ) {
				$files[] = $this->makeFileEntry( $path, $locale, 'po', $domain );
			}
		}

		// MO files.
		foreach ( $discovered['mo'] ?? array() as $locale => $mo_files ) {
			foreach ( $mo_files as $path ) {
				$files[] = $this->makeFileEntry( $path, $locale, 'mo', $domain );
			}
		}

		// PHP files (l10n.php).
		foreach ( $discovered['php'] ?? array() as $locale => $php_files ) {
			foreach ( $php_files as $path ) {
				$files[] = $this->makeFileEntry( $path, $locale, 'php', $domain );
			}
		}

		// JSON files.
		foreach ( $discovered['json'] ?? array() as $path ) {
			$files[] = $this->makeFileEntry( $path, '', 'json', $domain );
		}

		return $files;
	}

	/**
	 * Create a single file entry array for rendering.
	 *
	 * @param string $path   Absolute file path.
	 * @param string $locale Locale code (empty for POT/JSON).
	 * @param string $type   File type (po, mo, pot, php, json).
	 * @param string $domain Text domain.
	 * @return array
	 */
	private function makeFileEntry( string $path, string $locale, string $type, string $domain ): array {
		return array(
			'path'     => $path,
			'language' => '' !== $locale ? $this->localeToLanguageName( $locale ) : '—',
			'locale'   => $locale,
			'type'     => $type,
			'domain'   => $domain,
			'modified' => file_exists( $path ) ? filemtime( $path ) : null,
			'size'     => file_exists( $path ) ? filesize( $path ) : null,
		);
	}

	/**
	 * Get a human-readable language name from a locale code.
	 *
	 * @param string $locale WordPress locale (e.g. "fr_FR").
	 * @return string
	 */
	private function localeToLanguageName( string $locale ): string {
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';

		$translations = wp_get_available_translations();
		if ( isset( $translations[ $locale ] ) ) {
			return $translations[ $locale ]['native_name'] ?? $locale;
		}

		// Fallback for English (not in available translations).
		if ( 'en_US' === $locale ) {
			return 'English';
		}

		return $locale;
	}

	/**
	 * Find a specific bundle by kind and slug.
	 *
	 * @param string $kind Bundle kind (theme/plugin).
	 * @param string $slug Bundle kind slug.
	 * @return array|null
	 */
	private function findBundle( string $kind, string $slug ): ?array {
		$bundles = $this->getBundles();

		foreach ( $bundles as $bundle ) {
			if ( ( $bundle['kind'] ?? '' ) === $kind && ( $bundle['kind_slug'] ?? '' ) === $slug ) {
				return $bundle;
			}
		}

		return null;
	}

	/**
	 * Parse a PO file using the PoFileParser service.
	 *
	 * @param string $path Absolute path to the PO file.
	 * @return array PO entries array.
	 */
	private function parsePoFile( string $path ): array {
		try {
			if ( $this->plugin->has( 'po.parser' ) && file_exists( $path ) ) {
				$data = $this->plugin->get( 'po.parser' )->parse( $path );
				return $data['entries'] ?? array();
			}
		} catch ( \Throwable ) {
			// Fall through.
		}
		return array();
	}
}
