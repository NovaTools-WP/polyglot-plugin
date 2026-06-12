<?php
/**
 * Language switcher Gutenberg block for NovaTools Polyglot.
 *
 * Registers a server-rendered Gutenberg block that displays the language
 * switcher. The block uses the Block API's `render_callback` to delegate
 * rendering to the central LanguageSwitcher service, ensuring consistent
 * output across all switcher contexts (widget, shortcode, block).
 *
 * The editor shows a static preview via the `editorScript`; the frontend
 * renders the real switcher via the server-side render callback.
 *
 * @package NovaTools\Polyglot\LanguageSwitcher
 */

namespace NovaTools\Polyglot\LanguageSwitcher;

use NovaTools\Polyglot\Core\Plugin;

defined( 'ABSPATH' ) || exit;

class SwitcherBlock {

	use SwitcherHelpers;

	/**
	 * Block type name (namespace/block-name).
	 *
	 * @var string
	 */
	const BLOCK_NAME = 'novatools-polyglot/language-switcher';

	/**
	 * The central language switcher renderer.
	 *
	 * @var LanguageSwitcher|null
	 */
	private ?LanguageSwitcher $switcher = null;

	/**
	 * Register the Gutenberg block with WordPress.
	 *
	 * Should be called during the 'init' action.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type( self::BLOCK_NAME, array(
			'attributes'      => $this->getAttributes(),
			'render_callback' => array( $this, 'render' ),
			'editor_script'   => $this->registerEditorScript(),
		) );
	}

	/**
	 * Server-side render callback for the block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML output of the language switcher.
	 */
	public function render( array $attributes ): string {
		$switcher_args = array(
			'format'     => $attributes['format'] ?? 'list',
			'show_flags' => (bool) ( $attributes['showFlags'] ?? true ),
			'show_names' => (bool) ( $attributes['showNames'] ?? true ),
			'exclude'    => isset( $attributes['exclude'] )
				? $this->parseExclude( $attributes['exclude'] )
				: array(),
		);

		/**
		 * Filter the language switcher block arguments before rendering.
		 *
		 * @param array $switcher_args Arguments for the renderer.
		 * @param array $attributes    Block attributes.
		 */
		$switcher_args = apply_filters( 'polyglot_switcher_block_args', $switcher_args, $attributes );

		return $this->getSwitcher()->render( $switcher_args );
	}

	/**
	 * Define the block attributes schema.
	 *
	 * @return array Block attributes definition.
	 */
	private function getAttributes(): array {
		return array(
			'format' => array(
				'type'    => 'string',
				'default' => 'list',
				'enum'    => array( 'list', 'dropdown' ),
			),
			'showFlags' => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'showNames' => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'exclude' => array(
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	/**
	 * Register the editor JavaScript for the block.
	 *
	 * Inline script registers the block type in the Gutenberg editor
	 * with a preview component.
	 *
	 * @return string Script handle.
	 */
	private function registerEditorScript(): string {
		$handle = 'novatools-polyglot-switcher-block';
		$script = $this->getEditorScript();

		wp_register_script(
			$handle,
			false, // No external file — inline script.
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-block-editor' ),
			NOVATOOLS_POLYGLOT_VERSION
		);

		wp_add_inline_script( $handle, $script );

		return $handle;
	}

	/**
	 * Generate the editor-side JavaScript for block registration and preview.
	 *
	 * @return string JavaScript source.
	 */
	private function getEditorScript(): string {
		return <<<'JS'
(function() {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var TextControl = wp.components.TextControl;

	var blockIcon = el('svg', { width: 24, height: 24, viewBox: '0 0 24 24' },
		el('path', { d: 'M12.87 15.07l-2.54-2.51.03-.03A17.52 17.52 0 0014.07 6H17V4h-7V2H8v2H1v1.99h11.17C11.5 7.92 10.44 9.75 9 11.35 8.07 10.32 7.3 9.19 6.69 8h-2c.73 1.63 1.73 3.17 2.98 4.56l-5.09 5.02L4 19l5-5 3.11 3.11.76-2.04zM18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12zm-2.62 7l1.62-4.33L19.12 17h-3.24z' })
	);

	wp.blocks.registerBlockType('novatools-polyglot/language-switcher', {
		title: __('PolyGlot Language Switcher', 'novatools-polyglot'),
		description: __('Display a language switcher with flags and language names.', 'novatools-polyglot'),
		icon: blockIcon,
		category: 'widgets',
		supports: {
			html: false,
			align: true,
		},
		attributes: {
			format: { type: 'string', default: 'list' },
			showFlags: { type: 'boolean', default: true },
			showNames: { type: 'boolean', default: true },
			exclude: { type: 'string', default: '' },
		},
		edit: function(props) {
			var attrs = props.attributes;
			var setAttrs = props.setAttributes;

			// Build preview languages (static for editor).
			var previewLangs = [
				{ code: 'en', name: 'English', flag: '🇬🇧' },
				{ code: 'fr', name: 'Français', flag: '🇫🇷' },
				{ code: 'de', name: 'Deutsch', flag: '🇩🇪' },
			];

			var excluded = attrs.exclude ? attrs.exclude.split(',').map(function(s) { return s.trim(); }) : [];

			var filteredLangs = previewLangs.filter(function(l) {
				return excluded.indexOf(l.code) === -1;
			});

			// Build preview items.
			var items;
			if (attrs.format === 'dropdown') {
				var options = filteredLangs.map(function(l) {
					var label = '';
					if (attrs.showFlags) label += l.flag + ' ';
					if (attrs.showNames) label += l.name;
					if (!label) label = l.code;
					return el('option', { key: l.code, value: l.code }, label);
				});
				items = el('select', {
					className: 'polyglot-switcher-preview-dropdown',
					disabled: true,
				}, options);
			} else {
				var listItems = filteredLangs.map(function(l) {
					var content = [];
					if (attrs.showFlags) content.push(el('span', { key: 'flag', className: 'polyglot-switcher-preview-flag' }, l.flag + ' '));
					if (attrs.showNames) content.push(el('span', { key: 'name', className: 'polyglot-switcher-preview-name' }, l.name));
					if (!content.length) content.push(el('span', { key: 'code' }, l.code));
					return el('li', { key: l.code, className: 'polyglot-switcher-preview-item' }, content);
				});
				items = el('ul', { className: 'polyglot-switcher-preview-list' }, listItems);
			}

			var inspector = el(InspectorControls, null,
				el(PanelBody, { title: __('Switcher Settings', 'novatools-polyglot'), initialOpen: true },
					el(SelectControl, {
						label: __('Format', 'novatools-polyglot'),
						value: attrs.format,
						options: [
							{ label: __('List', 'novatools-polyglot'), value: 'list' },
							{ label: __('Dropdown', 'novatools-polyglot'), value: 'dropdown' },
						],
						onChange: function(v) { setAttrs({ format: v }); },
					}),
					el(ToggleControl, {
						label: __('Show flags', 'novatools-polyglot'),
						checked: attrs.showFlags,
						onChange: function(v) { setAttrs({ showFlags: v }); },
					}),
					el(ToggleControl, {
						label: __('Show language names', 'novatools-polyglot'),
						checked: attrs.showNames,
						onChange: function(v) { setAttrs({ showNames: v }); },
					}),
					el(TextControl, {
						label: __('Exclude languages (comma-separated codes)', 'novatools-polyglot'),
						value: attrs.exclude,
						placeholder: 'e.g. de,ja',
						onChange: function(v) { setAttrs({ exclude: v }); },
					})
				)
			);

			return el('div', useBlockProps(),
				inspector,
				el('div', { className: 'polyglot-switcher-block-preview' },
					el('span', { className: 'polyglot-switcher-block-label' },
						__('Language Switcher', 'novatools-polyglot')
					),
					items
				)
			);
		},
		save: function() {
			// Server-rendered block — return null.
			return null;
		},
	});
})();
JS;
	}

	/**
	 * Get the LanguageSwitcher service instance.
	 *
	 * @return LanguageSwitcher
	 */
	private function getSwitcher(): LanguageSwitcher {
		if ( null === $this->switcher ) {
			try {
				$plugin          = Plugin::getInstance();
				$this->switcher  = $plugin->get( 'language_switcher' );
			} catch ( \Throwable ) {
				$this->switcher = new LanguageSwitcher();
			}
		}

		return $this->switcher;
	}
}
