<?php
/**
 * Admin list table columns for translation status.
 *
 * Adds a language column to WordPress admin post/page list tables showing
 * translation status icons per active language with quick-action links.
 *
 * @package NovaTools\Polyglot\Admin
 */

namespace NovaTools\Polyglot\Admin;

use NovaTools\Polyglot\Language\LanguageRepository;
use NovaTools\Polyglot\Translation\TranslationRepository;
use NovaTools\Polyglot\Database\Schema;

defined( 'ABSPATH' ) || exit;

class AdminListColumns {

	/**
	 * Translation repository instance.
	 *
	 * @var TranslationRepository
	 */
	private TranslationRepository $translation_repository;

	/**
	 * Language repository instance.
	 *
	 * @var LanguageRepository
	 */
	private LanguageRepository $language_repository;

	/**
	 * Cached translation data for posts on the current page.
	 *
	 * @var array
	 */
	private array $translations_cache = array();

	/**
	 * Whether translation data has been preloaded for the current page.
	 *
	 * @var bool
	 */
	private static bool $loaded = false;

	/**
	 * Constructor.
	 *
	 * @param TranslationRepository $translation_repository Translation repository.
	 * @param LanguageRepository    $language_repository    Language repository.
	 */
	public function __construct(
		TranslationRepository $translation_repository,
		LanguageRepository $language_repository
	) {
		$this->translation_repository = $translation_repository;
		$this->language_repository    = $language_repository;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_custom_columns_hooks' ), 1010 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'save_post', array( $this, 'link_translation_on_save' ), 10, 3 );
		add_action( 'save_post', array( $this, 'mark_translation_completed' ), 20, 3 );
		add_action( 'save_post', array( $this, 'invalidate_excluded_posts_cache' ), 30, 3 );
		add_action( 'before_delete_post', array( $this, 'invalidate_excluded_posts_cache_on_delete' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_translation_context_script' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_translated_posts' ) );
	}

	/**
	 * Register column filters and actions for the current post type.
	 *
	 * @return void
	 */
	public function add_custom_columns_hooks(): void {
		if ( ! $this->has_custom_columns() ) {
			return;
		}

		$post_type = $this->get_current_post_type();

		add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_posts_management_column' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'add_content_for_posts_management_column' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( $this, 'add_content_for_posts_management_column' ), 10, 2 );
	}

	/**
	 * Guard: only show columns on edit.php screens (not trash, not AJAX).
	 *
	 * @return bool
	 */
	public function has_custom_columns(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		if ( wp_doing_ajax() ) {
			return false;
		}

		global $pagenow;

		if ( 'edit.php' !== $pagenow ) {
			return false;
		}

		if ( isset( $_GET['post_status'] ) && 'trash' === $_GET['post_status'] ) {
			return false;
		}

		$languages = $this->language_repository->getActive();

		return count( $languages ) > 1;
	}

	/**
	 * Filter the main query to exclude translated duplicate posts.
	 *
	 * On edit.php screens, excludes posts that are translations of another
	 * post (i.e., not the source post in their translation group).
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 * @return void
	 */
	public function filter_translated_posts( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || ! is_admin() ) {
			return;
		}

		global $pagenow;

		if ( 'edit.php' !== $pagenow ) {
			return;
		}

		$languages = $this->language_repository->getActive();

		if ( count( $languages ) <= 1 ) {
			return;
		}

		$default = $this->language_repository->getDefault();

		if ( ! $default ) {
			return;
		}

		$post_type    = $this->get_current_post_type();
		$cache_key    = "excluded_posts:{$post_type}:{$default->code}";
		$exclude_ids  = wp_cache_get( $cache_key, 'polyglot' );

		if ( false === $exclude_ids ) {
			$table = Schema::getTableName( 'polyglot_translations' );

			global $wpdb;

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$exclude_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT t1.element_id FROM {$table} t1
					INNER JOIN {$table} t2 ON t1.trid = t2.trid AND t2.language_code = %s
					WHERE t1.element_type LIKE 'post_%%' AND t1.language_code != %s",
					$default->code,
					$default->code
				)
			);

			wp_cache_set( $cache_key, $exclude_ids, 'polyglot', 5 * MINUTE_IN_SECONDS );
		}

		if ( ! empty( $exclude_ids ) ) {
			$exclude_ids = array_map( 'intval', $exclude_ids );
			$query->set( 'post__not_in', array_merge(
				$query->get( 'post__not_in' ) ?: array(),
				$exclude_ids
			) );
		}
	}

	/**
	 * Get the current post type from the request.
	 *
	 * @return string Post type slug, defaults to 'post'.
	 */
	private function get_current_post_type(): string {
		if ( isset( $_GET['post_type'] ) ) {
			return sanitize_key( $_GET['post_type'] );
		}

		return 'post';
	}

	/**
	 * Add the polyglot_translations column to the posts list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_posts_management_column( array $columns ): array {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'title' === $key || 'name' === $key ) {
				$new_columns['polyglot_translations'] = $this->get_flags_column();
			}
		}

		return $new_columns;
	}

	/**
	 * Render flag images for the column header.
	 *
	 * @return string HTML for the column header.
	 */
	private function get_flags_column(): string {
		$default = $this->language_repository->getDefault();
		$active  = $this->language_repository->getActive();

		$html = '<span class="polyglot-translations-column">';

		foreach ( $active as $lang ) {
			if ( $default && $lang->code === $default->code ) {
				continue;
			}

			$flag_url = $this->get_flag_url( $lang->flagCode );

			if ( $flag_url ) {
				$html .= sprintf(
					'<img src="%s" alt="%s" title="%s" width="18" height="12" style="margin-right:2px;">',
					esc_url( $flag_url ),
					esc_attr( $lang->code ),
					esc_attr( $lang->englishName )
				);
			} else {
				$html .= sprintf(
					'<span title="%s" style="margin-right:2px;font-size:10px;">%s</span>',
					esc_attr( $lang->englishName ),
					esc_html( strtoupper( $lang->code ) )
				);
			}
		}

		$html .= '</span>';

		return $html;
	}

	/**
	 * Get the flag image URL for a language.
	 *
	 * @param string $flag_code ISO flag code.
	 * @return string|null Flag URL or null.
	 */
	private function get_flag_url( string $flag_code ): ?string {
		if ( empty( $flag_code ) ) {
			return null;
		}

		$flag_code = strtolower( $flag_code );
		$flag_file = $flag_code . '.png';
		$path      = NOVATOOLS_POLYGLOT_DIR . 'assets/images/flags/' . $flag_file;

		if ( ! file_exists( $path ) ) {
			$parts         = explode( '_', $flag_code );
			$fallback      = $parts[0] . '.png';
			$fallback_path = NOVATOOLS_POLYGLOT_DIR . 'assets/images/flags/' . $fallback;

			if ( ! file_exists( $fallback_path ) ) {
				return null;
			}

			return NOVATOOLS_POLYGLOT_ASSETS_URL . '/images/flags/' . $fallback;
		}

		return NOVATOOLS_POLYGLOT_ASSETS_URL . '/images/flags/' . $flag_file;
	}

	/**
	 * Render translation status icons for a post row.
	 *
	 * @param string $column_name Column identifier.
	 * @param int    $post_id     Post ID.
	 * @return void
	 */
	public function add_content_for_posts_management_column( string $column_name, int $post_id ): void {
		if ( 'polyglot_translations' !== $column_name ) {
			return;
		}

		$this->preloadTranslationData();

		$active = $this->language_repository->getActive();
		$post   = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		$post_type    = $post->post_type;
		$post_lang    = $this->get_post_language( $post_id );

		echo '<span class="polyglot-translations-column">';

		foreach ( $active as $lang ) {
			if ( $post_lang && $lang->code === $post_lang ) {
				continue;
			}

			echo $this->get_status_html( $post_id, $lang->code, $post_type );
		}

		echo '</span>';
	}

	/**
	 * Get the language code for a post from the translation cache.
	 *
	 * Falls back to the default language if no translation row exists.
	 *
	 * @param int $post_id Post ID.
	 * @return string Language code.
	 */
	private function get_post_language( int $post_id ): string {
		if ( isset( $this->translations_cache[ $post_id ] ) ) {
			foreach ( $this->translations_cache[ $post_id ] as $row ) {
				if ( (int) $row['element_id'] === $post_id ) {
					return $row['language_code'];
				}
			}
		}

		$default = $this->language_repository->getDefault();

		return $default ? $default->code : 'en';
	}

	/**
	 * Batch-preload translation data for all posts on the current page.
	 *
	 * Fetches translation groups (trids) for all posts, then loads all
	 * translations in those groups so we can show status for every language.
	 *
	 * @return void
	 */
	private function preloadTranslationData(): void {
		if ( self::$loaded ) {
			return;
		}

		self::$loaded = true;

		global $wp_query;

		if ( ! $wp_query || empty( $wp_query->posts ) ) {
			return;
		}

		$post_ids = array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) );

		if ( empty( $post_ids ) ) {
			return;
		}

		$table        = Schema::getTableName( 'polyglot_translations' );
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		global $wpdb;

		// Step 1: Get trids for posts on this page.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT element_id, trid FROM {$table} WHERE element_type LIKE 'post_%%' AND element_id IN ({$placeholders})",
				$post_ids
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		$post_to_trid = array();
		$trids        = array();

		foreach ( $rows as $row ) {
			$post_to_trid[ (int) $row['element_id'] ] = (int) $row['trid'];
			$trids[ (int) $row['trid'] ] = true;
		}

		$trid_list     = array_keys( $trids );
		$trid_placeholders = implode( ',', array_fill( 0, count( $trid_list ), '%d' ) );

		// Step 2: Get all translations in those groups.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$group_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE trid IN ({$trid_placeholders})",
				$trid_list
			),
			ARRAY_A
		);

		if ( ! is_array( $group_rows ) ) {
			return;
		}

		// Build a lookup: trid -> language_code -> row.
		$trid_translations = array();

		foreach ( $group_rows as $row ) {
			$trid = (int) $row['trid'];
			$lang = $row['language_code'];

			if ( ! isset( $trid_translations[ $trid ] ) ) {
				$trid_translations[ $trid ] = array();
			}

			$trid_translations[ $trid ][ $lang ] = $row;
		}

		// Step 3: Map each post_id to its full translation group.
		foreach ( $post_ids as $post_id ) {
			$trid = $post_to_trid[ $post_id ] ?? null;

			if ( $trid && isset( $trid_translations[ $trid ] ) ) {
				$this->translations_cache[ $post_id ] = $trid_translations[ $trid ];
			}
		}
	}

	/**
	 * Get the status HTML for a post/language combination.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $lang_code Language code.
	 * @param string $post_type Post type.
	 * @return string HTML for the status icon.
	 */
	private function get_status_html( int $post_id, string $lang_code, string $post_type ): string {
		$translation = $this->translations_cache[ $post_id ][ $lang_code ] ?? null;

		if ( ! $translation ) {
			$link = $this->generate_add_link( $post_id, $post_type, $lang_code );

			return sprintf(
				'<a href="%s" title="%s" class="polyglot-icon polyglot-icon-add"><span class="dashicons dashicons-plus-alt"></span></a>',
				esc_url( $link ),
				esc_attr( sprintf( 'Add translation to %s', strtoupper( $lang_code ) ) )
			);
		}

		$status = $translation['status'] ?? 'not_translated';

		switch ( $status ) {
			case 'completed':
			case 'translated':
			case 'in_progress':
				$translated_post_id = (int) $translation['element_id'];
				$link = $this->generate_edit_link( $translated_post_id );

				return sprintf(
					'<a href="%s" title="%s" class="polyglot-icon polyglot-icon-edit"><span class="dashicons dashicons-edit"></span></a>',
					esc_url( $link ),
					esc_attr( sprintf( 'Edit %s translation', strtoupper( $lang_code ) ) )
				);

			case 'needs_update':
				$translated_post_id = (int) $translation['element_id'];
				$link = $this->generate_edit_link( $translated_post_id );

				return sprintf(
					'<a href="%s" title="%s" class="polyglot-icon polyglot-icon-update"><span class="dashicons dashicons-update"></span></a>',
					esc_url( $link ),
					esc_attr( sprintf( '%s translation needs update', strtoupper( $lang_code ) ) )
				);

			default:
				$link = $this->generate_add_link( $post_id, $post_type, $lang_code );

				return sprintf(
					'<a href="%s" title="%s" class="polyglot-icon polyglot-icon-add"><span class="dashicons dashicons-plus-alt"></span></a>',
					esc_url( $link ),
					esc_attr( sprintf( 'Add translation to %s', strtoupper( $lang_code ) ) )
				);
		}
	}

	/**
	 * Generate edit link for a translated post.
	 *
	 * @param int $translated_post_id Translated post ID.
	 * @return string Edit URL.
	 */
	private function generate_edit_link( int $translated_post_id ): string {
		return admin_url( 'post.php?post=' . $translated_post_id . '&action=edit' );
	}

	/**
	 * Generate add link for creating a new translation.
	 *
	 * @param int    $post_id   Source post ID.
	 * @param string $post_type Post type.
	 * @param string $lang_code Target language code.
	 * @return string New post URL.
	 */
	private function generate_add_link( int $post_id, string $post_type, string $lang_code ): string {
		if ( in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
			return admin_url(
				'admin.php?action=polyglot_create_product_translation'
				. '&source=' . $post_id
				. '&lang=' . urlencode( $lang_code )
				. '&_wpnonce=' . wp_create_nonce( 'polyglot_create_product_translation' )
			);
		}

		return admin_url(
			'post-new.php?post_type=' . urlencode( $post_type )
			. '&polyglot_source=' . $post_id
			. '&polyglot_lang=' . urlencode( $lang_code )
		);
	}

	/**
	 * Enqueue JavaScript to store translation context and inject hidden fields.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_translation_context_script( string $hook_suffix ): void {
		if ( 'edit.php' === substr( $hook_suffix, 0, 8 ) ) {
			add_action( 'admin_footer', array( $this, 'output_list_page_script' ) );
		}

		if ( 'post-new.php' === $hook_suffix ) {
			add_action( 'admin_footer', array( $this, 'output_editor_page_script' ) );
		}
	}

	/**
	 * Output JavaScript for the list page to store translation context.
	 *
	 * @return void
	 */
	public function output_list_page_script(): void {
		?>
		<script>
		document.addEventListener('mousedown', function(e) {
			var link = e.target.closest('a.polyglot-icon-add');
			if (!link) return;
			var url = new URL(link.href);
			var source = url.searchParams.get('polyglot_source');
			var lang = url.searchParams.get('polyglot_lang');
			if (source && lang) {
				document.cookie = 'polyglot_source=' + source + '; path=/; max-age=300';
				document.cookie = 'polyglot_lang=' + lang + '; path=/; max-age=300';
			}
		});
		</script>
		<?php
	}

	/**
	 * Output JavaScript for the editor page to inject hidden fields.
	 *
	 * @return void
	 */
	public function output_editor_page_script(): void {
		// Not needed - using cookies.
	}

	/**
	 * Store translation context from URL parameters into a transient.
	 *
	 * @return void
	 */
	public function store_translation_context(): void {
		// Unused - replaced by JavaScript approach.
	}

	/**
	 * Inject hidden fields for translation context into the post editor.
	 *
	 * @param \WP_Post $post The current post being edited.
	 * @return void
	 */
	public function inject_translation_fields( \WP_Post $post ): void {
		// Unused - replaced by JavaScript approach.
	}

	/**
	 * Link a newly created post as a translation of the source post.
	 *
	 * Reads `polyglot_source` and `polyglot_lang` from the request and
	 * creates translation rows linking the new post to the source.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 * @return void
	 */
	public function link_translation_on_save( int $post_id, \WP_Post $post, bool $update ): void {
		if ( $update ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$source_id = isset( $_COOKIE['polyglot_source'] ) ? (int) $_COOKIE['polyglot_source'] : 0;
		$lang_code = isset( $_COOKIE['polyglot_lang'] ) ? sanitize_key( $_COOKIE['polyglot_lang'] ) : '';

		if ( ! $source_id || empty( $lang_code ) ) {
			return;
		}

		// Clear the cookies.
		unset( $_COOKIE['polyglot_source'] );
		unset( $_COOKIE['polyglot_lang'] );
		setcookie( 'polyglot_source', '', time() - 3600, '/' );
		setcookie( 'polyglot_lang', '', time() - 3600, '/' );

		$source = get_post( $source_id );

		if ( ! $source || $source->post_type !== $post->post_type ) {
			return;
		}

		$element_type = 'post_' . $post->post_type;

		$existing_source = $this->translation_repository->getByElement( $element_type, $source_id );
		$trid            = $existing_source ? (int) $existing_source['trid'] : $this->translation_repository->getNextTrid();
		$source_lang     = $existing_source ? $existing_source['language_code'] : polyglot_get_current_language();

		$this->translation_repository->save( array(
			'element_id'           => $post_id,
			'element_type'         => $element_type,
			'trid'                 => $trid,
			'language_code'        => $lang_code,
			'source_language_code' => $source_lang,
			'status'               => 'in_progress',
		) );

		if ( ! $existing_source ) {
			$this->translation_repository->save( array(
				'element_id'           => $source_id,
				'element_type'         => $element_type,
				'trid'                 => $trid,
				'language_code'        => $source_lang,
				'source_language_code' => '',
				'status'               => 'completed',
			) );
		}
	}

	/**
	 * Mark translation as completed when a post is published.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 * @return void
	 */
	public function mark_translation_completed( int $post_id, \WP_Post $post, bool $update ): void {
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$element_type = 'post_' . $post->post_type;
		$row          = $this->translation_repository->getByElement( $element_type, $post_id );

		if ( $row && 'completed' !== $row['status'] ) {
			$this->translation_repository->updateStatus( $element_type, $post_id, 'completed' );
		}
	}

	/**
	 * Invalidate the excluded posts cache when a post is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 * @return void
	 */
	public function invalidate_excluded_posts_cache( int $post_id, \WP_Post $post, bool $update ): void {
		$this->flush_excluded_posts_cache( $post->post_type );
	}

	/**
	 * Invalidate the excluded posts cache when a post is deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function invalidate_excluded_posts_cache_on_delete( int $post_id ): void {
		$post = get_post( $post_id );

		if ( $post ) {
			$this->flush_excluded_posts_cache( $post->post_type );
		}
	}

	/**
	 * Flush all excluded posts cache entries for a post type.
	 *
	 * Iterates over active languages and deletes the cache key for each,
	 * since we cannot delete by prefix with the WordPress object cache API.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	private function flush_excluded_posts_cache( string $post_type ): void {
		$languages = $this->language_repository->getActive();

		foreach ( $languages as $lang ) {
			wp_cache_delete( "excluded_posts:{$post_type}:{$lang->code}", 'polyglot' );
		}
	}

	/**
	 * Enqueue admin CSS for translation column styling.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_styles( string $hook_suffix ): void {
		if ( 'edit.php' !== substr( $hook_suffix, 0, 8 ) ) {
			return;
		}

		$css = '
			.polyglot-translations-column {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				white-space: nowrap;
			}
			.polyglot-translations-column img {
				width: 18px;
				height: 12px;
				border: 1px solid #ddd;
				border-radius: 2px;
			}
			.polyglot-icon {
				text-decoration: none;
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 20px;
				height: 20px;
				border-radius: 3px;
				transition: background-color 0.2s;
			}
			.polyglot-icon:hover {
				background-color: #f0f0f1;
			}
			.polyglot-icon .dashicons {
				font-size: 14px;
				width: 14px;
				height: 14px;
				line-height: 14px;
			}
			.polyglot-icon-edit .dashicons {
				color: #2271b1;
			}
			.polyglot-icon-update .dashicons {
				color: #dba617;
			}
			.polyglot-icon-add .dashicons {
				color: #999;
			}
		';

		wp_register_style( 'polyglot-admin-list-columns', false );
		wp_enqueue_style( 'polyglot-admin-list-columns' );
		wp_add_inline_style( 'polyglot-admin-list-columns', $css );
	}
}
