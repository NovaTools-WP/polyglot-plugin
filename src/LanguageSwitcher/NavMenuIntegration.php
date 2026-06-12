<?php
/**
 * Nav menu integration for NovaTools Polyglot language switcher.
 *
 * Provides a custom meta box in the WordPress menu editor allowing
 * administrators to add individual language items to any navigation menu.
 * Also filters `wp_get_nav_menu_items` to inject language switcher items
 * when the "language" meta key is present on a menu item.
 *
 * On the frontend, language menu items link to the current page's
 * translation in the target language.
 *
 * @package NovaTools\Polyglot\LanguageSwitcher
 */

namespace NovaTools\Polyglot\LanguageSwitcher;

use NovaTools\Polyglot\Language\LanguageRepository;
use NovaTools\Polyglot\Language\Language;

defined( 'ABSPATH' ) || exit;

class NavMenuIntegration {

	/**
	 * The central language switcher renderer.
	 *
	 * @var LanguageSwitcher
	 */
	private LanguageSwitcher $switcher;

	/**
	 * Language repository for fetching active languages.
	 *
	 * @var LanguageRepository
	 */
	private LanguageRepository $languageRepository;

	/**
	 * Meta key used to mark menu items as language switcher items.
	 *
	 * @var string
	 */
	const META_KEY = '_polyglot_menu_language';

	/**
	 * Constructor.
	 *
	 * @param LanguageSwitcher    $switcher            The render service.
	 * @param LanguageRepository  $languageRepository  Language data access.
	 */
	public function __construct( LanguageSwitcher $switcher, LanguageRepository $languageRepository ) {
		$this->switcher           = $switcher;
		$this->languageRepository = $languageRepository;
	}

	/**
	 * Register WordPress hooks for nav menu integration.
	 *
	 * @return void
	 */
	public function register(): void {
		// Add meta box to the menu editor screen.
		add_action( 'admin_init', array( $this, 'registerMetaBox' ) );

		// Save the language code when a menu item is saved.
		add_action( 'wp_update_nav_menu_item', array( $this, 'saveMenuItemMeta' ), 10, 3 );

		// Filter menu items on the frontend to show correct translated URLs.
		add_filter( 'wp_setup_nav_menu_item', array( $this, 'setupNavItem' ) );

		// AJAX handler for adding language items from the meta box.
		add_action( 'wp_ajax_polyglot_add_menu_item', array( $this, 'ajaxAddMenuItem' ) );
	}

	/**
	 * Register the "PolyGlot Languages" meta box on the nav menu editor.
	 *
	 * @return void
	 */
	public function registerMetaBox(): void {
		add_meta_box(
			'polyglot-languages-meta-box',
			__( 'PolyGlot Languages', 'novatools-polyglot' ),
			array( $this, 'renderMetaBox' ),
			'nav-menus',
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box content in the menu editor.
	 *
	 * Displays active languages as checkboxes that can be added to the menu.
	 *
	 * @return void
	 */
	public function renderMetaBox(): void {
		$languages = $this->languageRepository->getActive();

		if ( empty( $languages ) ) {
			echo '<p>' . esc_html__( 'No active languages found.', 'novatools-polyglot' ) . '</p>';
			return;
		}

		?>
		<div id="polyglot-language-menu-items" class="categorydiv">
			<ul class="polyglot-language-checklist">
				<?php foreach ( $languages as $lang ) : ?>
					<li>
						<label>
							<input
								type="checkbox"
								class="polyglot-menu-language-item"
								value="<?php echo esc_attr( $lang->code ); ?>"
								data-name="<?php echo esc_attr( $lang->nativeName ); ?>"
							/>
							<?php echo esc_html( $lang->nativeName ); ?>
							<span class="polyglot-lang-code">(<?php echo esc_html( $lang->code ); ?>)</span>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="button-controls">
				<span class="add-to-menu">
					<input
						type="button"
						class="button-secondary right"
						id="polyglot-add-to-menu"
						value="<?php esc_attr_e( 'Add to Menu', 'novatools-polyglot' ); ?>"
						data-wp-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'polyglot-menu-nonce' ) ); ?>"
					/>
					<span class="spinner"></span>
				</span>
			</p>
		</div>
		<script>
		(function($) {
			$('#polyglot-add-to-menu').on('click', function() {
				var button = $(this);
				var spinner = button.next('.spinner');
				var items = [];

				button.closest('div').find('.polyglot-menu-language-item:checked').each(function() {
					items.push({
						code: $(this).val(),
						name: $(this).data('name')
					});
				});

				if (!items.length) return;

				spinner.addClass('is-active');
				button.prop('disabled', true);

				$.post(button.data('wp-ajax'), {
					action: 'polyglot_add_menu_item',
					items: items,
					menu: $('#menu').val(),
					nonce: button.data('nonce')
				}).done(function(response) {
					if (response.success) {
						// Refresh the menu editor.
						wpNavMenu.refreshMenuCount();
						// Re-load the menu items.
						$('#menu-to-edit').load(ajaxurl + '?action=menu-get-menus&menu=' + $('#menu').val(), function() {
							wpNavMenu.initSortables();
						});
					}
				}).always(function() {
					spinner.removeClass('is-active');
					button.prop('disabled', false);
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * AJAX handler for adding language items to a menu.
	 *
	 * Creates a custom link menu item for each selected language.
	 *
	 * @return void
	 */
	public function ajaxAddMenuItem(): void {
		check_ajax_referer( 'polyglot-menu-nonce', 'nonce' );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'novatools-polyglot' ) ) );
		}

		$menu_id = isset( $_POST['menu'] ) ? absint( $_POST['menu'] ) : 0;
		$items   = isset( $_POST['items'] ) ? (array) $_POST['items'] : array();

		if ( ! $menu_id || empty( $items ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'novatools-polyglot' ) ) );
		}

		$added = array();

		foreach ( $items as $item ) {
			$code = sanitize_text_field( $item['code'] ?? '' );
			$name = sanitize_text_field( $item['name'] ?? '' );

			if ( '' === $code ) {
				continue;
			}

			// Create a custom link menu item pointing to home URL in that language.
			$menu_item_id = wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'  => $name ?: $code,
					'menu-item-url'    => polyglot_home_url( $code ),
					'menu-item-status' => 'publish',
					'menu-item-type'   => 'custom',
				)
			);

			if ( $menu_item_id && ! is_wp_error( $menu_item_id ) ) {
				// Store the language code as meta to identify this as a language item.
				update_post_meta( $menu_item_id, self::META_KEY, $code );
				$added[] = $menu_item_id;
			}
		}

		wp_send_json_success( array( 'added' => count( $added ) ) );
	}

	/**
	 * Save the language code meta when a nav menu item is updated.
	 *
	 * @param int   $menu_id         The menu ID.
	 * @param int   $menu_item_db_id The menu item ID.
	 * @param array $args            The menu item arguments.
	 * @return void
	 */
	public function saveMenuItemMeta( int $menu_id, int $menu_item_db_id, array $args ): void {
		if ( isset( $_POST['polyglot_menu_language'][ $menu_item_db_id ] ) ) {
			$code = sanitize_text_field( $_POST['polyglot_menu_language'][ $menu_item_db_id ] );

			if ( '' !== $code ) {
				update_post_meta( $menu_item_db_id, self::META_KEY, $code );
			} else {
				delete_post_meta( $menu_item_db_id, self::META_KEY );
			}
		}
	}

	/**
	 * Filter nav menu items on the frontend.
	 *
	 * For menu items with the PolyGlot language meta key, replaces the URL
	 * with the translated URL for the current page in that language, and
	 * adds CSS classes for current-language highlighting.
	 *
	 * @param \WP_Post $menu_item The menu item object.
	 * @return \WP_Post The modified menu item.
	 */
	public function setupNavItem( \WP_Post $menu_item ): \WP_Post {
		// Only process on the frontend.
		if ( is_admin() ) {
			return $menu_item;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$language_code = get_post_meta( $menu_item->ID, self::META_KEY, true );

		if ( ! $language_code ) {
			return $menu_item;
		}

		$current = polyglot_get_current_language();

		// Replace the URL with the translated URL for this page.
		$menu_item->url = $this->switcher->getTranslatedUrl( $language_code );

		// Mark the item as a PolyGlot language item.
		$classes = (array) $menu_item->classes;
		$classes[] = 'polyglot-menu-language';
		$classes[] = 'polyglot-menu-language-' . $language_code;

		// Mark current language.
		if ( $language_code === $current ) {
			$classes[] = 'polyglot-menu-language-current';
			$classes[] = 'current-language-item';
		}

		$menu_item->classes = array_unique( array_filter( $classes ) );

		return $menu_item;
	}
}
