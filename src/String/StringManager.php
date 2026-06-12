<?php
/**
 * String manager for NovaTools Polyglot.
 *
 * Orchestrates string registration, translation lookup, and translation
 * storage. The `registerString()` method computes an MD5 hash from
 * domain + context + name, inserting or updating the string row in
 * `polyglot_strings`. The `translateString()` and `getTranslation()`
 * methods serve translated values with fallback to the original.
 *
 * @package NovaTools\Polyglot\String
 */

namespace NovaTools\Polyglot\String;

use NovaTools\Polyglot\Support\Cache;
use NovaTools\Polyglot\Support\Logger;
use NovaTools\Polyglot\String\Package\PackageRepository;

defined( 'ABSPATH' ) || exit;

class StringManager {

	/**
	 * Translation status: untranslated.
	 *
	 * @var int
	 */
	const STATUS_UNTRANSLATED = 0;

	/**
	 * Translation status: translated.
	 *
	 * @var int
	 */
	const STATUS_TRANSLATED = 1;

	/**
	 * Translation status: needs update (source string changed).
	 *
	 * @var int
	 */
	const STATUS_NEEDS_UPDATE = 2;

	/**
	 * String repository (read/write access).
	 *
	 * @var StringRepository
	 */
	private StringRepository $repository;

	/**
	 * Cache wrapper instance.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Translation memory instance (optional).
	 *
	 * @var TranslationMemory|null
	 */
	private ?TranslationMemory $translationMemory = null;

	/**
	 * Package repository instance (optional).
	 *
	 * @var PackageRepository|null
	 */
	private ?PackageRepository $packageRepository = null;

	/**
	 * Constructor.
	 *
	 * @param StringRepository $repository String read/write access.
	 * @param Cache            $cache      Cache wrapper for invalidation.
	 */
	public function __construct( StringRepository $repository, Cache $cache ) {
		$this->repository = $repository;
		$this->cache      = $cache;
	}

	/**
	 * Set the translation memory instance.
	 *
	 * @param TranslationMemory $memory Translation memory for suggestions.
	 */
	public function setTranslationMemory( TranslationMemory $memory ): void {
		$this->translationMemory = $memory;
	}

	/**
	 * Set the package repository instance.
	 *
	 * @param PackageRepository $repository Package repository for auto-creation.
	 */
	public function setPackageRepository( PackageRepository $repository ): void {
		$this->packageRepository = $repository;
	}

	/**
	 * Register a string for translation.
	 *
	 * Computes an MD5 hash of `domain|context|name` and inserts a row
	 * into `polyglot_strings`. If a string with the same hash already
	 * exists, its value and metadata are updated and existing translations
	 * are flagged as "needs_update" when the source value has changed.
	 *
	 * @param string $domain  Text domain (e.g. "mytheme", "contact-form-7").
	 * @param string $name    Machine-readable string identifier.
	 * @param string $value   The source string value.
	 * @param string $context Optional grouping context. Default empty.
	 * @param array  $args {
	 *     Optional. Extra registration metadata.
	 *
	 *     @type int    $package_id           Package ID to link the string to.
	 *     @type string $type                 String type: 'LINE', 'TEXTAREA', 'VISUAL'. Default 'LINE'.
	 *     @type string $title                Human-readable label. Default same as $name.
	 *     @type string $translation_priority 'optional', 'prioritized', etc. Default 'optional'.
	 * }
	 * @return int The string ID.
	 */
	public function registerString(
		string $domain,
		string $name,
		string $value,
		string $context = '',
		array $args = array()
	): int {
		$hash = $this->computeHash( $domain, $context, $name );

		// Look for an existing string with the same hash.
		$existing = $this->repository->findByHash( $hash );

		$data = array(
			'domain'              => $domain,
			'context'             => $context,
			'name'                => $name,
			'value'               => $value,
			'hash'                => $hash,
			'type'                => $args['type'] ?? 'LINE',
			'title'               => $args['title'] ?? $name,
			'translation_priority' => $args['translation_priority'] ?? 'optional',
		);

		if ( isset( $args['package_id'] ) && $args['package_id'] > 0 ) {
			$data['package_id'] = (int) $args['package_id'];
		} elseif ( null !== $this->packageRepository ) {
			// Auto-create or look up a package for the domain so strings are
			// grouped by plugin/theme automatically.
			$package_id = $this->ensurePackageForDomain( $domain );
			if ( $package_id > 0 ) {
				$data['package_id'] = $package_id;
			}
		}

		if ( $existing ) {
			$id = (int) $existing['id'];

			// Detect whether the source value actually changed.
			$value_changed = ( $existing['value'] !== $value );

			$data['id'] = $id;

			// Update word count only when value changes.
			if ( $value_changed ) {
				$data['word_count'] = str_word_count( strip_tags( $value ) );
			}

			$this->repository->save( $data );

			// When the source value changed, flag all existing translations
			// as "needs_update" (status = 2 in WPML convention).
			if ( $value_changed ) {
				$this->flagTranslationsNeedsUpdate( $id );

				// Store in translation memory for future suggestions.
				$this->storeInTranslationMemory( $existing['value'], $domain, $name, $context );
			}

			$this->repository->invalidateStringCache( $hash, $id );
		} else {
			$data['word_count'] = str_word_count( strip_tags( $value ) );

			$id = $this->repository->save( $data );

			$this->repository->invalidateStringCache( $hash, $id );
		}

		$this->repository->invalidateDomainCache( $domain );

		/**
		 * Fires after a string has been registered or updated.
		 *
		 * @param int    $id      The string ID.
		 * @param string $domain  Text domain.
		 * @param string $name    String name/identifier.
		 * @param string $value   Source string value.
		 * @param string $context Grouping context.
		 */
		do_action( 'polyglot_string_registered', $id, $domain, $name, $value, $context );

		return $id;
	}

	/**
	 * Translate a string by domain, context and name for a given language.
	 *
	 * Looks up the string by domain + context + name, then returns the
	 * translated value for the requested language. Falls back to the
	 * original string value when no translation exists.
	 *
	 * @param string $domain   Text domain.
	 * @param string $name     String name/identifier.
	 * @param string $language Target language code.
	 * @param string $context  Optional grouping context. Default empty.
	 * @return string Translated value, or original value as fallback.
	 */
	public function translateString( string $domain, string $name, string $language, string $context = '' ): string {
		$hash   = $this->computeHash( $domain, $context, $name );
		$string = $this->repository->findByHash( $hash );

		if ( ! $string ) {
			return '';
		}

		// If the requested language matches the source language, return original.
		$default_lang = function_exists( 'polyglot_get_default_language' )
			? polyglot_get_default_language()
			: 'en';

		if ( $language === $default_lang ) {
			return $string['value'];
		}

		$translation = $this->repository->getTranslation( (int) $string['id'], $language );

		// Return the translated value only if the translation is complete.
		if ( $translation && self::STATUS_TRANSLATED === (int) $translation['status'] && null !== $translation['value'] ) {
			return $translation['value'];
		}

		// Fallback to the original string value.
		return $string['value'];
	}

	/**
	 * Get the full translation row for a string + language pair.
	 *
	 * Unlike translateString() which returns a plain string, this method
	 * returns the full translation data including status, translator ID,
	 * and timestamp — useful for admin interfaces.
	 *
	 * @param int    $string_id The string ID from polyglot_strings.
	 * @param string $language  Target language code.
	 * @return array|null Translation row data, or null if not found.
	 */
	public function getTranslation( int $string_id, string $language ): ?array {
		return $this->repository->getTranslation( $string_id, $language );
	}

	/**
	 * Save a translation for a specific string + language.
	 *
	 * Inserts or updates the translation row in `polyglot_string_translations`.
	 *
	 * @param int    $string_id The string ID.
	 * @param string $language  Target language code.
	 * @param string $value     The translated value.
	 * @param int    $status    Translation status. Default self::STATUS_TRANSLATED.
	 * @return int The translation row ID.
	 */
	public function saveTranslation(
		int $string_id,
		string $language,
		string $value,
		int $status = self::STATUS_TRANSLATED
	): int {
		$data = array(
			'string_id'   => $string_id,
			'language'    => $language,
			'value'       => $value,
			'status'      => $status,
			'translator_id' => get_current_user_id(),
		);

		$id = $this->repository->saveTranslation( $data );

		/**
		 * Fires after a string translation has been saved.
		 *
		 * @param int    $id        The translation row ID.
		 * @param int    $string_id The source string ID.
		 * @param string $language  Target language code.
		 * @param string $value     The translated value.
		 */
		do_action( 'polyglot_string_translation_saved', $id, $string_id, $language, $value );

		return $id;
	}

	/**
	 * Compute the unique hash for a string registration.
	 *
	 * The hash is derived from the text domain, context, and name using
	 * MD5. This mirrors WPML's approach of using `md5( domain|context|name )`.
	 *
	 * @param string $domain  Text domain.
	 * @param string $context Grouping context.
	 * @param string $name    String name/identifier.
	 * @return string 32-character hex MD5 hash.
	 */
	public function computeHash( string $domain, string $context, string $name ): string {
		return md5( $domain . '|' . $context . '|' . $name );
	}

	/**
	 * Get all translations for a string, keyed by language code.
	 *
	 * @param int $string_id The string ID.
	 * @return array[] Associative array of translation rows keyed by language code.
	 */
	public function getTranslationsForString( int $string_id ): array {
		return $this->repository->getTranslationsForString( $string_id );
	}

	/**
	 * Flag all existing translations of a string as "needs_update".
	 *
	 * Called when the source string value changes so that translators
	 * are notified that their translations are stale.
	 *
	 * @param int $string_id The string ID.
	 */
	private function flagTranslationsNeedsUpdate( int $string_id ): void {
		global $wpdb;

		$table = \NovaTools\Polyglot\Database\Schema::getTableName( 'polyglot_string_translations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$table,
			array( 'status' => self::STATUS_NEEDS_UPDATE ),
			array( 'string_id' => $string_id )
		);

		if ( false === $result ) {
			Logger::error( sprintf(
				'Failed to flag translations as needs_update for string %d: %s',
				$string_id,
				$wpdb->last_error
			) );
		}

		// Invalidate translation caches for this string.
		$translations = $this->repository->getTranslationsForString( $string_id );
		foreach ( $translations as $lang => $row ) {
			$this->cache->delete(
				$this->cache->key( 'string_translation', $string_id, $lang )
			);
		}
		$this->cache->delete( $this->cache->key( 'string_translations', $string_id ) );
	}

	/**
	 * Store the old source value in translation memory.
	 *
	 * Only stores when translation memory is available.
	 *
	 * @param string $value   The old source string value.
	 * @param string $domain  The text domain.
	 * @param string $name    The string identifier used during registration.
	 * @param string $context The context used during registration. Default empty.
	 */
	private function storeInTranslationMemory( string $value, string $domain, string $name, string $context = '' ): void {
		if ( null === $this->translationMemory ) {
			return;
		}

		$this->translationMemory->store( $value, $domain, $name, $context );
	}

	/**
	 * Ensure a string package exists for the given text domain.
	 *
	 * Detects whether the domain belongs to an installed plugin, theme, or
	 * falls back to "Admin Texts". If a matching package already exists it is
	 * returned; otherwise a new one is created.
	 *
	 * @param string $domain Text domain (e.g. "mytheme", "contact-form-7").
	 * @return int Package ID, or 0 on failure.
	 */
	private function ensurePackageForDomain( string $domain ): int {
		// Determine kind and kind_slug from the domain.
		$kind      = 'Admin Texts';
		$kind_slug = $domain;
		$title     = $domain;

		// Check installed plugins.
		if ( function_exists( 'get_plugins' ) ) {
			$plugins = get_plugins();
			foreach ( $plugins as $plugin_file => $plugin_data ) {
				$plugin_domain = $plugin_data['TextDomain'] ?? '';
				if ( '' === $plugin_domain ) {
					$plugin_domain = dirname( $plugin_file );
				}
				if ( $plugin_domain === $domain ) {
					$kind      = 'Plugin';
					$kind_slug = dirname( $plugin_file );
					$title     = $plugin_data['Name'] ?? $domain;
					break;
				}
			}
		}

		// Check installed themes if no plugin matched.
		if ( 'Admin Texts' === $kind && function_exists( 'wp_get_themes' ) ) {
			$themes = wp_get_themes();
			foreach ( $themes as $slug => $theme ) {
				$theme_domain = $theme->get( 'TextDomain' ) ?: $slug;
				if ( $theme_domain === $domain ) {
					$kind      = 'Theme';
					$kind_slug = $slug;
					$title     = $theme->get( 'Name' ) ?: $domain;
					break;
				}
			}
		}

		// Look for an existing package by natural key.
		$existing = $this->packageRepository->findByKindAndName( $kind, $kind_slug, $domain );

		if ( $existing ) {
			return (int) $existing['id'];
		}

		// Create a new package.
		return $this->packageRepository->save( array(
			'kind'      => $kind,
			'kind_slug' => $kind_slug,
			'name'      => $domain,
			'title'     => $title,
		) );
	}
}
