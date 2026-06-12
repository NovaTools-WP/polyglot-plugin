<?php
/**
 * REST API controller for batch auto-translation.
 *
 * Registers the `/polyglot/v1/auto-translate` POST endpoint that triggers
 * automatic translation of untranslated strings using a configured provider
 * (DeepL, Google Translate, OpenAI, etc.).
 *
 * @package NovaTools\Polyglot\RestApi
 */

namespace NovaTools\Polyglot\RestApi;

use NovaTools\Polyglot\Core\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

class AutoTranslateController {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'polyglot/v1';

	/**
	 * REST base for this controller.
	 *
	 * @var string
	 */
	const REST_BASE = 'auto-translate';

	/**
	 * Register the routes for this controller.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		// POST /polyglot/v1/auto-translate — batch auto-translate strings.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'createItem' ),
					'permission_callback' => array( $this, 'permissionsCheck' ),
					'args'                => $this->getCreateItemArgs(),
				),
			)
		);
	}

	/**
	 * Trigger batch auto-translation.
	 *
	 * Accepts either a target language (to translate all untranslated strings
	 * for that language) or a specific set of string IDs. An optional provider
	 * ID can be passed to override the configured default provider.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function createItem( WP_REST_Request $request ) {
		$plugin = Plugin::getInstance();

		if ( ! $plugin->has( 'auto.translator' ) ) {
			return new WP_Error(
				'polyglot_not_booted',
				__( 'Auto-translation service is not available.', 'novatools-polyglot' ),
				array( 'status' => 500 )
			);
		}

		$language   = sanitize_text_field( $request->get_param( 'language' ) );
		$providerId = sanitize_text_field( $request->get_param( 'provider' ) ) ?: null;
		$stringIds  = $request->get_param( 'string_ids' );

		if ( empty( $language ) ) {
			return new WP_Error(
				'polyglot_missing_language',
				__( 'Target language code is required.', 'novatools-polyglot' ),
				array( 'status' => 400 )
			);
		}

		// Validate the provider if specified.
		if ( null !== $providerId ) {
			$providerRegistry = $plugin->get( 'provider.registry' );

			if ( ! $providerRegistry->has( $providerId ) ) {
				return new WP_Error(
					'polyglot_invalid_provider',
					sprintf(
						/* translators: %s: provider ID */
						__( 'Translation provider "%s" is not registered.', 'novatools-polyglot' ),
						$providerId
					),
					array( 'status' => 400 )
				);
			}

			$provider = $providerRegistry->get( $providerId );

			if ( $provider && ! $provider->isConfigured() ) {
				return new WP_Error(
					'polyglot_provider_not_configured',
					sprintf(
						/* translators: %s: provider name */
						__( 'Translation provider "%s" is not configured. Please set the API key in settings.', 'novatools-polyglot' ),
						$providerId
					),
					array( 'status' => 400 )
				);
			}
		}

		/** @var \NovaTools\Polyglot\TranslationApi\AutoTranslator $autoTranslator */
		$autoTranslator = $plugin->get( 'auto.translator' );

		// Dispatch based on whether specific string IDs were provided.
		if ( is_array( $stringIds ) && ! empty( $stringIds ) ) {
			$ids = array_map( 'absint', $stringIds );
			$result = $autoTranslator->translateStrings( $ids, $language, $providerId );
		} else {
			$result = $autoTranslator->translateLanguage( $language, $providerId );
		}

		/**
		 * Fires after an auto-translation batch has been processed via REST API.
		 *
		 * @param string $language   Target language code.
		 * @param array  $result     Summary with translated, failed, skipped counts.
		 * @param string|null $providerId Provider ID used, or null for default.
		 */
		do_action( 'polyglot_auto_translated_rest', $language, $result, $providerId );

		return new WP_REST_Response(
			array(
				'language'   => $language,
				'provider'   => $providerId ?? ( $autoTranslator->getActiveProvider()?->getId() ?? 'none' ),
				'translated' => $result['translated'],
				'failed'     => $result['failed'],
				'skipped'    => $result['skipped'],
			),
			200
		);
	}

	/**
	 * Check if a given request has access to run auto-translation.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return true|WP_Error
	 */
	public function permissionsCheck( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'polyglot_rest_forbidden',
				__( 'Sorry, you are not allowed to run auto-translation.', 'novatools-polyglot' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Get the arguments for the auto-translate endpoint.
	 *
	 * @return array[]
	 */
	protected function getCreateItemArgs(): array {
		return array(
			'language' => array(
				'description'       => __( 'Target language code for auto-translation.', 'novatools-polyglot' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'provider' => array(
				'description'       => __( 'Translation provider ID (e.g. "deepl", "google", "openai"). Defaults to the configured provider.', 'novatools-polyglot' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'string_ids' => array(
				'description' => __( 'Optional array of specific string IDs to translate. Omit to translate all untranslated strings for the language.', 'novatools-polyglot' ),
				'type'        => 'array',
				'items'       => array(
					'type' => 'integer',
				),
			),
		);
	}
}
