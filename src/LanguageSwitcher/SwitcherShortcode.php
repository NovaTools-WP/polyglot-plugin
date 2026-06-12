<?php
/**
 * Language switcher shortcode for NovaTools Polyglot.
 *
 * Registers the `[polyglot_switcher]` shortcode that renders a language
 * switcher anywhere shortcodes are supported. Supports attributes for
 * format, flag display, name display, and language exclusion.
 *
 * Usage:
 *   [polyglot_switcher]
 *   [polyglot_switcher format="dropdown" show_flags="true"]
 *   [polyglot_switcher format="list" show_names="false" exclude="de,ja"]
 *
 * @package NovaTools\Polyglot\LanguageSwitcher
 */

namespace NovaTools\Polyglot\LanguageSwitcher;

defined( 'ABSPATH' ) || exit;

class SwitcherShortcode {

	use SwitcherHelpers;

	/**
	 * The central language switcher renderer.
	 *
	 * @var LanguageSwitcher
	 */
	private LanguageSwitcher $switcher;

	/**
	 * Constructor.
	 *
	 * @param LanguageSwitcher $switcher The render service.
	 */
	public function __construct( LanguageSwitcher $switcher ) {
		$this->switcher = $switcher;
	}

	/**
	 * Register the shortcode with WordPress.
	 *
	 * Should be called during the 'init' action.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'polyglot_switcher', array( $this, 'render' ) );
	}

	/**
	 * Shortcode callback. Renders the language switcher.
	 *
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Shortcode enclosed content (unused).
	 * @param string $tag     Shortcode tag name.
	 * @return string HTML output of the language switcher.
	 */
	public function render( array $atts = array(), string $content = '', string $tag = '' ): string {
		$defaults = array(
			'format'     => 'list',
			'show_flags' => 'true',
			'show_names' => 'true',
			'exclude'    => '',
		);

		$atts = shortcode_atts( $defaults, $atts, 'polyglot_switcher' );

		$switcher_args = array(
			'format'     => $this->sanitizeFormat( $atts['format'] ),
			'show_flags' => $this->parseBool( $atts['show_flags'] ),
			'show_names' => $this->parseBool( $atts['show_names'] ),
			'exclude'    => $this->parseExclude( $atts['exclude'] ),
		);

		/**
		 * Filter the language switcher shortcode arguments before rendering.
		 *
		 * @param array  $switcher_args Arguments for the renderer.
		 * @param array  $atts          Raw shortcode attributes.
		 * @param string $content       Shortcode enclosed content.
		 */
		$switcher_args = apply_filters( 'polyglot_switcher_shortcode_args', $switcher_args, $atts, $content );

		return $this->switcher->render( $switcher_args );
	}

	/**
	 * Sanitize the format attribute value.
	 *
	 * @param string $format Raw format value.
	 * @return string Either 'dropdown' or 'list'.
	 */
	private function sanitizeFormat( string $format ): string {
		$allowed = array( 'dropdown', 'list' );

		return in_array( $format, $allowed, true ) ? $format : 'list';
	}

	/**
	 * Parse a boolean-like string attribute.
	 *
	 * Accepts: 'true', '1', 'yes', 'on' → true
	 * Everything else → false
	 *
	 * @param string $value Raw attribute value.
	 * @return bool
	 */
	private function parseBool( string $value ): bool {
		return in_array( strtolower( $value ), array( 'true', '1', 'yes', 'on' ), true );
	}
}
