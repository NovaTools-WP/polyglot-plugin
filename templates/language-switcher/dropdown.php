<?php
/**
 * Language switcher dropdown template.
 *
 * Available variables (extracted from $vars by LanguageSwitcher::renderTemplate()):
 *
 * @var array[] $items       Array of language items. Each item contains:
 *                           - code         (string)  Language code.
 *                           - locale       (string)  Full WordPress locale.
 *                           - native_name  (string)  Name in the language.
 *                           - english_name (string)  Name in English.
 *                           - flag_url     (string)  Flag image URL.
 *                           - url          (string)  Translated page URL.
 *                           - is_current   (bool)    Whether this is the current language.
 *                           - direction    (string)  "ltr" or "rtl".
 * @var bool    $show_flags  Whether to display flag images.
 * @var bool    $show_names  Whether to display language names.
 * @var string  $css_prefix  CSS class prefix (e.g. "polyglot-switcher").
 * @var string  $css_classes Space-separated CSS classes for the wrapper.
 * @var string  $current     Current language code.
 *
 * @package NovaTools\Polyglot\LanguageSwitcher
 *
 * Theme override: Copy this file to your theme at
 * wp-content/themes/{theme}/polyglot/language-switcher/dropdown.php
 * to customise the dropdown markup.
 */

defined( 'ABSPATH' ) || exit;
?>
<select class="<?php echo esc_attr( $css_prefix ); ?>-dropdown <?php echo esc_attr( $css_classes ); ?>" data-polyglot-switcher="dropdown" aria-label="<?php esc_attr_e( 'Language switcher', 'novatools-polyglot' ); ?>">
	<?php foreach ( $items as $item ) : ?>
		<option
			value="<?php echo esc_url( $item['url'] ); ?>"
			<?php if ( $item['is_current'] ) : ?>
				selected="selected"
			<?php endif; ?>
			data-flag="<?php echo esc_url( $item['flag_url'] ); ?>"
			data-code="<?php echo esc_attr( $item['code'] ); ?>"
			data-locale="<?php echo esc_attr( $item['locale'] ); ?>"
			dir="<?php echo esc_attr( $item['direction'] ); ?>"
		>
			<?php
			$label_parts = array();
			if ( $show_flags && $item['flag_url'] ) {
				// Flags in <option> are indicated via data attribute;
				// textual label is built from the name.
			}
			if ( $show_names ) {
				$label_parts[] = $item['native_name'];
			}
			if ( empty( $label_parts ) ) {
				$label_parts[] = strtoupper( $item['code'] );
			}
			echo esc_html( implode( ' ', $label_parts ) );
			?>
		</option>
	<?php endforeach; ?>
</select>
<script>
(function() {
	var wrapper = document.currentScript.parentElement;
	var switcher = wrapper ? wrapper.querySelector('[data-polyglot-switcher="dropdown"]') : null;
	if (switcher) {
		switcher.addEventListener('change', function() {
			window.location.href = this.value;
		});
	}
})();
</script>
