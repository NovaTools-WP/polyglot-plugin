<?php
/**
 * Language value object for NovaTools Polyglot.
 *
 * Immutable representation of a language row from the `polyglot_languages`
 * table. Instances are created from database rows via `fromRow()` or
 * explicitly via the constructor.
 *
 * @package NovaTools\Polyglot\Language
 */

namespace NovaTools\Polyglot\Language;

defined( 'ABSPATH' ) || exit;

class Language {

	/**
	 * Database row ID.
	 *
	 * @var int
	 */
	public readonly int $id;

	/**
	 * Short language code (e.g. "en", "fr", "de_CH").
	 *
	 * @var string
	 */
	public readonly string $code;

	/**
	 * Full WordPress locale (e.g. "en_US", "fr_FR").
	 *
	 * @var string
	 */
	public readonly string $locale;

	/**
	 * Human-readable name in English.
	 *
	 * @var string
	 */
	public readonly string $englishName;

	/**
	 * Human-readable name in the language itself.
	 *
	 * @var string
	 */
	public readonly string $nativeName;

	/**
	 * Whether the language is active on the site.
	 *
	 * @var bool
	 */
	public readonly bool $isActive;

	/**
	 * Whether this is the site's default language.
	 *
	 * @var bool
	 */
	public readonly bool $isDefault;

	/**
	 * Text direction — "ltr" or "rtl".
	 *
	 * @var string
	 */
	public readonly string $direction;

	/**
	 * ISO flag code (typically matches the language code).
	 *
	 * @var string
	 */
	public readonly string $flagCode;

	/**
	 * PHP date format string for this language.
	 *
	 * @var string
	 */
	public readonly string $dateFormat;

	/**
	 * PHP time format string for this language.
	 *
	 * @var string
	 */
	public readonly string $timeFormat;

	/**
	 * Admin sort order (lower values appear first).
	 *
	 * @var int
	 */
	public readonly int $sortOrder;

	/**
	 * Constructor.
	 *
	 * Prefer using `fromRow()` to construct from a database row, which
	 * handles type coercion automatically.
	 *
	 * @param int    $id          Database row ID.
	 * @param string $code        Short language code.
	 * @param string $locale      Full WordPress locale.
	 * @param string $englishName Name in English.
	 * @param string $nativeName  Name in the language itself.
	 * @param bool   $isActive    Whether the language is active.
	 * @param bool   $isDefault   Whether this is the default language.
	 * @param string $direction   Text direction ("ltr" or "rtl").
	 * @param string $flagCode    ISO flag code.
	 * @param string $dateFormat  PHP date format.
	 * @param string $timeFormat  PHP time format.
	 * @param int    $sortOrder   Admin sort order.
	 */
	public function __construct(
		int    $id,
		string $code,
		string $locale,
		string $englishName,
		string $nativeName,
		bool   $isActive,
		bool   $isDefault,
		string $direction,
		string $flagCode,
		string $dateFormat,
		string $timeFormat,
		int    $sortOrder
	) {
		$this->id          = $id;
		$this->code        = $code;
		$this->locale      = $locale;
		$this->englishName = $englishName;
		$this->nativeName  = $nativeName;
		$this->isActive    = $isActive;
		$this->isDefault   = $isDefault;
		$this->direction   = $direction;
		$this->flagCode    = $flagCode;
		$this->dateFormat  = $dateFormat;
		$this->timeFormat  = $timeFormat;
		$this->sortOrder   = $sortOrder;
	}

	/**
	 * Create a Language instance from a database row (associative array).
	 *
	 * Handles type coercion from the string values returned by `$wpdb->get_row()`
	 * to the proper PHP types used by the constructor.
	 *
	 * @param array $row Associative array from the database.
	 * @return static
	 */
	public static function fromRow( array $row ): static {
		return new static(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['code'] ?? '' ),
			(string) ( $row['locale'] ?? '' ),
			(string) ( $row['english_name'] ?? '' ),
			(string) ( $row['native_name'] ?? '' ),
			(bool) ( $row['is_active'] ?? false ),
			(bool) ( $row['is_default'] ?? false ),
			(string) ( $row['direction'] ?? 'ltr' ),
			(string) ( $row['flag_code'] ?? '' ),
			(string) ( $row['date_format'] ?? '' ),
			(string) ( $row['time_format'] ?? '' ),
			(int) ( $row['sort_order'] ?? 0 ),
		);
	}

	/**
	 * Whether the language uses right-to-left text direction.
	 *
	 * @return bool
	 */
	public function isRtl(): bool {
		return 'rtl' === $this->direction;
	}

	/**
	 * Return the language as a plain associative array.
	 *
	 * Useful for cache storage and REST API responses.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'           => $this->id,
			'code'         => $this->code,
			'locale'       => $this->locale,
			'english_name' => $this->englishName,
			'native_name'  => $this->nativeName,
			'is_active'    => $this->isActive,
			'is_default'   => $this->isDefault,
			'direction'    => $this->direction,
			'flag_code'    => $this->flagCode,
			'date_format'  => $this->dateFormat,
			'time_format'  => $this->timeFormat,
			'sort_order'   => $this->sortOrder,
		);
	}
}
