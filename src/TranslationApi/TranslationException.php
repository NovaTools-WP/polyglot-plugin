<?php
/**
 * Translation API exception for NovaTools Polyglot.
 *
 * Thrown when a translation provider API call fails due to network errors,
 * authentication issues, rate limits, or invalid responses.
 *
 * @package NovaTools\Polyglot\TranslationApi
 */

namespace NovaTools\Polyglot\TranslationApi;

defined( 'ABSPATH' ) || exit;

class TranslationException extends \Exception {

	/**
	 * HTTP status code from the API response, if available.
	 *
	 * @var int|null
	 */
	private ?int $statusCode;

	/**
	 * Provider identifier that threw the exception.
	 *
	 * @var string
	 */
	private string $providerId;

	/**
	 * Constructor.
	 *
	 * @param string   $message    Error description.
	 * @param int      $code       Exception code.
	 * @param \Throwable|null $previous Previous exception.
	 * @param int|null $statusCode HTTP status code from the API response.
	 * @param string   $providerId Provider that caused the error.
	 */
	public function __construct(
		string $message = '',
		int $code = 0,
		?\Throwable $previous = null,
		?int $statusCode = null,
		string $providerId = ''
	) {
		parent::__construct( $message, $code, $previous );
		$this->statusCode = $statusCode;
		$this->providerId = $providerId;
	}

	/**
	 * Get the HTTP status code, if available.
	 *
	 * @return int|null
	 */
	public function getStatusCode(): ?int {
		return $this->statusCode;
	}

	/**
	 * Check whether this exception was caused by a rate limit response.
	 *
	 * @return bool
	 */
	public function isRateLimit(): bool {
		return 429 === $this->statusCode;
	}

	/**
	 * Get the provider identifier that threw the exception.
	 *
	 * @return string
	 */
	public function getProviderId(): string {
		return $this->providerId;
	}

	/**
	 * Create an exception from a WordPress WP_Error.
	 *
	 * @param \WP_Error $error      The WordPress error object.
	 * @param string    $providerId Provider identifier.
	 * @return self
	 */
	public static function fromWpError( \WP_Error $error, string $providerId = '' ): self {
		return new self(
			$error->get_error_message(),
			0,
			null,
			null,
			$providerId
		);
	}

	/**
	 * Create an exception from an API response array.
	 *
	 * @param array  $response   WordPress HTTP API response array.
	 * @param string $providerId Provider identifier.
	 * @return self
	 */
	public static function fromResponse( array $response, string $providerId = '' ): self {
		$code    = wp_remote_retrieve_response_code( $response );
		$message = wp_remote_retrieve_response_message( $response );
		$body    = wp_remote_retrieve_body( $response );

		// Try to extract a more specific error from the JSON body.
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			// DeepL-style: { "message": "..." }
			if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
				$message = $decoded['message'];
			}
			// Google-style: { "error": { "message": "..." } }
			if ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
				$message = $decoded['error']['message'];
			}
			// OpenAI-style: { "error": { "message": "..." } }
			if ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
				$message = $decoded['error']['message'];
			}
		}

		return new self(
			sprintf( '%s (HTTP %d)', $message, $code ),
			0,
			null,
			$code,
			$providerId
		);
	}
}
