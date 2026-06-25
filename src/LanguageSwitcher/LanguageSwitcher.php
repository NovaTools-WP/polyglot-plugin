<?php
/**
 * Language switcher render engine for NovaTools Polyglot.
 *
 * Central class responsible for building the language switcher data model
 * (active languages with URLs, flags, names, current-language indicator)
 * and rendering it in either dropdown or list format using overridable
 * template files.
 *
 * Templates are discovered in the following priority order:
 *   1. Theme:     wp-content/themes/{theme}/polyglot/language-switcher/
 *   2. Plugin:    wp-content/plugins/novatools-polyglot/templates/language-switcher/
 *
 * @package NovaTools\Polyglot\LanguageSwitcher
 */

namespace NovaTools\Polyglot\LanguageSwitcher;

use NovaTools\Polyglot\Core\Plugin;
use NovaTools\Polyglot\Language\FlagResolver;
use NovaTools\Polyglot\Language\Language;
use NovaTools\Polyglot\Language\LanguageRepository;
use NovaTools\Polyglot\Translation\ContentTranslator;
use NovaTools\Polyglot\Url\UrlConverter;

defined( 'ABSPATH' ) || exit;

class LanguageSwitcher {

	/**
	 * CSS class prefix for all switcher markup.
	 *
	 * @var string
	 */
	const CSS_PREFIX = 'polyglot-switcher';

	/**
	 * Build the data model for the language switcher.
	 *
	 * Returns an array of language items, each containing:
	 *   - code         Language code (e.g. "en").
	 *   - locale       Full WordPress locale (e.g. "en_US").
	 *   - native_name  Name in the language itself.
	 *   - english_name Name in English.
	 *   - flag_url     URL to the flag image (empty string if none).
	 *   - url          URL to the current page in that language (or home URL).
	 *   - is_current   Whether this language matches the current request.
	 *   - direction    Text direction ("ltr" or "rtl").
	 *
	 * @param array $args {
	 *     Optional. Switcher configuration.
	 *
	 *     @type string[] $exclude Language codes to exclude from the list.
	 * }
	 * @return array[] Array of language item associative arrays.
	 */
	public function getModel( array $args = array() ): array {
		$exclude = $args['exclude'] ?? array();
		$current = polyglot_get_current_language();

		try {
			$plugin = Plugin::getInstance();

			/** @var LanguageRepository $repo */
			$repo     = $plugin->get( 'language.repository' );
			$languages = $repo->getActive();
		} catch ( \Throwable ) {
			return array();
		}

		if ( empty( $languages ) ) {
			return array();
		}

		$items = array();

		foreach ( $languages as $lang ) {
			// Skip excluded languages.
			if ( in_array( $lang->code, $exclude, true ) ) {
				continue;
			}

			$items[] = array(
				'code'         => $lang->code,
				'locale'       => $lang->locale,
				'native_name'  => $lang->nativeName,
				'english_name' => $lang->englishName,
				'flag_url'     => $this->getFlagUrl( $lang ),
				'url'          => $this->getTranslatedUrl( $lang->code ),
				'is_current'   => $lang->code === $current,
				'direction'    => $lang->direction,
			);
		}

		return $items;
	}

	/**
	 * Render the language switcher.
	 *
	 * Builds the model and delegates to a template file based on format.
	 * Supported formats: 'dropdown', 'list'.
	 *
	 * @param array $args {
	 *     Switcher configuration.
	 *
	 *     @type string   $format     Output format: 'dropdown' or 'list'. Default 'list'.
	 *     @type bool     $show_flags Whether to display flag images. Default true.
	 *     @type bool     $show_names Whether to display language names. Default true.
	 *     @type string[] $exclude    Language codes to exclude. Default empty.
	 *     @type string   $css_class  Additional CSS class for the wrapper element.
	 * }
	 * @return string HTML output.
	 */
	public function render( array $args = array() ): string {
		$defaults = array(
			'format'     => 'list',
			'show_flags' => true,
			'show_names' => true,
			'exclude'    => array(),
			'css_class'  => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$items = $this->getModel( array( 'exclude' => $args['exclude'] ) );

		if ( empty( $items ) ) {
			return '';
		}

		// Build template variables.
		$vars = array(
			'items'       => $items,
			'show_flags'  => (bool) $args['show_flags'],
			'show_names'  => (bool) $args['show_names'],
			'css_prefix'  => self::CSS_PREFIX,
			'css_classes' => $this->buildCssClasses( $args ),
			'current'     => polyglot_get_current_language(),
		);

		/**
		 * Filter the language switcher template variables before rendering.
		 *
		 * @param array $vars Template variables.
		 * @param array $args Original switcher configuration.
		 */
		$vars = apply_filters( 'polyglot_switcher_template_vars', $vars, $args );

		return $this->loadTemplate( $args['format'], $vars );
	}

	/**
	 * Get the URL to a language flag image.
	 *
	 * Flags are served from the plugin's assets/images/flags/ directory.
	 * Falls back to an empty string when no flag file exists.
	 *
	 * @param Language $lang The language object.
	 * @return string Flag image URL, or empty string.
	 */
	public function getFlagUrl( Language $lang ): string {
		// Resolve the flag from the language code (not the stored flag_code),
		// so the correct country flag is used (e.g. "et" → "ee.png").
		$country = strtolower( FlagResolver::countryCode( $lang->code ) );

		if ( '' === $country ) {
			return '';
		}

		$flag_file = $country . '.png';
		$path      = NOVATOOLS_POLYGLOT_DIR . 'assets/images/flags/' . $flag_file;

		if ( file_exists( $path ) ) {
			return NOVATOOLS_POLYGLOT_ASSETS_URL . '/images/flags/' . $flag_file;
		}

		return '';
	}

	/**
	 * Get the translated URL for a given language code.
	 *
	 * On the frontend, attempts to resolve the current post/term/object
	 * translation URL. Falls back to the language home URL.
	 *
	 * @param string $language_code Target language code.
	 * @return string Translated URL.
	 */
	public function getTranslatedUrl( string $language_code ): string {
		global $wp;

		// On singular pages, try to link to the translated post.
		if ( is_singular() ) {
			$post_id   = get_the_ID();
			$post_type = get_post_type( $post_id );

			if ( $post_type ) {
				$element_type = 'post_' . $post_type;
				$translated   = polyglot_translate_object( $post_id, $element_type, $language_code );

				if ( $translated ) {
					$permalink = get_permalink( $translated );

					if ( $permalink ) {
						return polyglot_url( $permalink, $language_code );
					}
				}
			}
		}

		// On taxonomy archives, try to link to the translated term.
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			if ( $term instanceof \WP_Term ) {
				$type       = 'tax_' . $term->taxonomy;
				$translated = polyglot_translate_object( $term->term_id, $type, $language_code );

				if ( $translated ) {
					$link = get_term_link( $translated, $term->taxonomy );

					if ( ! is_wp_error( $link ) ) {
						return polyglot_url( $link, $language_code );
					}
				}
			}
		}

		// Fallback: convert the current URL to the target language.
		$current_url = home_url( add_query_arg( [], $wp->request ?? '/' ) );

		$converted = polyglot_url( esc_url_raw( $current_url ), $language_code );

		if ( $converted && $converted !== $current_url ) {
			return $converted;
		}

		// Final fallback: home URL for the language.
		return polyglot_home_url( $language_code );
	}

	/**
	 * Load a template file for rendering.
	 *
	 * Checks the theme override path first, then falls back to the
	 * plugin's built-in templates.
	 *
	 * @param string $format Template format name (e.g. 'dropdown', 'list').
	 * @param array  $vars   Template variables to extract.
	 * @return string Rendered HTML.
	 */
	private function loadTemplate( string $format, array $vars ): string {
		$template_names = array(
			"language-switcher/{$format}.php",
		);

		// Try theme override first.
		$theme_template = locate_template(
			array( "polyglot/language-switcher/{$format}.php" )
		);

		if ( $theme_template ) {
			return $this->renderTemplate( $theme_template, $vars );
		}

		// Fall back to plugin template.
		$plugin_template = NOVATOOLS_POLYGLOT_DIR . "templates/language-switcher/{$format}.php";

		if ( file_exists( $plugin_template ) ) {
			return $this->renderTemplate( $plugin_template, $vars );
		}

		// Ultimate fallback: inline render.
		return $this->renderFallback( $vars, $format );
	}

	/**
	 * Render a template file by extracting variables and including it.
	 *
	 * Output buffering captures the template output.
	 *
	 * @param string $path Absolute path to the template file.
	 * @param array  $vars Variables to extract into scope.
	 * @return string Rendered HTML.
	 */
	private function renderTemplate( string $path, array $vars ): string {
		extract( $vars, EXTR_SKIP );

		ob_start();
		include $path;

		return ob_get_clean();
	}

	/**
	 * Inline fallback renderer when no template file exists.
	 *
	 * @param array  $vars  Template variables.
	 * @param string $format Format type.
	 * @return string HTML output.
	 */
	private function renderFallback( array $vars, string $format ): string {
		$items      = $vars['items'];
		$show_flags = $vars['show_flags'];
		$show_names = $vars['show_names'];
		$css_prefix = $vars['css_prefix'];
		$css_class  = $vars['css_classes'];

		if ( 'dropdown' === $format ) {
			return $this->renderFallbackDropdown( $items, $show_flags, $show_names, $css_prefix, $css_class );
		}

		return $this->renderFallbackList( $items, $show_flags, $show_names, $css_prefix, $css_class );
	}

	/**
	 * Render a dropdown switcher as inline fallback.
	 *
	 * @param array[] $items      Language items.
	 * @param bool    $show_flags Whether to show flags.
	 * @param bool    $show_names Whether to show names.
	 * @param string  $css_prefix CSS prefix.
	 * @param string  $css_class  Additional CSS class.
	 * @return string HTML.
	 */
	private function renderFallbackDropdown(
		array $items,
		bool $show_flags,
		bool $show_names,
		string $css_prefix,
		string $css_class
	): string {
		$html = sprintf(
			'<select class="%s-dropdown %s" data-polyglot-switcher="dropdown">',
			esc_attr( $css_prefix ),
			esc_attr( $css_class )
		);

		foreach ( $items as $item ) {
			$label = '';

			if ( $show_names ) {
				$label = $item['native_name'];
			}

			if ( $show_names && $show_flags ) {
				// Flags in dropdown are shown via data attribute.
			}

			$html .= sprintf(
				'<option value="%s" data-flag="%s"%s>%s</option>',
				esc_url( $item['url'] ),
				esc_url( $item['flag_url'] ),
				$item['is_current'] ? ' selected' : '',
				esc_html( $label ?: $item['code'] )
			);
		}

		$html .= '</select>';

		// Inline JS for dropdown navigation.
		$html .= sprintf(
			'<script>(function(){var w=document.currentScript.parentElement;var s=w?w.querySelector("[data-polyglot-switcher=dropdown]"):null;if(s)s.addEventListener("change",function(){window.location.href=this.value})})();</script>'
		);

		return $html;
	}

	/**
	 * Render a list switcher as inline fallback.
	 *
	 * @param array[] $items      Language items.
	 * @param bool    $show_flags Whether to show flags.
	 * @param bool    $show_names Whether to show names.
	 * @param string  $css_prefix CSS prefix.
	 * @param string  $css_class  Additional CSS class.
	 * @return string HTML.
	 */
	private function renderFallbackList(
		array $items,
		bool $show_flags,
		bool $show_names,
		string $css_prefix,
		string $css_class
	): string {
		$html = sprintf(
			'<ul class="%s-list %s">',
			esc_attr( $css_prefix ),
			esc_attr( $css_class )
		);

		foreach ( $items as $item ) {
			$classes = $css_prefix . '-item';

			if ( $item['is_current'] ) {
				$classes .= ' ' . $css_prefix . '-current';
			}

			$html .= sprintf( '<li class="%s">', esc_attr( $classes ) );

			if ( $item['is_current'] ) {
				$html .= '<span class="' . esc_attr( $css_prefix . '-link ' . $css_prefix . '-link--current' ) . '">';
			} else {
				$html .= sprintf(
					'<a href="%s" class="%s">',
					esc_url( $item['url'] ),
					esc_attr( $css_prefix . '-link' )
				);
			}

			if ( $show_flags && $item['flag_url'] ) {
				$html .= sprintf(
					'<img src="%s" alt="%s" class="%s-flag" width="18" height="12" />',
					esc_url( $item['flag_url'] ),
					esc_attr( $item['english_name'] ),
					esc_attr( $css_prefix )
				);
			}

			if ( $show_names ) {
				$html .= sprintf(
					'<span class="%s-name">%s</span>',
					esc_attr( $css_prefix ),
					esc_html( $item['native_name'] )
				);
			}

			$html .= $item['is_current'] ? '</span>' : '</a>';
			$html .= '</li>';
		}

		$html .= '</ul>';

		return $html;
	}

	/**
	 * Build CSS class string for the switcher wrapper.
	 *
	 * @param array $args Switcher configuration.
	 * @return string Space-separated CSS classes.
	 */
	private function buildCssClasses( array $args ): string {
		$classes = array( self::CSS_PREFIX );

		if ( ! empty( $args['format'] ) ) {
			$classes[] = self::CSS_PREFIX . '--' . $args['format'];
		}

		if ( ! empty( $args['css_class'] ) ) {
			$classes[] = $args['css_class'];
		}

		// Add RTL class if current language is RTL.
		$current = polyglot_get_current_language();

		try {
			$plugin = Plugin::getInstance();

			if ( $plugin->has( 'language.repository' ) ) {
				$repo = $plugin->get( 'language.repository' );
				$lang = $repo->getByCode( $current );

				if ( $lang && $lang->isRtl() ) {
					$classes[] = self::CSS_PREFIX . '--rtl';
				}
			}
		} catch ( \Throwable ) {
			// Ignore — no RTL class.
		}

		return implode( ' ', $classes );
	}
}
