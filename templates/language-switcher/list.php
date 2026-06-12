<?php
/**
 * Language switcher list template.
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
 * wp-content/themes/{theme}/polyglot/language-switcher/list.php
 * to customise the list markup.
 */

defined( 'ABSPATH' ) || exit;
?>
<ul class="<?php echo esc_attr( $css_prefix ); ?>-list <?php echo esc_attr( $css_classes ); ?>">
	<?php foreach ( $items as $item ) :
		$item_classes = $css_prefix . '-item';

		if ( $item['is_current'] ) {
			$item_classes .= ' ' . $css_prefix . '-current';
		}
		?>
		<li class="<?php echo esc_attr( $item_classes ); ?>" dir="<?php echo esc_attr( $item['direction'] ); ?>">
			<?php if ( $item['is_current'] ) : ?>
				<span class="<?php echo esc_attr( $css_prefix ); ?>-link <?php echo esc_attr( $css_prefix ); ?>-link--current" aria-current="true">
			<?php else : ?>
				<a href="<?php echo esc_url( $item['url'] ); ?>" class="<?php echo esc_attr( $css_prefix ); ?>-link" hreflang="<?php echo esc_attr( $item['code'] ); ?>">
			<?php endif; ?>

				<?php if ( $show_flags && $item['flag_url'] ) : ?>
					<img
						src="<?php echo esc_url( $item['flag_url'] ); ?>"
						alt="<?php echo esc_attr( $item['english_name'] ); ?>"
						class="<?php echo esc_attr( $css_prefix ); ?>-flag"
						width="18"
						height="12"
						loading="lazy"
					/>
				<?php endif; ?>

				<?php if ( $show_names ) : ?>
					<span class="<?php echo esc_attr( $css_prefix ); ?>-name">
						<?php echo esc_html( $item['native_name'] ); ?>
					</span>
				<?php endif; ?>

				<?php if ( ! $show_flags && ! $show_names ) : ?>
					<span class="<?php echo esc_attr( $css_prefix ); ?>-code">
						<?php echo esc_html( strtoupper( $item['code'] ) ); ?>
					</span>
				<?php endif; ?>

			<?php if ( $item['is_current'] ) : ?>
				</span>
			<?php else : ?>
				</a>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>
