<?php
/**
 * Dashboard admin page for NovaTools Polyglot.
 *
 * Displays a translation status overview with completion percentages
 * per language. Queries translation and string repositories to build
 * aggregate statistics.
 *
 * @package NovaTools\Polyglot\Admin
 */

namespace NovaTools\Polyglot\Admin;

use NovaTools\Polyglot\Core\Plugin;
use NovaTools\Polyglot\Database\Schema;

defined( 'ABSPATH' ) || exit;

class DashboardPage {

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
	 * Render the dashboard page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'novatools-polyglot' ) );
		}

		$active_languages = $this->getActiveLanguages();
		$default_language = $this->getDefaultLanguage();
		$stats            = $this->gatherStats( $active_languages, $default_language );

		$this->outputHeader();
		$this->outputSummaryCards( $stats );
		$this->outputLanguageTable( $active_languages, $default_language, $stats );
		$this->outputFooter();
	}

	/**
	 * Gather translation statistics for all active languages.
	 *
	 * Returns per-language counts of translated, in-progress, and
	 * untranslated content, plus string translation totals.
	 *
	 * @param array      $languages Active language objects keyed by code.
	 * @param object|null $default  Default language object.
	 * @return array Stats array keyed by language code.
	 */
	private function gatherStats( array $languages, ?object $default ): array {
		global $wpdb;

		$stats            = array();
		$default_code     = $default ? $default->code : '';
		$translations_tbl = Schema::getTableName( 'polyglot_translations' );
		$strings_tbl      = Schema::getTableName( 'polyglot_strings' );
		$str_trans_tbl    = Schema::getTableName( 'polyglot_string_translations' );

		foreach ( $languages as $code => $lang ) {
			// Skip the default language for translation counts (it IS the source).
			if ( $code === $default_code ) {
				$stats[ $code ] = array(
					'content_total'        => 0,
					'content_translated'   => 0,
					'content_in_progress'  => 0,
					'content_needs_update' => 0,
					'content_not_translated' => 0,
					'content_completion'   => 100,
					'strings_total'        => 0,
					'strings_translated'   => 0,
					'strings_completion'   => 100,
				);
				continue;
			}

			// Content translation counts.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$content_counts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT status, COUNT(*) AS cnt FROM {$translations_tbl} WHERE language_code = %s AND element_type LIKE 'post_%%' GROUP BY status",
					$code
				),
				OBJECT_K
			);

			$content_total        = 0;
			$content_translated   = 0;
			$content_in_progress  = 0;
			$content_needs_update = 0;
			$content_not_translated = 0;

			if ( is_array( $content_counts ) ) {
				foreach ( $content_counts as $status => $row ) {
					$count = (int) $row->cnt;
					$content_total += $count;

					switch ( $status ) {
						case 'completed':
						case 'translated':
							$content_translated += $count;
							break;
						case 'in_progress':
						case 'awaiting_review':
							$content_in_progress += $count;
							break;
						case 'needs_update':
							$content_needs_update += $count;
							break;
						case 'not_translated':
						default:
							$content_not_translated += $count;
							break;
					}
				}
			}

			$content_completion = $content_total > 0
				? round( ( $content_translated / $content_total ) * 100 )
				: 0;

			// String translation counts.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$strings_total = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$strings_tbl}"
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$strings_translated = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$str_trans_tbl} WHERE language = %s AND status = 1",
					$code
				)
			);

			$strings_completion = $strings_total > 0
				? round( ( $strings_translated / $strings_total ) * 100 )
				: 0;

			$stats[ $code ] = array(
				'content_total'          => $content_total,
				'content_translated'     => $content_translated,
				'content_in_progress'    => $content_in_progress,
				'content_needs_update'   => $content_needs_update,
				'content_not_translated' => $content_not_translated,
				'content_completion'     => $content_completion,
				'strings_total'          => $strings_total,
				'strings_translated'     => $strings_translated,
				'strings_completion'     => $strings_completion,
			);
		}

		return $stats;
	}

	/**
	 * Output the page header.
	 *
	 * @return void
	 */
	private function outputHeader(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Polyglot Dashboard', 'novatools-polyglot' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Translation status overview for your multilingual site.', 'novatools-polyglot' ) . '</p>';
	}

	/**
	 * Output summary cards with key metrics.
	 *
	 * @param array $stats Per-language stats.
	 * @return void
	 */
	private function outputSummaryCards( array $stats ): void {
		$total_languages   = count( $stats );
		$total_content     = 0;
		$translated_content = 0;
		$total_strings      = 0;
		$translated_strings = 0;

		foreach ( $stats as $s ) {
			$total_content      += $s['content_total'];
			$translated_content += $s['content_translated'];
			$total_strings      += $s['strings_total'];
			$translated_strings += $s['strings_translated'];
		}

		$content_pct = $total_content > 0 ? round( ( $translated_content / $total_content ) * 100 ) : 0;
		$strings_pct = $total_strings > 0 ? round( ( $translated_strings / $total_strings ) * 100 ) : 0;

		echo '<div style="display:flex;gap:20px;margin:20px 0;flex-wrap:wrap;">';

		$this->summaryCard(
			__( 'Active Languages', 'novatools-polyglot' ),
			(string) $total_languages,
			'dashicons-translation'
		);

		$this->summaryCard(
			__( 'Content Translated', 'novatools-polyglot' ),
			$content_pct . '%',
			'dashicons-admin-page',
			"{$translated_content} / {$total_content}"
		);

		$this->summaryCard(
			__( 'Strings Translated', 'novatools-polyglot' ),
			$strings_pct . '%',
			'dashicons-editor-code',
			"{$translated_strings} / {$total_strings}"
		);

		$configured_providers = $this->getConfiguredProviderCount();
		$this->summaryCard(
			__( 'Translation APIs', 'novatools-polyglot' ),
			(string) $configured_providers,
			'dashicons-admin-plugins'
		);

		echo '</div>';
	}

	/**
	 * Render a single summary card.
	 *
	 * @param string $title   Card title.
	 * @param string $value   Primary value to display.
	 * @param string $icon    Dashicon class name.
	 * @param string $detail  Optional detail line below the value.
	 * @return void
	 */
	private function summaryCard( string $title, string $value, string $icon, string $detail = '' ): void {
		echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;min-width:200px;flex:1;">';
		echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
		echo '<span class="dashicons ' . esc_attr( $icon ) . '" style="color:#2271b1;font-size:20px;"></span>';
		echo '<strong>' . esc_html( $title ) . '</strong>';
		echo '</div>';
		echo '<div style="font-size:28px;font-weight:600;color:#1d2327;">' . esc_html( $value ) . '</div>';
		if ( '' !== $detail ) {
			echo '<div style="color:#646970;font-size:13px;margin-top:4px;">' . esc_html( $detail ) . '</div>';
		}
		echo '</div>';
	}

	/**
	 * Get the count of configured translation providers.
	 *
	 * @return int
	 */
	private function getConfiguredProviderCount(): int {
		try {
			if ( $this->plugin->has( 'provider.registry' ) ) {
				return count( $this->plugin->get( 'provider.registry' )->getConfigured() );
			}
		} catch ( \Throwable ) {
			// Fall through.
		}

		return 0;
	}

	/**
	 * Output the per-language translation status table.
	 *
	 * @param array       $languages Active language objects.
	 * @param object|null $default   Default language object.
	 * @param array       $stats     Per-language statistics.
	 * @return void
	 */
	private function outputLanguageTable( array $languages, ?object $default, array $stats ): void {
		$default_code = $default ? $default->code : '';

		echo '<h2>' . esc_html__( 'Language Status', 'novatools-polyglot' ) . '</h2>';
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>' . esc_html__( 'Language', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Content', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Strings', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Needs Update', 'novatools-polyglot' ) . '</th>';
		echo '<th>' . esc_html__( 'Overall', 'novatools-polyglot' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		foreach ( $languages as $code => $lang ) {
			$s = $stats[ $code ] ?? array();
			$is_default = $code === $default_code;

			echo '<tr>';
			echo '<td>';
			echo '<strong>' . esc_html( $lang->englishName ) . '</strong>';
			if ( $is_default ) {
				echo ' <span class="dashicons dashicons-star-filled" style="color:#dba617;font-size:14px;" title="' . esc_attr__( 'Default language', 'novatools-polyglot' ) . '"></span>';
			}
			echo '<br><span style="color:#646970;">' . esc_html( $code ) . ' &middot; ' . esc_html( $lang->nativeName ) . '</span>';
			echo '</td>';

			// Content column.
			if ( $is_default ) {
				echo '<td><em>' . esc_html__( 'Source language', 'novatools-polyglot' ) . '</em></td>';
			} else {
				$content_pct = $s['content_completion'] ?? 0;
				echo '<td>' . $this->progressBar( $content_pct ) . '</td>';
			}

			// Strings column.
			if ( $is_default ) {
				echo '<td><em>' . esc_html__( 'Source language', 'novatools-polyglot' ) . '</em></td>';
			} else {
				$strings_pct = $s['strings_completion'] ?? 0;
				echo '<td>' . $this->progressBar( $strings_pct ) . '</td>';
			}

			// Needs update column.
			$needs_update = $s['content_needs_update'] ?? 0;
			echo '<td>';
			if ( $needs_update > 0 ) {
				echo '<span style="color:#d63638;font-weight:600;">' . esc_html( $needs_update ) . '</span>';
			} else {
				echo '<span style="color:#00a32a;">&mdash;</span>';
			}
			echo '</td>';

			// Overall column.
			if ( $is_default ) {
				echo '<td><span style="color:#00a32a;font-weight:600;">100%</span></td>';
			} else {
				$content_pct = $s['content_completion'] ?? 0;
				$strings_pct = $s['strings_completion'] ?? 0;
				$overall     = ( $content_pct + $strings_pct ) > 0
					? round( ( $content_pct + $strings_pct ) / 2 )
					: 0;
				echo '<td>' . $this->progressBar( $overall ) . '</td>';
			}

			echo '</tr>';
		}

		if ( empty( $languages ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No languages configured yet.', 'novatools-polyglot' ) . '</td></tr>';
		}

		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * Render a percentage progress bar.
	 *
	 * @param int $percentage Completion percentage (0-100).
	 * @return string HTML output.
	 */
	private function progressBar( int $percentage ): string {
		$percentage = max( 0, min( 100, $percentage ) );
		$color      = $percentage >= 80 ? '#00a32a' : ( $percentage >= 50 ? '#dba617' : '#d63638' );

		return sprintf(
			'<div style="display:flex;align-items:center;gap:8px;">
				<div style="background:#dcdcde;border-radius:3px;height:14px;width:100px;overflow:hidden;">
					<div style="background:%s;height:100%%;width:%d%%;border-radius:3px;"></div>
				</div>
				<span style="font-weight:500;color:%2$s;">%d%%</span>
			</div>',
			esc_attr( $color ),
			$percentage,
			$percentage
		);
	}

	/**
	 * Output the page footer.
	 *
	 * @return void
	 */
	private function outputFooter(): void {
		echo '</div>';
	}
}
