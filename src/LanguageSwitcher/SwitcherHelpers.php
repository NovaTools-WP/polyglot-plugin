<?php
/**
 * Shared helper methods for language switcher components.
 *
 * Provides utility methods used across multiple switcher integration
 * classes (Widget, Shortcode, Block) to avoid duplication.
 *
 * @package NovaTools\Polyglot\LanguageSwitcher
 */

namespace NovaTools\Polyglot\LanguageSwitcher;

defined( 'ABSPATH' ) || exit;

trait SwitcherHelpers {

	/**
	 * Parse a comma-separated exclude string into an array of language codes.
	 *
	 * @param string $exclude Comma-separated language codes.
	 * @return string[] Filtered array of trimmed codes.
	 */
	private function parseExclude( string $exclude ): array {
		if ( '' === $exclude ) {
			return array();
		}

		return array_filter( array_map( 'trim', explode( ',', $exclude ) ) );
	}
}
