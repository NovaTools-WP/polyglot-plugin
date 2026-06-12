<?php
/**
 * Custom field translator for NovaTools Polyglot.
 *
 * Handles the three custom-field translation modes:
 *   - "copy"    — Always copy the source value to the translation.
 *   - "translate" — The field holds language-specific content; each
 *                   translation has its own value.
 *   - "ignore"  — Do not copy or sync this field at all.
 *
 * Configuration is stored in `polyglot_settings` under the "custom_fields"
 * key as an associative array mapping meta keys to their mode.
 *
 * @package NovaTools\Polyglot\Translation\CustomFieldTranslation
 */

namespace NovaTools\Polyglot\Translation\CustomFieldTranslation;

use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class CustomFieldTranslator {

	/**
	 * Settings store.
	 *
	 * @var OptionStore
	 */
	private OptionStore $options;

	/**
	 * Constructor.
	 *
	 * @param OptionStore $options Settings store.
	 */
	public function __construct( OptionStore $options ) {
		$this->options = $options;
	}

	/**
	 * Copy all "copy"-mode custom fields from source to target post.
	 *
	 * This is used during post duplication to ensure that fields marked
	 * as "copy" are transferred to the new translation.
	 *
	 * @param int $sourceId Source post ID.
	 * @param int $targetId Target (translated) post ID.
	 * @return int Number of fields copied.
	 */
	public function copyFields( int $sourceId, int $targetId ): int {
		$meta = get_post_meta( $sourceId );

		if ( empty( $meta ) ) {
			return 0;
		}

		$copied      = 0;
		$fieldSettings = $this->options->get( 'custom_fields', array() );

		foreach ( $meta as $key => $values ) {
			$mode = $fieldSettings[ $key ] ?? $this->getDefaultMode( $key );

			if ( 'copy' !== $mode ) {
				continue;
			}

			// Delete existing meta on target to avoid duplicates.
			delete_post_meta( $targetId, $key );

			foreach ( $values as $value ) {
				add_post_meta( $targetId, $key, maybe_unserialize( $value ) );
			}

			++$copied;
		}

		return $copied;
	}

	/**
	 * Sync copy-mode fields from source to all its translations.
	 *
	 * Used when a source post is updated — all "copy" fields are pushed
	 * to every existing translation in the group.
	 *
	 * @param int   $sourceId    Source post ID.
	 * @param array $translationIds Array of translated post IDs keyed by language code.
	 * @return int Total number of fields synced across all translations.
	 */
	public function syncFields( int $sourceId, array $translationIds ): int {
		$meta = get_post_meta( $sourceId );

		if ( empty( $meta ) ) {
			return 0;
		}

		$copyFields    = array();
		$fieldSettings = $this->options->get( 'custom_fields', array() );

		foreach ( $meta as $key => $values ) {
			$mode = $fieldSettings[ $key ] ?? $this->getDefaultMode( $key );

			if ( 'copy' === $mode ) {
				$copyFields[ $key ] = $values;
			}
		}

		if ( empty( $copyFields ) ) {
			return 0;
		}

		$total = 0;

		foreach ( $translationIds as $targetId ) {
			foreach ( $copyFields as $key => $values ) {
				delete_post_meta( $targetId, $key );

				foreach ( $values as $value ) {
					add_post_meta( $targetId, $key, maybe_unserialize( $value ) );
				}

				++$total;
			}
		}

		return $total;
	}

	/**
	 * Get the translation mode for a specific custom field.
	 *
	 * @param string $key Meta key.
	 * @return string "copy", "translate", or "ignore".
	 */
	public function getFieldMode( string $key ): string {
		$fieldSettings = $this->options->get( 'custom_fields', array() );

		// Check for an explicit setting.
		if ( isset( $fieldSettings[ $key ] ) ) {
			$mode = $fieldSettings[ $key ];

			if ( in_array( $mode, array( 'copy', 'translate', 'ignore' ), true ) ) {
				return $mode;
			}
		}

		return $this->getDefaultMode( $key );
	}

	/**
	 * Set the translation mode for a custom field.
	 *
	 * @param string $key  Meta key.
	 * @param string $mode One of "copy", "translate", "ignore".
	 * @return bool
	 */
	public function setFieldMode( string $key, string $mode ): bool {
		if ( ! in_array( $mode, array( 'copy', 'translate', 'ignore' ), true ) ) {
			return false;
		}

		$fieldSettings = $this->options->get( 'custom_fields', array() );

		$fieldSettings[ $key ] = $mode;

		return $this->options->set( 'custom_fields', $fieldSettings );
	}

	/**
	 * Get all custom fields configured with their mode.
	 *
	 * @return array<string, string> Associative array of meta_key => mode.
	 */
	public function getAllFieldSettings(): array {
		return $this->options->get( 'custom_fields', array() );
	}

	/**
	 * Determine the default translation mode for a meta key.
	 *
	 * WordPress internal fields (prefixed with "_") default to "copy"
	 * since they typically hold structural data (e.g. _thumbnail_id,
	 * _wp_page_template). All other fields default to "copy" as well,
	 * which is the safest default for a duplication workflow.
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	private function getDefaultMode( string $key ): string {
		return 'copy';
	}

	/**
	 * Check if a field should be copied during duplication.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public function shouldCopy( string $key ): bool {
		return 'copy' === $this->getFieldMode( $key );
	}

	/**
	 * Check if a field is translatable (holds language-specific content).
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public function isTranslatable( string $key ): bool {
		return 'translate' === $this->getFieldMode( $key );
	}

	/**
	 * Check if a field should be ignored entirely.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public function isIgnored( string $key ): bool {
		return 'ignore' === $this->getFieldMode( $key );
	}
}
