<?php
/**
 * Translation provider registry for NovaTools Polyglot.
 *
 * Manages available translation providers. Built-in providers (DeepL, Google,
 * OpenAI) are registered on construction. Third-party providers can be added
 * via the `polyglot_translation_providers` filter.
 *
 * @package NovaTools\Polyglot\TranslationApi
 */

namespace NovaTools\Polyglot\TranslationApi;

use NovaTools\Polyglot\Support\HookManager;
use NovaTools\Polyglot\Support\OptionStore;

defined( 'ABSPATH' ) || exit;

class ProviderRegistry {

	/**
	 * Hook manager for applying the providers filter.
	 *
	 * @var HookManager
	 */
	private HookManager $hooks;

	/**
	 * Settings store for provider API key retrieval.
	 *
	 * @var OptionStore
	 */
	private OptionStore $options;

	/**
	 * Cached array of instantiated providers keyed by their ID.
	 *
	 * @var array<string, TranslationProviderInterface>|null
	 */
	private ?array $providers = null;

	/**
	 * Constructor.
	 *
	 * @param HookManager  $hooks   Hook manager for filter integration.
	 * @param OptionStore  $options Settings store for API key retrieval.
	 */
	public function __construct( HookManager $hooks, OptionStore $options ) {
		$this->hooks   = $hooks;
		$this->options = $options;
	}

	/**
	 * Get all registered providers, instantiating them on first call.
	 *
	 * Built-in providers are always available. Third-party providers
	 * are added via the `polyglot_translation_providers` filter.
	 *
	 * @return array<string, TranslationProviderInterface> Providers keyed by ID.
	 */
	public function all(): array {
		if ( null !== $this->providers ) {
			return $this->providers;
		}

		$options = $this->getOptions();

		$builtin = array(
			DeepLProvider::ID             => new DeepLProvider( $options ),
			GoogleTranslateProvider::ID   => new GoogleTranslateProvider( $options ),
			OpenAIProvider::ID            => new OpenAIProvider( $options ),
		);

		/**
		 * Filter the registered translation providers.
		 *
		 * Third-party plugins can use this filter to register custom
		 * translation providers that implement TranslationProviderInterface.
		 *
		 * @param array<string, TranslationProviderInterface> $providers Provider instances keyed by ID.
		 */
		$filtered = $this->hooks->applyFilters( 'polyglot_translation_providers', $builtin );

		$this->providers = array();

		foreach ( $filtered as $provider ) {
			if ( $provider instanceof TranslationProviderInterface ) {
				$this->providers[ $provider->getId() ] = $provider;
			}
		}

		return $this->providers;
	}

	/**
	 * Get a single provider by its identifier.
	 *
	 * @param string $id Provider identifier (e.g. 'deepl', 'google', 'openai').
	 * @return TranslationProviderInterface|null The provider, or null if not registered.
	 */
	public function get( string $id ): ?TranslationProviderInterface {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Check whether a provider with the given ID is registered.
	 *
	 * @param string $id Provider identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->all()[ $id ] );
	}

	/**
	 * Get only the providers that are correctly configured (have API keys set).
	 *
	 * @return array<string, TranslationProviderInterface>
	 */
	public function getConfigured(): array {
		return array_filter( $this->all(), static function ( TranslationProviderInterface $provider ): bool {
			return $provider->isConfigured();
		} );
	}

	/**
	 * Get provider identifiers and display names for admin settings.
	 *
	 * @return array<string, string> Map of ID → human-readable name.
	 */
	public function getLabels(): array {
		$labels = array();

		foreach ( $this->all() as $id => $provider ) {
			$labels[ $id ] = $provider->getName();
		}

		return $labels;
	}

	/**
	 * Manually register a provider at runtime.
	 *
	 * Useful for programmatic registration outside of the filter hook.
	 *
	 * @param TranslationProviderInterface $provider The provider to register.
	 * @return void
	 */
	public function register( TranslationProviderInterface $provider ): void {
		$this->providers = null; // Invalidate cache.
		$this->hooks->addFilter( 'polyglot_translation_providers', static function ( array $providers ) use ( $provider ): array {
			$providers[ $provider->getId() ] = $provider;
			return $providers;
		} );
	}

	/**
	 * Get the default provider based on settings.
	 *
	 * Returns the configured default provider if available and configured,
	 * otherwise falls back to the first configured provider.
	 *
	 * @return TranslationProviderInterface|null
	 */
	public function getDefaultProvider(): ?TranslationProviderInterface {
		$configured = $this->getConfigured();

		if ( empty( $configured ) ) {
			return null;
		}

		$defaultId = (string) $this->getOptions()->get( 'api.default_provider', '' );

		if ( '' !== $defaultId && isset( $configured[ $defaultId ] ) ) {
			return $configured[ $defaultId ];
		}

		return reset( $configured ) ?: null;
	}

	/**
	 * Flush the cached provider instances.
	 *
	 * Called when API keys or settings change to force re-instantiation.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->providers = null;
	}

	/**
	 * Get the OptionStore instance.
	 *
	 * @return OptionStore
	 */
	private function getOptions(): OptionStore {
		return $this->options;
	}
}
