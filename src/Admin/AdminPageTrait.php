<?php
/**
 * Shared helper methods for NovaTools Polyglot admin pages.
 *
 * Provides common service-access helpers used across multiple admin page
 * classes: active language retrieval, default language, and provider checks.
 *
 * @package NovaTools\Polyglot\Admin
 */

namespace NovaTools\Polyglot\Admin;

use NovaTools\Polyglot\Core\Plugin;

defined( 'ABSPATH' ) || exit;

trait AdminPageTrait {

	/**
	 * Get active languages from the language repository.
	 *
	 * Returns an empty array when the service is unavailable.
	 *
	 * @return array Language objects keyed by code.
	 */
	private function getActiveLanguages(): array {
		try {
			if ( $this->plugin->has( 'language.repository' ) ) {
				return $this->plugin->get( 'language.repository' )->getActive();
			}
		} catch ( \Throwable ) {
			// Fall through.
		}

		return array();
	}

	/**
	 * Get the default language.
	 *
	 * @return object|null
	 */
	private function getDefaultLanguage(): ?object {
		try {
			if ( $this->plugin->has( 'language.repository' ) ) {
				return $this->plugin->get( 'language.repository' )->getDefault();
			}
		} catch ( \Throwable ) {
			// Fall through.
		}

		return null;
	}

	/**
	 * Get all languages (active and inactive) from the repository.
	 *
	 * @return array
	 */
	private function getAllLanguages(): array {
		try {
			if ( $this->plugin->has( 'language.repository' ) ) {
				return $this->plugin->get( 'language.repository' )->getAll();
			}
		} catch ( \Throwable ) {
			// Fall through.
		}

		return array();
	}
}
