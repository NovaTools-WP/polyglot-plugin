<?php
/**
 * Currency switcher for NovaTools Polyglot.
 *
 * Provides a currency switcher in three flavours: a classic WordPress widget,
 * the `[polyglot_currency_switcher]` shortcode, and a dynamic Gutenberg block.
 * Each renders a dropdown of enabled currencies; selection is submitted as the
 * `polyglot_currency` request param, which CurrencyManager persists.
 *
 * @package NovaTools\Polyglot\WooCommerce\Currency
 */

namespace NovaTools\Polyglot\WooCommerce\Currency;

use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class CurrencySwitcher {

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE = 'polyglot_currency_switcher';

	/**
	 * Gutenberg block name.
	 */
	const BLOCK_NAME = 'novatools-polyglot/currency-switcher';

	/**
	 * Currency manager.
	 *
	 * @var CurrencyManager
	 */
	private CurrencyManager $manager;

	/**
	 * Option store.
	 *
	 * @var OptionStore
	 */
	private OptionStore $options;

	/**
	 * Constructor.
	 *
	 * @param CurrencyManager $manager Currency manager.
	 * @param OptionStore     $options Option store.
	 */
	public function __construct( CurrencyManager $manager, OptionStore $options ) {
		$this->manager = $manager;
		$this->options = $options;
	}

	/**
	 * Register the widget, shortcode, and Gutenberg block.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'widgets_init', array( $this, 'registerWidget' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'renderShortcode' ) );
		add_action( 'init', array( $this, 'registerBlock' ) );
	}

	/**
	 * Register the currency switcher widget.
	 *
	 * @return void
	 */
	public function registerWidget(): void {
		register_widget( new CurrencySwitcherWidget( $this ) );
	}

	/**
	 * Register the Gutenberg block as a dynamic block.
	 *
	 * @return void
	 */
	public function registerBlock(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type( self::BLOCK_NAME, array(
			'attributes'      => array(
				'showSymbols' => array( 'type' => 'boolean', 'default' => true ),
				'showNames'   => array( 'type' => 'boolean', 'default' => false ),
				'format'      => array( 'type' => 'string', 'default' => 'dropdown' ),
			),
			'render_callback' => array( $this, 'renderBlock' ),
		) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array $atts Shortcode attributes: format, show_symbols, show_names.
	 * @return string Switcher HTML.
	 */
	public function renderShortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'format'      => 'dropdown',
				'show_symbols'=> 'true',
				'show_names'  => 'false',
			),
			$atts,
			self::SHORTCODE
		);

		return $this->render( array(
			'format'       => $atts['format'],
			'show_symbols' => filter_var( $atts['show_symbols'], FILTER_VALIDATE_BOOLEAN ),
			'show_names'   => filter_var( $atts['show_names'], FILTER_VALIDATE_BOOLEAN ),
		) );
	}

	/**
	 * Render the Gutenberg block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Switcher HTML.
	 */
	public function renderBlock( array $attributes ): string {
		return $this->render( array(
			'format'       => $attributes['format'] ?? 'dropdown',
			'show_symbols' => $attributes['showSymbols'] ?? true,
			'show_names'   => $attributes['showNames'] ?? false,
		) );
	}

	/**
	 * Render the currency switcher markup.
	 *
	 * @param array $args {
	 *     Render options.
	 *
	 *     @type string $format       "dropdown" or "list". Default "dropdown".
	 *     @type bool   $show_symbols Whether to prefix options with the symbol.
	 *     @type bool   $show_names   Whether to append the currency name.
	 * }
	 * @return string Switcher HTML.
	 */
	public function render( array $args = array() ): string {
		$args = wp_parse_args( $args, array(
			'format'       => 'dropdown',
			'show_symbols' => true,
			'show_names'   => false,
		) );

		$currencies = $this->manager->getEnabledCurrencies();

		if ( empty( $currencies ) ) {
			return '';
		}

		$active   = $this->manager->getActiveCurrency();
		$symbols  = function_exists( 'get_woocommerce_currency_symbols' ) ? get_woocommerce_currency_symbols() : array();
		$names    = $this->getCurrencyNames();
		$action   = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );

		if ( 'list' === $args['format'] ) {
			return $this->renderList( $currencies, $active, $symbols, $names, $args );
		}

		ob_start();
		?>
		<form class="polyglot-currency-switcher polyglot-currency-switcher--dropdown" method="get" action="<?php echo esc_url( $action ); ?>">
			<label for="polyglot-currency-select" class="screen-reader-text">
				<?php esc_html_e( 'Select currency', 'novatools-polyglot' ); ?>
			</label>
			<select id="polyglot-currency-select" name="polyglot_currency" onchange="this.form.submit()">
				<?php foreach ( $currencies as $currency ) : ?>
					<option value="<?php echo esc_attr( $currency ); ?>" <?php selected( $active, $currency ); ?>>
						<?php echo esc_html( $this->formatLabel( $currency, $symbols, $names, $args ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the switcher as an unordered list of currency links.
	 *
	 * @param string[] $currencies Enabled currency codes.
	 * @param string   $active     Active currency code.
	 * @param array    $symbols    Map of currency => symbol.
	 * @param array    $names      Map of currency => name.
	 * @param array    $args       Render options.
	 * @return string
	 */
	private function renderList( array $currencies, string $active, array $symbols, array $names, array $args ): string {
		ob_start();
		?>
		<ul class="polyglot-currency-switcher polyglot-currency-switcher--list">
			<?php foreach ( $currencies as $currency ) : ?>
				<li class="<?php echo esc_attr( $active === $currency ? 'polyglot-currency-switcher__active' : '' ); ?>">
					<a href="<?php echo esc_url( add_query_arg( 'polyglot_currency', $currency ) ); ?>">
						<?php echo esc_html( $this->formatLabel( $currency, $symbols, $names, $args ) ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		return ob_get_clean();
	}

	/**
	 * Format the label for a single currency option.
	 *
	 * @param string $currency  Currency code.
	 * @param array  $symbols   Map of currency => symbol.
	 * @param array  $names     Map of currency => name.
	 * @param array  $args      Render options.
	 * @return string
	 */
	private function formatLabel( string $currency, array $symbols, array $names, array $args ): string {
		$parts = array( $currency );

		if ( ! empty( $args['show_symbols'] ) && ! empty( $symbols[ $currency ] ) ) {
			array_unshift( $parts, $symbols[ $currency ] );
		}

		if ( ! empty( $args['show_names'] ) && ! empty( $names[ $currency ] ) ) {
			$parts[] = '(' . $names[ $currency ] . ')';
		}

		return implode( ' ', $parts );
	}

	/**
	 * Get a map of currency code => human-readable name.
	 *
	 * @return array
	 */
	private function getCurrencyNames(): array {
		// WooCommerce ships a full currency list keyed by code.
		if ( function_exists( 'get_woocommerce_currencies' ) ) {
			return get_woocommerce_currencies();
		}

		return array();
	}
}

/**
 * WordPress widget wrapper for the currency switcher.
 *
 * Delegates rendering to the parent CurrencySwitcher instance so the widget,
 * shortcode, and block share identical markup.
 */
class CurrencySwitcherWidget extends \WP_Widget {

	/**
	 * The switcher renderer.
	 *
	 * @var CurrencySwitcher
	 */
	private CurrencySwitcher $switcher;

	/**
	 * Constructor.
	 *
	 * @param CurrencySwitcher $switcher The switcher renderer.
	 */
	public function __construct( CurrencySwitcher $switcher ) {
		$this->switcher = $switcher;

		parent::__construct(
			'polyglot_currency_switcher',
			__( 'Polyglot Currency Switcher', 'novatools-polyglot' ),
			array(
				'description' => __( 'Lets customers switch the active WooCommerce currency.', 'novatools-polyglot' ),
			)
		);
	}

	/**
	 * Render the widget on the frontend.
	 *
	 * @param array $args     Display arguments.
	 * @param array $instance Widget settings.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		$title = apply_filters( 'widget_title', $instance['title'] ?? '', $instance, $this->id_base );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo $this->switcher->render( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'format'       => $instance['format'] ?? 'dropdown',
			'show_symbols' => filter_var( $instance['show_symbols'] ?? true, FILTER_VALIDATE_BOOLEAN ),
			'show_names'   => filter_var( $instance['show_names'] ?? false, FILTER_VALIDATE_BOOLEAN ),
		) );

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render the widget settings form.
	 *
	 * @param array $instance Current settings.
	 * @return void
	 */
	public function form( $instance ) {
		$title        = $instance['title'] ?? '';
		$format       = $instance['format'] ?? 'dropdown';
		$show_symbols = isset( $instance['show_symbols'] ) ? (bool) $instance['show_symbols'] : true;
		$show_names   = isset( $instance['show_names'] ) ? (bool) $instance['show_names'] : false;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title:', 'novatools-polyglot' ); ?>
			</label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'format' ) ); ?>">
				<?php esc_html_e( 'Format:', 'novatools-polyglot' ); ?>
			</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'format' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'format' ) ); ?>">
				<option value="dropdown" <?php selected( $format, 'dropdown' ); ?>><?php esc_html_e( 'Dropdown', 'novatools-polyglot' ); ?></option>
				<option value="list" <?php selected( $format, 'list' ); ?>><?php esc_html_e( 'List', 'novatools-polyglot' ); ?></option>
			</select>
		</p>
		<p>
			<input class="checkbox" type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_symbols' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_symbols' ) ); ?>" <?php checked( $show_symbols ); ?> />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_symbols' ) ); ?>"><?php esc_html_e( 'Show currency symbols', 'novatools-polyglot' ); ?></label>
		</p>
		<p>
			<input class="checkbox" type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_names' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_names' ) ); ?>" <?php checked( $show_names ); ?> />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_names' ) ); ?>"><?php esc_html_e( 'Show currency names', 'novatools-polyglot' ); ?></label>
		</p>
		<?php
	}

	/**
	 * Sanitise and save widget settings.
	 *
	 * @param array $new_instance New settings.
	 * @param array $old_instance Old settings.
	 * @return array Sanitised settings.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array();

		$instance['title']        = sanitize_text_field( $new_instance['title'] ?? '' );
		$instance['format']       = in_array( $new_instance['format'] ?? '', array( 'dropdown', 'list' ), true ) ? $new_instance['format'] : 'dropdown';
		$instance['show_symbols'] = isset( $new_instance['show_symbols'] );
		$instance['show_names']   = isset( $new_instance['show_names'] );

		return $instance;
	}
}
