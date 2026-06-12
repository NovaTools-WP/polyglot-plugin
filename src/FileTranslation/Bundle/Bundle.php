<?php
/**
 * Translation bundle value object for NovaTools Polyglot.
 *
 * Represents a theme or plugin translation set, including its text domain,
 * type (plugin/theme/core), version, and available translation files.
 *
 * Immutable value object following the same pattern as `Language`.
 *
 * @package NovaTools\Polyglot\FileTranslation\Bundle
 */

namespace NovaTools\Polyglot\FileTranslation\Bundle;

defined( 'ABSPATH' ) || exit;

class Bundle {

	/**
	 * Bundle type: plugin.
	 *
	 * @var string
	 */
	const TYPE_PLUGIN = 'plugin';

	/**
	 * Bundle type: theme.
	 *
	 * @var string
	 */
	const TYPE_THEME = 'theme';

	/**
	 * Bundle type: core WordPress.
	 *
	 * @var string
	 */
	const TYPE_CORE = 'core';

	/**
	 * Text domain (e.g. "woocommerce", "twentytwentyfour").
	 *
	 * @var string
	 */
	public readonly string $domain;

	/**
	 * Bundle type — one of TYPE_PLUGIN, TYPE_THEME, TYPE_CORE.
	 *
	 * @var string
	 */
	public readonly string $type;

	/**
	 * Human-readable name (e.g. "WooCommerce", "Twenty Twenty-Four").
	 *
	 * @var string
	 */
	public readonly string $name;

	/**
	 * Version string from the plugin/theme header.
	 *
	 * @var string
	 */
	public readonly string $version;

	/**
	 * Absolute path to the plugin/theme root directory.
	 *
	 * @var string
	 */
	public readonly string $path;

	/**
	 * Absolute path to the POT template file, if available.
	 *
	 * @var string
	 */
	public readonly string $potFile;

	/**
	 * Available locales with at least one PO file.
	 *
	 * @var string[]
	 */
	public readonly array $locales;

	/**
	 * Number of translatable strings (from POT).
	 *
	 * @var int
	 */
	public readonly int $stringCount;

	/**
	 * Number of fully translated locales.
	 *
	 * @var int
	 */
	public readonly int $completedCount;

	/**
	 * Constructor.
	 *
	 * @param string   $domain         Text domain.
	 * @param string   $type           Bundle type.
	 * @param string   $name           Display name.
	 * @param string   $version        Version string.
	 * @param string   $path           Root directory path.
	 * @param string   $potFile        POT file path (empty string if none).
	 * @param string[] $locales        Available locale codes.
	 * @param int      $stringCount    Total translatable strings.
	 * @param int      $completedCount Fully translated locale count.
	 */
	public function __construct(
		string $domain,
		string $type,
		string $name,
		string $version,
		string $path,
		string $potFile,
		array $locales,
		int $stringCount,
		int $completedCount
	) {
		$this->domain         = $domain;
		$this->type           = $type;
		$this->name           = $name;
		$this->version        = $version;
		$this->path           = $path;
		$this->potFile        = $potFile;
		$this->locales        = $locales;
		$this->stringCount    = $stringCount;
		$this->completedCount = $completedCount;
	}

	/**
	 * Create a Bundle from an associative array.
	 *
	 * @param array $data Bundle data.
	 * @return static
	 */
	public static function fromArray( array $data ): static {
		return new static(
			(string) ( $data['domain'] ?? '' ),
			(string) ( $data['type'] ?? self::TYPE_PLUGIN ),
			(string) ( $data['name'] ?? '' ),
			(string) ( $data['version'] ?? '' ),
			(string) ( $data['path'] ?? '' ),
			(string) ( $data['pot_file'] ?? '' ),
			(array) ( $data['locales'] ?? array() ),
			(int) ( $data['string_count'] ?? 0 ),
			(int) ( $data['completed_count'] ?? 0 ),
		);
	}

	/**
	 * Convert the bundle to an associative array.
	 *
	 * Useful for cache storage and REST API responses.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'domain'          => $this->domain,
			'type'            => $this->type,
			'name'            => $this->name,
			'version'         => $this->version,
			'path'            => $this->path,
			'pot_file'        => $this->potFile,
			'locales'         => $this->locales,
			'string_count'    => $this->stringCount,
			'completed_count' => $this->completedCount,
		);
	}

	/**
	 * Whether the bundle has a POT template file.
	 *
	 * @return bool
	 */
	public function hasPot(): bool {
		return '' !== $this->potFile && file_exists( $this->potFile );
	}

	/**
	 * Whether the bundle is a plugin.
	 *
	 * @return bool
	 */
	public function isPlugin(): bool {
		return self::TYPE_PLUGIN === $this->type;
	}

	/**
	 * Whether the bundle is a theme.
	 *
	 * @return bool
	 */
	public function isTheme(): bool {
		return self::TYPE_THEME === $this->type;
	}

	/**
	 * Whether the bundle is core WordPress.
	 *
	 * @return bool
	 */
	public function isCore(): bool {
		return self::TYPE_CORE === $this->type;
	}

	/**
	 * Completion percentage across all locales.
	 *
	 * @return int Percentage (0–100).
	 */
	public function completionPercent(): int {
		if ( 0 === count( $this->locales ) ) {
			return 0;
		}

		return (int) round( ( $this->completedCount / count( $this->locales ) ) * 100 );
	}
}
