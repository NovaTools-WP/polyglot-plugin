<?php
/**
 * Language switcher widget for NovaTools Polyglot.
 *
 * Extends WP_Widget to provide a drag-and-drop language switcher for
 * any widget area. Delegates rendering to the central LanguageSwitcher
 * service for consistent output across all switcher contexts.
 *
 * @package NovaTools\Polyglot\LanguageSwitcher
 */

namespace NovaTools\Polyglot\LanguageSwitcher;

defined( 'ABSPATH' ) || exit;

class SwitcherWidget extends \WP_Widget {

	use SwitcherHelpers;

	/**
	 * Widget slug / ID base.
	 *
	 * @var string
	 */
	const ID_BASE = 'polyglot_language_switcher';

	/**
	 * Constructor. Sets up the widget description and options.
	 */
	public function __construct() {
		parent::__construct(
			self::ID_BASE,
			__( 'PolyGlot Language Switcher', 'novatools-polyglot' ),
			array(
				'classname'   => 'polyglot-switcher-widget',
				'description' => __( 'Display a language switcher with flags and language names.', 'novatools-polyglot' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	/**
	 * Front-end display of the widget.
	 *
	 * @param array $args     Display arguments including 'before_title', 'after_title',
	 *                        'before_widget', and 'after_widget'.
	 * @param array $instance The settings for the particular instance of the widget.
	 * @return void
	 */
	public function widget( $args, $instance ): void {
		// Only show on frontend.
		if ( is_admin() ) {
			return;
		}

		$title = ! empty( $instance['title'] ) ? $instance['title'] : '';

		/** This filter is documented in wp-includes/widgets/class-wp-widget-pages.php */
		$title = apply_filters( 'widget_title', $title, $instance, $this->id_base );

		$switcher_args = array(
			'format'     => $instance['format'] ?? 'list',
			'show_flags' => ! empty( $instance['show_flags'] ),
			'show_names' => ! empty( $instance['show_names'] ),
			'exclude'    => $this->parseExclude( $instance['exclude'] ?? '' ),
			'css_class'  => 'polyglot-switcher-widget',
		);

		/**
		 * Filter the language switcher widget arguments before rendering.
		 *
		 * @param array $switcher_args Arguments passed to the renderer.
		 * @param array $instance      Widget instance settings.
		 * @param array $args          Widget display arguments.
		 */
		$switcher_args = apply_filters( 'polyglot_switcher_widget_args', $switcher_args, $instance, $args );

		$html = $this->getSwitcher()->render( $switcher_args );

		if ( '' === $html ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by LanguageSwitcher with escaping.

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Back-end widget form.
	 *
	 * @param array $instance Current settings.
	 * @return void
	 */
	public function form( $instance ): void {
		$title      = isset( $instance['title'] ) ? $instance['title'] : '';
		$format     = isset( $instance['format'] ) ? $instance['format'] : 'list';
		$show_flags = isset( $instance['show_flags'] ) ? (bool) $instance['show_flags'] : true;
		$show_names = isset( $instance['show_names'] ) ? (bool) $instance['show_names'] : true;
		$exclude    = isset( $instance['exclude'] ) ? $instance['exclude'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title:', 'novatools-polyglot' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $title ); ?>"
			/>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'format' ) ); ?>">
				<?php esc_html_e( 'Format:', 'novatools-polyglot' ); ?>
			</label>
			<select
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'format' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'format' ) ); ?>"
			>
				<option value="list" <?php selected( $format, 'list' ); ?>>
					<?php esc_html_e( 'List', 'novatools-polyglot' ); ?>
				</option>
				<option value="dropdown" <?php selected( $format, 'dropdown' ); ?>>
					<?php esc_html_e( 'Dropdown', 'novatools-polyglot' ); ?>
				</option>
			</select>
		</p>
		<p>
			<input
				id="<?php echo esc_attr( $this->get_field_id( 'show_flags' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_flags' ) ); ?>"
				type="checkbox"
				<?php checked( $show_flags ); ?>
			/>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_flags' ) ); ?>">
				<?php esc_html_e( 'Show flags', 'novatools-polyglot' ); ?>
			</label>
		</p>
		<p>
			<input
				id="<?php echo esc_attr( $this->get_field_id( 'show_names' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_names' ) ); ?>"
				type="checkbox"
				<?php checked( $show_names ); ?>
			/>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_names' ) ); ?>">
				<?php esc_html_e( 'Show language names', 'novatools-polyglot' ); ?>
			</label>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'exclude' ) ); ?>">
				<?php esc_html_e( 'Exclude languages (comma-separated codes):', 'novatools-polyglot' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'exclude' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'exclude' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $exclude ); ?>"
				placeholder="e.g. de,ja"
			/>
		</p>
		<?php
	}

	/**
	 * Sanitize widget form values as they are saved.
	 *
	 * @param array $new_instance Values just sent to be saved.
	 * @param array $old_instance Previously saved values.
	 * @return array Updated safe values to be saved.
	 */
	public function update( $new_instance, $old_instance ): array {
		$instance = array();

		$instance['title']      = sanitize_text_field( $new_instance['title'] ?? '' );
		$instance['format']     = in_array( $new_instance['format'] ?? '', array( 'list', 'dropdown' ), true )
			? $new_instance['format']
			: 'list';
		$instance['show_flags'] = ! empty( $new_instance['show_flags'] );
		$instance['show_names'] = ! empty( $new_instance['show_names'] );
		$instance['exclude']    = sanitize_text_field( $new_instance['exclude'] ?? '' );

		return $instance;
	}

	/**
	 * Register this widget with WordPress.
	 *
	 * Should be called during the 'widgets_init' action.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_widget( static::class );
	}

	/**
	 * Get the LanguageSwitcher service instance.
	 *
	 * @return LanguageSwitcher
	 */
	private function getSwitcher(): LanguageSwitcher {
		static $switcher = null;

		if ( null === $switcher ) {
			try {
				$plugin   = \NovaTools\Polyglot\Core\Plugin::getInstance();
				$switcher = $plugin->get( 'language_switcher' );
			} catch ( \Throwable ) {
				$switcher = new LanguageSwitcher();
			}
		}

		return $switcher;
	}
}
