<?php
/**
 * Translation group value object for NovaTools Polyglot.
 *
 * Immutable representation of a translation group identified by a `trid`
 * (translation ID). Groups link elements across languages — e.g. the English
 * original post and its French, German, … translations all share the same trid.
 *
 * @package NovaTools\Polyglot\Translation
 */

namespace NovaTools\Polyglot\Translation;

defined( 'ABSPATH' ) || exit;

class TranslationGroup {

	/**
	 * Translation ID — shared by all elements in the group.
	 *
	 * @var int
	 */
	public readonly int $trid;

	/**
	 * All translation rows in this group, keyed by language code.
	 *
	 * Each entry is an associative array with at least:
	 *   - translation_id (int)
	 *   - element_type   (string)
	 *   - element_id     (int|null)
	 *   - language_code  (string)
	 *   - source_language_code (string)
	 *   - status         (string)
	 *
	 * @var array<string, array>
	 */
	public readonly array $elements;

	/**
	 * The source (original) language code for this group.
	 *
	 * @var string
	 */
	public readonly string $sourceLanguageCode;

	/**
	 * Constructor.
	 *
	 * @param int    $trid              Translation group ID.
	 * @param array  $elements          Translation rows keyed by language code.
	 * @param string $sourceLanguageCode Language code of the original element.
	 */
	public function __construct(
		int    $trid,
		array  $elements,
		string $sourceLanguageCode
	) {
		$this->trid              = $trid;
		$this->elements          = $elements;
		$this->sourceLanguageCode = $sourceLanguageCode;
	}

	/**
	 * Create a TranslationGroup from a set of database rows sharing a trid.
	 *
	 * @param array $rows Array of associative arrays from polyglot_translations.
	 * @return static
	 */
	public static function fromRows( array $rows ): static {
		if ( empty( $rows ) ) {
			return new static( 0, array(), '' );
		}

		$trid    = (int) $rows[0]['trid'];
		$source  = '';
		$by_lang = array();

		foreach ( $rows as $row ) {
			$code = (string) ( $row['language_code'] ?? '' );

			$by_lang[ $code ] = array(
				'translation_id'       => (int) ( $row['translation_id'] ?? 0 ),
				'element_type'         => (string) ( $row['element_type'] ?? '' ),
				'element_id'           => isset( $row['element_id'] ) ? (int) $row['element_id'] : null,
				'language_code'        => $code,
				'source_language_code' => (string) ( $row['source_language_code'] ?? '' ),
				'status'               => (string) ( $row['status'] ?? 'not_translated' ),
			);

			// The first row with an empty source_language_code is the original.
			if ( '' === $source && '' === ( $row['source_language_code'] ?? '' ) ) {
				$source = $code;
			}
		}

		// If no source found (all rows have source set), use the first language.
		if ( '' === $source && ! empty( $by_lang ) ) {
			$source = array_key_first( $by_lang );
		}

		return new static( $trid, $by_lang, $source );
	}

	/**
	 * Get the element ID for a given language in this group.
	 *
	 * @param string $languageCode Target language code.
	 * @return int|null Element ID or null if no translation exists for that language.
	 */
	public function getElementId( string $languageCode ): ?int {
		return $this->elements[ $languageCode ]['element_id'] ?? null;
	}

	/**
	 * Get the status of a translation for a given language.
	 *
	 * @param string $languageCode Target language code.
	 * @return string Status string, or 'not_translated' if not in the group.
	 */
	public function getStatus( string $languageCode ): string {
		return $this->elements[ $languageCode ]['status'] ?? 'not_translated';
	}

	/**
	 * Get all language codes present in this group.
	 *
	 * @return string[]
	 */
	public function getLanguageCodes(): array {
		return array_keys( $this->elements );
	}

	/**
	 * Whether the group has a translation for a given language.
	 *
	 * @param string $languageCode Language code to check.
	 * @return bool
	 */
	public function hasLanguage( string $languageCode ): bool {
		return isset( $this->elements[ $languageCode ] );
	}

	/**
	 * Return the group as a plain associative array.
	 *
	 * Useful for cache storage and REST API responses.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'trid'               => $this->trid,
			'source_language_code' => $this->sourceLanguageCode,
			'elements'           => $this->elements,
		);
	}
}
