<?php
/**
 * PO editor AJAX controller for NovaTools Polyglot.
 *
 * Provides WordPress AJAX endpoints for in-browser PO file editing:
 *   - Load PO file contents for the editor.
 *   - Save updated translations back to the PO file.
 *   - Compile PO -> MO/PHP/JSON after save.
 *
 * All endpoints require `manage_options` capability.
 *
 * @package NovaTools\Polyglot\FileTranslation\Editor
 */

namespace NovaTools\Polyglot\FileTranslation\Editor;

use NovaTools\Polyglot\FileTranslation\PoFileParser;
use NovaTools\Polyglot\FileTranslation\PoMoCompiler;

defined( 'ABSPATH' ) || exit;

class PoEditorController {

	/**
	 * Nonce action string for AJAX requests.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'polyglot_po_editor';

	/**
	 * PO file parser instance.
	 *
	 * @var PoFileParser
	 */
	private PoFileParser $parser;

	/**
	 * PO/MO compiler instance.
	 *
	 * @var PoMoCompiler
	 */
	private PoMoCompiler $compiler;

	/**
	 * Constructor.
	 *
	 * @param PoFileParser  $parser   PO file parser.
	 * @param PoMoCompiler  $compiler MO/PHP/JSON compiler.
	 */
	public function __construct( PoFileParser $parser, PoMoCompiler $compiler ) {
		$this->parser   = $parser;
		$this->compiler = $compiler;
	}

	/**
	 * Register AJAX actions.
	 *
	 * Hooks into `wp_ajax_*` for authenticated admin users.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_polyglot_po_load', array( $this, 'ajaxLoad' ) );
		add_action( 'wp_ajax_polyglot_po_save', array( $this, 'ajaxSave' ) );
		add_action( 'wp_ajax_polyglot_po_compile', array( $this, 'ajaxCompile' ) );
	}

	/**
	 * AJAX handler: Load a PO file for editing.
	 *
	 * Expects POST parameters:
	 *   - `nonce`   -- Security nonce.
	 *   - `file`    -- Absolute path to the PO file (validated against allowed dirs).
	 *   - `domain`  -- Text domain.
	 *
	 * Returns JSON:
	 *   - `success` -- Boolean.
	 *   - `data`    -- Parsed PO structure (headers + entries) on success.
	 *   - `message` -- Error message on failure.
	 *
	 * @return void
	 */
	public function ajaxLoad(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'novatools-polyglot' ) ), 403 );
		}

		$file = sanitize_text_field( wp_unslash( $_POST['file'] ?? '' ) );

		if ( '' === $file ) {
			wp_send_json_error( array( 'message' => __( 'File path is required.', 'novatools-polyglot' ) ) );
		}

		// Validate the file path is within allowed translation directories.
		if ( ! $this->isAllowedPath( $file ) ) {
			wp_send_json_error( array( 'message' => __( 'File path is not in an allowed directory.', 'novatools-polyglot' ) ) );
		}

		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			wp_send_json_error( array( 'message' => __( 'PO file not found or unreadable.', 'novatools-polyglot' ) ) );
		}

		try {
			$data = $this->parser->parse( $file );

			wp_send_json_success( array(
				'headers'      => $data['headers'],
				'entries'      => $data['entries'],
				'file'         => $file,
				'total'        => count( $data['entries'] ),
				'translated'   => $this->countTranslated( $data['entries'] ),
				'untranslated' => $this->countUntranslated( $data['entries'] ),
			) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX handler: Save translations to a PO file.
	 *
	 * Expects POST parameters:
	 *   - `nonce`    -- Security nonce.
	 *   - `file`     -- Absolute path to the PO file.
	 *   - `domain`   -- Text domain.
	 *   - `locale`   -- WordPress locale.
	 *   - `entries`  -- JSON-encoded array of entries with updated msgstr values.
	 *   - `headers`  -- JSON-encoded array of updated headers (optional).
	 *   - `compile`  -- Whether to compile MO/PHP/JSON after save (default true).
	 *
	 * Returns JSON:
	 *   - `success`  -- Boolean.
	 *   - `data`     -- Compilation results on success.
	 *   - `message`  -- Error message on failure.
	 *
	 * @return void
	 */
	public function ajaxSave(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'novatools-polyglot' ) ), 403 );
		}

		$file   = sanitize_text_field( wp_unslash( $_POST['file'] ?? '' ) );
		$domain = sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) );
		$locale = sanitize_text_field( wp_unslash( $_POST['locale'] ?? '' ) );
		$should_compile = filter_var( $_POST['compile'] ?? true, FILTER_VALIDATE_BOOLEAN );

		if ( '' === $file || '' === $domain ) {
			wp_send_json_error( array( 'message' => __( 'File path and domain are required.', 'novatools-polyglot' ) ) );
		}

		if ( ! $this->isAllowedPath( $file ) ) {
			wp_send_json_error( array( 'message' => __( 'File path is not in an allowed directory.', 'novatools-polyglot' ) ) );
		}

		// Decode entries JSON.
		$entries_json = wp_unslash( $_POST['entries'] ?? '[]' );
		$entries      = json_decode( $entries_json, true );

		if ( ! is_array( $entries ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid entries data.', 'novatools-polyglot' ) ) );
		}

		// Decode headers JSON (optional).
		$headers_json = wp_unslash( $_POST['headers'] ?? '{}' );
		$headers      = json_decode( $headers_json, true );
		if ( ! is_array( $headers ) ) {
			$headers = array();
		}

		try {
			// Generate PO file content.
			$po_content = $this->parser->generate( $headers, $entries );

			// Write PO file.
			$written = file_put_contents( $file, $po_content );

			if ( false === $written ) {
				wp_send_json_error( array( 'message' => __( 'Failed to write PO file.', 'novatools-polyglot' ) ) );
			}

			$result = array(
				'po_file' => $file,
				'bytes'   => $written,
				'compiled' => false,
			);

			// Compile MO/PHP/JSON if requested.
			if ( $should_compile && '' !== $locale ) {
				$po_data = array(
					'headers' => $headers,
					'entries' => $entries,
				);

				$compilation = $this->compiler->compileAll( $po_data, $file, $domain, $locale );
				$result['compiled']    = true;
				$result['compilation'] = array(
					'mo'   => $compilation['mo'],
					'php'  => $compilation['php'],
					'json' => count( $compilation['json'] ),
				);
			}

			wp_send_json_success( $result );

		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX handler: Compile an existing PO file to MO/PHP/JSON.
	 *
	 * Expects POST parameters:
	 *   - `nonce`   -- Security nonce.
	 *   - `file`    -- Absolute path to the PO file.
	 *   - `domain`  -- Text domain.
	 *   - `locale`  -- WordPress locale.
	 *
	 * @return void
	 */
	public function ajaxCompile(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'novatools-polyglot' ) ), 403 );
		}

		$file   = sanitize_text_field( wp_unslash( $_POST['file'] ?? '' ) );
		$domain = sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) );
		$locale = sanitize_text_field( wp_unslash( $_POST['locale'] ?? '' ) );

		if ( '' === $file || '' === $domain || '' === $locale ) {
			wp_send_json_error( array( 'message' => __( 'File path, domain, and locale are required.', 'novatools-polyglot' ) ) );
		}

		if ( ! $this->isAllowedPath( $file ) ) {
			wp_send_json_error( array( 'message' => __( 'File path is not in an allowed directory.', 'novatools-polyglot' ) ) );
		}

		try {
			$po_data = $this->parser->parse( $file );
			$compilation = $this->compiler->compileAll( $po_data, $file, $domain, $locale );

			wp_send_json_success( array(
				'mo'   => $compilation['mo'],
				'php'  => $compilation['php'],
				'json' => count( $compilation['json'] ),
				'json_files' => $compilation['json'],
			) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Check whether a file path is within an allowed translation directory.
	 *
	 * Only paths inside `wp-content/languages/`, plugin bundled `languages/`
	 * directories, or theme bundled `languages/` directories are allowed.
	 *
	 * @param string $path Absolute file path to validate.
	 * @return bool
	 */
	private function isAllowedPath( string $path ): bool {
		$path = wp_normalize_path( $path );

		// Prevent directory traversal using '..'
		if ( false !== strpos( $path, '..' ) ) {
			return false;
		}

		$allowed_roots = array(
			wp_normalize_path( WP_LANG_DIR ),
			wp_normalize_path( WP_PLUGIN_DIR ),
			wp_normalize_path( WP_CONTENT_DIR . '/themes' ),
		);

		foreach ( $allowed_roots as $root ) {
			if ( 0 === strpos( $path, $root ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Count translated entries (where msgstr[0] is non-empty).
	 *
	 * @param array $entries PO entries.
	 * @return int
	 */
	private function countTranslated( array $entries ): int {
		$count = 0;

		foreach ( $entries as $entry ) {
			$msgstr = $entry['msgstr'][0] ?? '';
			if ( '' !== $msgstr ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Count untranslated entries (where msgstr[0] is empty).
	 *
	 * @param array $entries PO entries.
	 * @return int
	 */
	private function countUntranslated( array $entries ): int {
		return count( $entries ) - $this->countTranslated( $entries );
	}
}
