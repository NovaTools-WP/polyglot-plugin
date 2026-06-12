<?php
/**
 * Translation editor admin page for NovaTools Polyglot.
 *
 * Combines database string editing and PO file view with inline editing
 * and an auto-translate button. Supports two rendering modes:
 *   - 'translations': content translation overview (post/term groups).
 *   - 'strings':      database string translation editor.
 *
 * @package NovaTools\Polyglot\Admin
 */

namespace NovaTools\Polyglot\Admin;

use NovaTools\Polyglot\Core\Plugin;
use NovaTools\Polyglot\Database\Schema;

defined( 'ABSPATH' ) || exit;

class TranslationEditorPage {

	use AdminPageTrait;

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
	 * Render the translation editor page.
	 *
	 * @param string $mode Either 'translations' or 'strings'. Default 'translations'.
	 * @return void
	 */
	public function render( string $mode = 'translations' ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'novatools-polyglot' ) );
		}

		$this->outputHeader( $mode );

		if ( 'strings' === $mode ) {
			$this->handleStringSave();
			$this->outputStringEditor();
		} else {
			$this->outputContentTranslations();
		}

		$this->outputFooter();
	}

	// ─── String Translation Editor ────────────────────────────────────────

	/**
	 * Handle string translation save via POST.
	 *
	 * Processes inline edits submitted from the string translation form.
	 *
	 * @return void
	 */
	private function handleStringSave(): void {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ?? '' ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['polyglot_action'] ?? '' ) );

		if ( 'save_string_translations' !== $action ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'polyglot_save_strings' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'novatools-polyglot' ) );
		}

		if ( ! $this->plugin->has( 'string.repository' ) ) {
			return;
		}

		$repo    = $this->plugin->get( 'string.repository' );
		$strings = wp_unslash( $_POST['strings'] ?? array() );

		if ( ! is_array( $strings ) ) {
			return;
		}

		foreach ( $strings as $string_id => $translations ) {
			$string_id = (int) $string_id;

			if ( ! is_array( $translations ) ) {
				continue;
			}

			foreach ( $translations as $language => $value ) {
				$language = sanitize_text_field( $language );
				$value    = wp_kses_post( $value );

				$existing = $repo->getTranslation( $string_id, $language );

				$repo->saveTranslation( array(
					'string_id'    => $string_id,
					'language'     => $language,
					'value'        => $value,
					'status'       => '' !== $value ? 1 : 0,
					'translator_id' => get_current_user_id(),
				) );
			}
		}

		/**
		 * Fires after string translations have been saved from the admin editor.
		 *
		 * @param array $strings The string IDs and translations that were saved.
		 */
		do_action( 'polyglot_string_translations_saved', $strings );
	}

	/**
	 * Output the database string translation editor.
	 *
	 * Shows a filterable, paginated table of registered strings with inline
	 * editing fields per language and an auto-translate button.
	 *
	 * @return void
	 */
	private function outputStringEditor(): void {
		$active_languages = $this->getActiveLanguages();
		$default_lang     = $this->getDefaultLanguage();
		$default_code     = $default_lang ? $default_lang->code : '';

		// Filter parameters.
		$current_domain   = sanitize_text_field( wp_unslash( $_GET['domain'] ?? '' ) );
		$current_search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$current_page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per_page         = 20;

		// Query strings.
		$search_args = array(
			'per_page' => $per_page,
			'page'     => $current_page,
			'order'    => 'ASC',
			'orderby'  => 'id',
		);

		if ( '' !== $current_domain ) {
			$search_args['domain'] = $current_domain;
		}

		if ( '' !== $current_search ) {
			$search_args['search'] = $current_search;
		}

		$result = $this->searchStrings( $search_args );
		$items  = $result['items'] ?? array();
		$total  = $result['total'] ?? 0;
		$pages  = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

		$domains = $this->getDomains();
		$page    = sanitize_text_field( wp_unslash( $_GET['page'] ?? 'novatools-polyglot-strings' ) );
		$has_auto_translate = $this->hasConfiguredProvider();

		// Filters.
		echo '<div class="tablenav top">';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $page ) . '" />';

		echo '<div class="alignleft actions">';
		echo '<select name="domain">';
		echo '<option value="">' . esc_html__( 'All domains', 'novatools-polyglot' ) . '</option>';
		foreach ( $domains as $domain ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $domain ),
				selected( $current_domain, $domain, false ),
				esc_html( $domain )
			);
		}
		echo '</select> ';

		echo '<input type="search" name="s" value="' . esc_attr( $current_search ) . '" placeholder="' . esc_attr__( 'Search strings…', 'novatools-polyglot' ) . '" class="regular-text" /> ';

		submit_button( __( 'Filter', 'novatools-polyglot' ), 'secondary', 'filter_action', false );
		echo '</div>';
		echo '</form>';

		// Pagination.
		if ( $pages > 1 ) {
			echo '<div class="tablenav-pages">';
			printf(
				'<span class="displaying-num">%s</span>',
				sprintf(
					/* translators: %d: total items */
					esc_html__( '%d items', 'novatools-polyglot' ),
					$total
				)
			);
			echo ' ';
			for ( $i = 1; $i <= $pages; $i++ ) {
				if ( $i === $current_page ) {
					printf( '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">%d</span> ', $i );
				} else {
					printf(
						'<a class="button" href="%s">%d</a> ',
						esc_url( add_query_arg( array( 'paged' => $i, 'domain' => $current_domain, 's' => $current_search ), admin_url( "admin.php?page={$page}" ) ) ),
						$i
					);
				}
			}
			echo '</div>';
		}

		echo '</div>';

		// Form for saving translations.
		echo '<form method="post">';
		wp_nonce_field( 'polyglot_save_strings' );
		echo '<input type="hidden" name="polyglot_action" value="save_string_translations" />';

		echo '<table class="wp-list-table widefat fixed striped polyglot-string-table">';
		echo '<thead>';
		echo '<tr>';
		echo '<th style="width:25%;">' . esc_html__( 'String', 'novatools-polyglot' ) . '</th>';
		echo '<th style="width:10%;">' . esc_html__( 'Domain', 'novatools-polyglot' ) . '</th>';
		echo '<th style="width:10%;">' . esc_html__( 'Name', 'novatools-polyglot' ) . '</th>';

		foreach ( $active_languages as $code => $lang ) {
			if ( $code === $default_code ) {
				continue;
			}
			echo '<th>' . esc_html( $lang->englishName ) . '</th>';
		}

		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		if ( ! empty( $items ) ) {
			$repo = $this->plugin->has( 'string.repository' ) ? $this->plugin->get( 'string.repository' ) : null;

			foreach ( $items as $item ) {
				$string_id = (int) $item['id'];
				echo '<tr>';
				echo '<td><span title="' . esc_attr( $item['value'] ) . '">' . esc_html( wp_trim_words( $item['value'], 12, '…' ) ) . '</span></td>';
				echo '<td>' . esc_html( $item['domain'] ) . '</td>';
				echo '<td><code>' . esc_html( $item['name'] ) . '</code></td>';

				foreach ( $active_languages as $code => $lang ) {
					if ( $code === $default_code ) {
						continue;
					}

					$translation = '';
					if ( $repo ) {
						$t = $repo->getTranslation( $string_id, $code );
						if ( $t && 1 === (int) $t['status'] ) {
							$translation = $t['value'];
						}
					}

					printf(
						'<td><textarea name="strings[%d][%s]" rows="2" class="large-text polyglot-string-input" data-string-id="%d" data-language="%s">%s</textarea></td>',
						$string_id,
						esc_attr( $code ),
						$string_id,
						esc_attr( $code ),
						esc_textarea( $translation )
					);
				}

				echo '</tr>';
			}
		} else {
			$colspan = 3 + count( $active_languages ) - ( $default_code ? 1 : 0 );
			printf( '<tr><td colspan="%d">%s</td></tr>', $colspan, esc_html__( 'No strings found.', 'novatools-polyglot' ) );
		}

		echo '</tbody>';
		echo '</table>';

		echo '<p class="submit" style="display:flex;align-items:center;gap:12px;">';
		submit_button( __( 'Save Translations', 'novatools-polyglot' ), 'primary', 'polyglot_save_strings', false );

		if ( $has_auto_translate ) {
			echo '<button type="button" id="polyglot-auto-translate-btn" class="button button-secondary">';
			esc_html_e( 'Auto-Translate All', 'novatools-polyglot' );
			echo '</button>';
			echo '<span id="polyglot-auto-translate-status" style="color:#646970;"></span>';
		}

		echo '</p>';
		echo '</form>';

		// Auto-translate JS.
		if ( $has_auto_translate ) {
			$this->outputAutoTranslateScript();
		}
	}

	/**
	 * Output inline JavaScript for auto-translate functionality.
	 *
	 * Sends empty translation fields to the REST API batch auto-translate
	 * endpoint and fills in the results.
	 *
	 * @return void
	 */
	private function outputAutoTranslateScript(): void {
		$rest_url = rest_url( 'polyglot/v1/auto-translate' );
		$nonce    = wp_create_nonce( 'wp_rest' );
		?>
		<script type="text/javascript">
		jQuery( document ).ready( function( $ ) {
			$( '#polyglot-auto-translate-btn' ).on( 'click', function() {
				var btn = $( this );
				btn.prop( 'disabled', true );
				$( '#polyglot-auto-translate-status' ).text( '<?php echo esc_js( __( 'Translating…', 'novatools-polyglot' ) ); ?>' );

				var strings = [];
				$( '.polyglot-string-input' ).each( function() {
					if ( '' === $( this ).val().trim() ) {
						strings.push( {
							string_id: $( this ).data( 'string-id' ),
							language: $( this ).data( 'language' )
						} );
					}
				} );

				if ( 0 === strings.length ) {
					$( '#polyglot-auto-translate-status' ).text( '<?php echo esc_js( __( 'No empty translations to fill.', 'novatools-polyglot' ) ); ?>' );
					btn.prop( 'disabled', false );
					return;
				}

				$.ajax( {
					url: '<?php echo esc_url( $rest_url ); ?>',
					method: 'POST',
					beforeSend: function( xhr ) {
						xhr.setRequestHeader( 'X-WP-Nonce', '<?php echo esc_js( $nonce ); ?>' );
					},
					data: {
						strings: JSON.stringify( strings )
					},
					success: function( response ) {
						if ( response.data && response.data.translations ) {
							$.each( response.data.translations, function( i, t ) {
								$( '.polyglot-string-input[data-string-id="' + t.string_id + '"][data-language="' + t.language + '"]' ).val( t.value );
							} );
							$( '#polyglot-auto-translate-status' ).text(
								response.data.translations.length + ' <?php echo esc_js( __( 'translations completed.', 'novatools-polyglot' ) ); ?>'
							);
						}
						btn.prop( 'disabled', false );
					},
					error: function() {
						$( '#polyglot-auto-translate-status' ).text( '<?php echo esc_js( __( 'Auto-translation failed. Check your API settings.', 'novatools-polyglot' ) ); ?>' );
						btn.prop( 'disabled', false );
					}
				} );
			} );
		} );
		</script>
		<?php
	}

	// ─── Content Translation Overview ─────────────────────────────────────

	/**
	 * Output the content translation overview (post/term translation groups).
	 *
	 * @return void
	 */
	private function outputContentTranslations(): void {
		$active_languages = $this->getActiveLanguages();
		$default_lang     = $this->getDefaultLanguage();
		$default_code     = $default_lang ? $default_lang->code : '';

		if ( empty( $active_languages ) || ! $default_lang ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Please configure at least one language before translating content.', 'novatools-polyglot' ) . '</p></div>';
			return;
		}

		$groups = $this->getTranslationGroups();
		$page   = sanitize_text_field( wp_unslash( $_GET['page'] ?? 'novatools-polyglot-translations' ) );

		echo '<h2>' . esc_html__( 'Content Translations', 'novatools-polyglot' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Translation groups show which pieces of content are linked across languages.', 'novatools-polyglot' ) . '</p>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>' . esc_html__( 'Group', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'novatools-polyglot' ) . '</th>';

		foreach ( $active_languages as $code => $lang ) {
			echo '<th>' . esc_html( $lang->englishName ) . '</th>';
		}

		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		if ( ! empty( $groups ) ) {
			foreach ( $groups as $group ) {
				echo '<tr>';
				echo '<td>' . esc_html( '#' . $group['trid'] ) . '</td>';
				echo '<td>' . esc_html( $group['element_type'] ) . '</td>';

				foreach ( $active_languages as $code => $lang ) {
					$element = $group['elements'][ $code ] ?? null;
					if ( $element ) {
						$status_class = $this->statusColor( $element['status'] );
						echo '<td>';
						echo '<span class="dashicons ' . esc_attr( $this->statusIcon( $element['status'] ) ) . '" style="color:' . esc_attr( $status_class ) . ';"></span> ';
						echo '<a href="' . esc_url( get_edit_post_link( $element['element_id'] ) ) . '">' . esc_html( $this->getElementTitle( $element ) ) . '</a>';
						echo '</td>';
					} else {
						echo '<td><em style="color:#999;">' . esc_html__( 'Not translated', 'novatools-polyglot' ) . '</em></td>';
					}
				}

				echo '</tr>';
			}
		} else {
			$colspan = 2 + count( $active_languages );
			printf( '<tr><td colspan="%d">%s</td></tr>', $colspan, esc_html__( 'No translation groups found.', 'novatools-polyglot' ) );
		}

		echo '</tbody>';
		echo '</table>';
	}

	// ─── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Output the page header.
	 *
	 * @param string $mode Current rendering mode.
	 * @return void
	 */
	private function outputHeader( string $mode ): void {
		$title = 'strings' === $mode
			? __( 'String Translation', 'novatools-polyglot' )
			: __( 'Translations', 'novatools-polyglot' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
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
	 * Search strings using the string repository.
	 *
	 * @param array $args Search arguments.
	 * @return array
	 */
	private function searchStrings( array $args ): array {
		try {
			if ( $this->plugin->has( 'string.repository' ) ) {
				return $this->plugin->get( 'string.repository' )->search( $args );
			}
		} catch ( \Throwable ) {
			// Fall through.
		}
		return array( 'items' => array(), 'total' => 0 );
	}

	/**
	 * Get all distinct text domains from the strings table.
	 *
	 * @return string[]
	 */
	private function getDomains(): array {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_strings' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_col( "SELECT DISTINCT domain FROM {$table} ORDER BY domain ASC" );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get translation groups for the content overview.
	 *
	 * Returns a simplified representation of translation groups with their
	 * elements keyed by language code.
	 *
	 * @return array[]
	 */
	private function getTranslationGroups(): array {
		global $wpdb;

		$table = Schema::getTableName( 'polyglot_translations' );
		$limit = 50;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			"SELECT t.* FROM {$table} t INNER JOIN (SELECT DISTINCT trid FROM {$table} LIMIT {$limit}) g ON t.trid = g.trid ORDER BY t.trid ASC, t.language_code ASC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$groups = array();
		foreach ( $rows as $row ) {
			$trid = (int) $row['trid'];
			if ( ! isset( $groups[ $trid ] ) ) {
				$groups[ $trid ] = array(
					'trid'         => $trid,
					'element_type' => $row['element_type'],
					'elements'     => array(),
				);
			}
			$groups[ $trid ]['elements'][ $row['language_code'] ] = $row;
		}

		return array_values( $groups );
	}

	/**
	 * Get a human-readable title for a translation element.
	 *
	 * @param array $element Translation row data.
	 * @return string
	 */
	private function getElementTitle( array $element ): string {
		$post = get_post( (int) $element['element_id'] );
		if ( $post ) {
			return $post->post_title ? $post->post_title : __( '(no title)', 'novatools-polyglot' );
		}

		$term = get_term( (int) $element['element_id'] );
		if ( $term && ! is_wp_error( $term ) ) {
			return $term->name;
		}

		return '#' . $element['element_id'];
	}

	/**
	 * Get the CSS color for a translation status.
	 *
	 * @param string $status Translation status string.
	 * @return string CSS color value.
	 */
	private function statusColor( string $status ): string {
		return match ( $status ) {
			'completed', 'translated' => '#00a32a',
			'in_progress'             => '#dba617',
			'needs_update'            => '#d63638',
			'awaiting_review'         => '#2271b1',
			default                   => '#646970',
		};
	}

	/**
	 * Get the dashicon class for a translation status.
	 *
	 * @param string $status Translation status string.
	 * @return string Dashicon class name.
	 */
	private function statusIcon( string $status ): string {
		return match ( $status ) {
			'completed', 'translated' => 'dashicons-yes-alt',
			'in_progress'             => 'dashicons-clock',
			'needs_update'            => 'dashicons-warning',
			'awaiting_review'         => 'dashicons-visibility',
			default                   => 'dashicons-minus',
		};
	}

	/**
	 * Check if at least one translation provider is configured.
	 *
	 * @return bool
	 */
	private function hasConfiguredProvider(): bool {
		try {
			if ( $this->plugin->has( 'provider.registry' ) ) {
				return ! empty( $this->plugin->get( 'provider.registry' )->getConfigured() );
			}
		} catch ( \Throwable ) {
			// Fall through.
		}
		return false;
	}
}
