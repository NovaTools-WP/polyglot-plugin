<?php
/**
 * WPML API compatibility shim for NovaTools Polyglot.
 *
 * Provides drop-in replacements for the 10 most common WPML API functions
 * that themes and plugins call directly. The shim maps each function to its
 * PolyGlot equivalent, ensuring compatibility during and after migration
 * from WPML.
 *
 * Each function is guarded by a `function_exists()` check so that the shim
 * does not cause fatal errors when WPML is still active (coexistence during
 * the migration period). When WPML is present, the original WPML functions
 * take precedence and a notice is logged.
 *
 * Supported functions:
 *   - icl_object_id()
 *   - icl_register_string()
 *   - icl_t()
 *   - icl_translate()
 *   - wpml_get_language_information()
 *   - wpml_get_current_language()
 *   - wpml_default_language()
 *   - wpml_active_languages()
 *   - wpml_element_has_translations()
 *   - wpml_translate_single_string()
 *
 * @package NovaTools\Polyglot\Compatibility
 */

namespace NovaTools\Polyglot\Compatibility;

defined( 'ABSPATH' ) || exit;

class WpmlApiShim {

	/**
	 * Whether the shim has been loaded.
	 *
	 * @var bool
	 */
	private static bool $loaded = false;

	/**
	 * Load the WPML compatibility shim functions.
	 *
	 * Defines each compatibility function only when it does not already
	 * exist. This allows WPML to remain active during the migration
	 * period without causing "Cannot redeclare function" errors.
	 *
	 * Should be called during the `plugins_loaded` hook at a late priority
	 * so that WPML has a chance to define its own functions first.
	 *
	 * @return bool True if at least one function was shimmed, false if all
	 *              functions already exist (WPML is active).
	 */
	public static function load(): bool {
		if ( self::$loaded ) {
			return false;
		}

		self::$loaded = true;

		$shimmed  = false;
		$wpml_active = false;

		// ── icl_object_id ────────────────────────────────────────────
		if ( ! function_exists( 'icl_object_id' ) ) {
			/**
			 * Get the translated element ID for a given element.
			 *
			 * @param int|false $element_id                Source element ID.
			 * @param string    $element_type              Element type (e.g. 'post', 'page', 'category').
			 * @param bool      $return_original_if_missing Return original ID if no translation.
			 * @param string    $language_code             Target language code.
			 * @return int|false Translated element ID, original ID, or false.
			 */
			function icl_object_id( $element_id, string $element_type, bool $return_original_if_missing = false, string $language_code = '' ): int|false {
				if ( ! $element_id ) {
					return false;
				}

				// Normalize element type: WPML uses 'post', 'page', etc.
				// PolyGlot uses 'post_post', 'post_page', etc.
				$polyglot_type = WpmlApiShim::normalizeElementType( $element_type );

				if ( '' === $language_code && function_exists( 'polyglot_get_current_language' ) ) {
					$language_code = polyglot_get_current_language();
				}

				$translated = function_exists( 'polyglot_translate_object' )
					? polyglot_translate_object( (int) $element_id, $polyglot_type, $language_code )
					: null;

				if ( null !== $translated ) {
					return $translated;
				}

				return $return_original_if_missing ? (int) $element_id : false;
			}

			$shimmed = true;
		} else {
			$wpml_active = true;
		}

		// ── icl_register_string ──────────────────────────────────────
		if ( ! function_exists( 'icl_register_string' ) ) {
			/**
			 * Register a string for translation.
			 *
			 * @param string $domain     Text domain / context.
			 * @param string $name       Machine-readable string identifier.
			 * @param string $value      Source string value.
			 * @param bool   $allow_empty Whether to allow empty values. Default false.
			 * @param string $context    Optional grouping context.
			 * @return int|null String ID, or null on failure.
			 */
			function icl_register_string( string $domain, string $name, string $value, bool $allow_empty = false, string $context = '' ): ?int {
				if ( '' === $value && ! $allow_empty ) {
					return null;
				}

				$id = function_exists( 'polyglot_register_string' )
					? polyglot_register_string( $domain, $name, $value, $context )
					: 0;

				return $id > 0 ? $id : null;
			}

			$shimmed = true;
		} else {
			$wpml_active = true;
		}

		// ── icl_t ────────────────────────────────────────────────────
		if ( ! function_exists( 'icl_t' ) ) {
			/**
			 * Translate a registered string, with a default fallback.
			 *
			 * @param string      $domain  Text domain / context.
			 * @param string      $name    String identifier.
			 * @param string      $default Default value (returned when no translation).
			 * @param int|null    $has     Reference var set to 1 when a translation exists.
			 * @return string Translated or default value.
			 */
			function icl_t( string $domain, string $name, string $default, ?int &$has = null ): string {
				$language = function_exists( 'polyglot_get_current_language' )
					? polyglot_get_current_language()
					: '';

				$translated = function_exists( 'polyglot_translate_string' )
					? polyglot_translate_string( $domain, $name, $language )
					: '';

				// If we got a non-empty translation that differs from default, use it.
				if ( '' !== $translated && $translated !== $default ) {
					if ( null !== $has ) {
						$has = 1;
					}
					return $translated;
				}

				if ( null !== $has ) {
					$has = 0;
				}
				return $default;
			}

			$shimmed = true;
		} else {
			$wpml_active = true;
		}

		// ── icl_translate ────────────────────────────────────────────
		if ( ! function_exists( 'icl_translate' ) ) {
			/**
			 * Translate a string (variant used by cforms and other plugins).
			 *
			 * @param string $domain Text domain.
			 * @param string $name   String identifier.
			 * @param string $value  Default/source value.
			 * @return string Translated value, or the original value.
			 */
			function icl_translate( string $domain, string $name, string $value ): string {
				$language = function_exists( 'polyglot_get_current_language' )
					? polyglot_get_current_language()
					: '';

				$translated = function_exists( 'polyglot_translate_string' )
					? polyglot_translate_string( $domain, $name, $language )
					: '';

				return '' !== $translated ? $translated : $value;
			}

			$shimmed = true;
		} else {
			$wpml_active = true;
		}

		// ── wpml_get_language_information ────────────────────────────
		if ( ! function_exists( 'wpml_get_language_information' ) ) {
			/**
			 * Get language information for a post.
			 *
			 * Returns an object with language_code, native_name, and locale.
			 *
			 * @param int|null $post_id Post ID. Defaults to current post.
			 * @return object {
			 *     @type string $language_code Language code (e.g. "fr").
			 *     @type string $native_name   Native language name (e.g. "Français").
			 *     @type string $locale        Full locale (e.g. "fr_FR").
			 * }
			 */
			function wpml_get_language_information( ?int $post_id = null ): object {
				if ( null === $post_id ) {
					$post_id = get_the_ID();
				}

				$lang_code = function_exists( 'polyglot_get_object_language' )
					? polyglot_get_object_language( $post_id, 'post_post' )
					: null;

				if ( null === $lang_code ) {
					$lang_code = function_exists( 'polyglot_get_default_language' )
						? polyglot_get_default_language()
						: 'en';
				}

				// Build the return object with available info.
				$info = (object) array(
					'language_code' => $lang_code,
					'native_name'   => '',
					'locale'        => $lang_code,
				);

				// Enrich with language details if available.
				if ( function_exists( 'polyglot_get_language_name' ) ) {
					$info->native_name = polyglot_get_language_name( $lang_code );
				}

				// Try to resolve the full locale via the language repository.
				try {
					$plugin = \NovaTools\Polyglot\Core\Plugin::getInstance();

					if ( $plugin->has( 'language.repository' ) ) {
						$repo  = $plugin->get( 'language.repository' );
						$lang  = $repo->getByCode( $lang_code );

						if ( $lang ) {
							$info->locale        = $lang->locale;
							$info->native_name   = $lang->nativeName;
						}
					}
				} catch ( \Throwable ) {
					// Fall back to the basic info already set.
				}

				return $info;
			}

			$shimmed = true;
		} else {
			$wpml_active = true;
		}

		// ── wpml_get_current_language ────────────────────────────────
		if ( ! function_exists( 'wpml_get_current_language' ) ) {
			/**
			 * Get the current language code.
			 *
			 * @return string Language code (e.g. "en", "fr").
			 */
			function wpml_get_current_language(): string {
				return function_exists( 'polyglot_get_current_language' )
					? polyglot_get_current_language()
					: 'en';
			}

			$shimmed = true;
		} else {
			$wpml_active = true;
		}

		// ── wpml_default_language ────────────────────────────────────
		if ( ! function_exists( 'wpml_default_language' ) ) {
			/**
			 * Get the site's default language code.
			 *
			 * @return string Default language code.
			 */
			function wpml_default_language(): string {
				return function_exists( 'polyglot_get_default_language' )
					? polyglot_get_default_language()
					: 'en';
			}

			$shimmed = true;
		} else {
			$wpml_active = true;
		}

		// ── wpml_active_languages ────────────────────────────────────
		if ( ! function_exists( 'wpml_active_languages' ) ) {
			/**
			 * Get active languages in the requested format.
			 *
			 * @param string $format Return format: 'array', 'csv', or 'wpml_format'.
			 * @param array  $args   Optional. Additional arguments (skip_missing, etc.).
			 * @return array|string Active languages in the requested format.
			 */
			function wpml_active_languages( string $format = 'array', array $args = array() ): array|string {
				$languages = function_exists( 'polyglot_get_active_languages' )
					? polyglot_get_active_languages()
					: array();

				switch ( $format ) {
					case 'csv':
						return implode( ',', array_keys( $languages ) );

					case 'wpml_format':
						$result = array();
						foreach ( $languages as $code => $lang ) {
							$result[ $code ] = array(
								'code'           => $code,
								'native_name'    => $lang->nativeName,
								'translated_name' => $lang->englishName,
								'url'            => function_exists( 'polyglot_home_url' )
									? polyglot_home_url( $code )
									: home_url( '/' ),
								'flag'           => $lang->flagCode ?? $code,
								'directory'      => $code,
							);
						}
						return $result;

					case 'array':
					default:
						$result = array();
						foreach ( $languages as $code => $lang ) {
							$result[ $code ] = array(
								'code'        => $code,
								'native_name' => $lang->nativeName,
								'english_name' => $lang->englishName,
								'locale'      => $lang->locale,
								'direction'   => $lang->direction,
							);
						}
						return $result;
				}
			}

			$shimmed = true;
		} else {
			$wpml_active = true;
		}

		// ── wpml_element_has_translations ────────────────────────────
		if ( ! function_exists( 'wpml_element_has_translations' ) ) {
			/**
			 * Check whether an element has at least one translation.
			 *
			 * @param int    $element_id   Element ID.
			 * @param string $element_type Element type (WPML format, e.g. 'post_post').
			 * @return bool True if at least one translation exists.
			 */
			function wpml_element_has_translations( int $element_id, string $element_type ): bool {
				$group = function_exists( 'polyglot_get_translation_group' )
					? polyglot_get_translation_group( $element_id, $element_type )
					: null;

				if ( null === $group ) {
					return false;
				}

				$codes = $group->getLanguageCodes();

				// More than one language means at least one translation exists.
				return count( $codes ) > 1;
			}

			$shimmed = true;
		} else {
			$wpml_active = true;
		}

		// ── wpml_translate_single_string ─────────────────────────────
		if ( ! function_exists( 'wpml_translate_single_string' ) ) {
			/**
			 * Translate a single registered string.
			 *
			 * @param string $domain Text domain.
			 * @param string $name   String identifier.
			 * @param string $default Default value.
			 * @return string Translated value, or the default.
			 */
			function wpml_translate_single_string( string $domain, string $name, string $default = '' ): string {
				$language = function_exists( 'polyglot_get_current_language' )
					? polyglot_get_current_language()
					: '';

				$translated = function_exists( 'polyglot_translate_string' )
					? polyglot_translate_string( $domain, $name, $language )
					: '';

				return '' !== $translated ? $translated : $default;
			}

			$shimmed = true;
		} else {
			$wpml_active = true;
		}

		// Log a notice when WPML is still active alongside the shim.
		if ( $wpml_active && $shimmed ) {
			$logged = get_transient( 'polyglot_wpml_shim_partial_logged' );
			if ( ! $logged ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					'[Polyglot] WPML API Shim partially loaded: some functions are already defined by WPML. ' .
					'This is expected during migration. Deactivate WPML to use the full shim.'
				);
				set_transient( 'polyglot_wpml_shim_partial_logged', true, DAY_IN_SECONDS );
			}
		}

		if ( $wpml_active && ! $shimmed ) {
			$logged = get_transient( 'polyglot_wpml_shim_skipped_logged' );
			if ( ! $logged ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					'[Polyglot] WPML is active — WPML API Shim skipped (all functions already defined by WPML).'
				);
				set_transient( 'polyglot_wpml_shim_skipped_logged', true, DAY_IN_SECONDS );
			}
		}

		return $shimmed;
	}

	/**
	 * Check whether the shim was loaded (at least partially).
	 *
	 * @return bool
	 */
	public static function isLoaded(): bool {
		return self::$loaded;
	}

	/**
	 * Normalize a WPML element type to a PolyGlot element type.
	 *
	 * WPML commonly uses short types like 'post', 'page', 'category'.
	 * PolyGlot uses WordPress-style types like 'post_post', 'post_page',
	 * 'tax_category'.
	 *
	 * @param string $type WPML element type.
	 * @return string PolyGlot element type.
	 */
	private static function normalizeElementType( string $type ): string {
		// Already in PolyGlot format (contains underscore separator).
		$known_polyglot_types = array(
			'post_post', 'post_page', 'post_attachment',
			'tax_category', 'tax_post_tag', 'tax_nav_menu',
			'post_nav_menu_item', 'post_product', 'post_product_variation',
		);

		if ( in_array( $type, $known_polyglot_types, true ) ) {
			return $type;
		}

		// Map common WPML short types to PolyGlot format.
		$short_type_map = array(
			'post'           => 'post_post',
			'page'           => 'post_page',
			'attachment'     => 'post_attachment',
			'category'       => 'tax_category',
			'post_tag'       => 'tax_post_tag',
			'nav_menu'       => 'tax_nav_menu',
			'nav_menu_item'  => 'post_nav_menu_item',
			'product'        => 'post_product',
			'product_variation' => 'post_product_variation',
		);

		if ( isset( $short_type_map[ $type ] ) ) {
			return $short_type_map[ $type ];
		}

		// Heuristic: if the type contains only one segment (no underscore),
		// prefix with 'post_' for post types.
		if ( false === strpos( $type, '_' ) ) {
			return 'post_' . $type;
		}

		return $type;
	}
}
