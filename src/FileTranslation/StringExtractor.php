<?php
/**
 * Source string extractor for NovaTools Polyglot.
 *
 * Scans PHP and JavaScript source files for translatable string patterns
 * (`__()`, `_e()`, `_x()`, `_n()`, `esc_html__()`, etc.) and returns
 * structured extraction results suitable for POT template generation.
 *
 * Uses PHP's `token_get_all()` for accurate PHP parsing and regex-based
 * extraction for JavaScript files, following patterns proven in Loco
 * Translate's `Loco_gettext_Extraction` and the bundled `LocoPHPExtractor`.
 *
 * @package NovaTools\Polyglot\FileTranslation
 */

namespace NovaTools\Polyglot\FileTranslation;

defined( 'ABSPATH' ) || exit;

class StringExtractor {

	/**
	 * WordPress i18n function signatures.
	 *
	 * Each value is a positional code where each character maps to an argument:
	 *   s = source (msgid), p = plural (msgid_plural), c = context (msgctxt),
	 *   d = domain (text domain).
	 *
	 * For example, `'__' => 'sd'` means `__( 'source', 'domain' )`.
	 *
	 * @var array<string, string>
	 */
	const WP_FUNCTIONS = array(
		'__'               => 'sd',
		'_e'               => 'sd',
		'_c'               => 'sd',
		'_n'               => 'sp_d',
		'_n_noop'          => 'spd',
		'_nc'              => 'sp_d',
		'__ngettext'       => 'spd',
		'__ngettext_noop'  => 'spd',
		'_x'               => 'scd',
		'_ex'              => 'scd',
		'_nx'              => 'sp_cd',
		'_nx_noop'         => 'spcd',
		'esc_attr__'       => 'sd',
		'esc_html__'       => 'sd',
		'esc_attr_e'       => 'sd',
		'esc_html_e'       => 'sd',
		'esc_attr_x'       => 'scd',
		'esc_html_x'       => 'scd',
	);

	/**
	 * Regex pattern for extracting i18n calls from JavaScript.
	 *
	 * Matches patterns like `__( 'text', 'domain' )` and `_n( 'one', 'many', count, 'domain' )`.
	 *
	 * @var string
	 */
	const JS_PATTERN = '/\b(__|_e|_x|_n|_nx|esc_attr__|esc_html__|esc_attr_e|esc_html_e|esc_attr_x|esc_html_x|_n_noop|_nx_noop)\s*\(([^)]*)\)/s';

	/**
	 * File extensions that contain PHP source.
	 *
	 * @var string[]
	 */
	const PHP_EXTENSIONS = array( 'php', 'phtml' );

	/**
	 * File extensions that contain JavaScript source.
	 *
	 * @var string[]
	 */
	const JS_EXTENSIONS = array( 'js', 'jsx', 'ts', 'tsx', 'mjs' );

	/**
	 * Directories to skip during extraction.
	 *
	 * @var string[]
	 */
	const SKIP_DIRS = array(
		'node_modules',
		'vendor',
		'.git',
		'.svn',
		'__pycache__',
		'.cache',
		'dist',
		'build',
	);

	/**
	 * Extract translatable strings from a source directory.
	 *
	 * @param string      $directory Root directory to scan.
	 * @param string      $domain    Text domain to filter by (empty = include all).
	 * @param string|null $base_path Optional base path for relative references.
	 * @return array{
	 *     strings: array<string, array{
	 *         msgid: string,
	 *         msgid_plural: string,
	 *         msgctxt: string,
	 *         references: string[],
	 *         comments: string
	 *     }>,
	 *     domain_counts: array<string, int>,
	 *     total: int
	 * }
	 */
	public function extract( string $directory, string $domain = '', ?string $base_path = null ): array {
		if ( null === $base_path ) {
			$base_path = $directory;
		}

		$files   = $this->findSourceFiles( $directory );
		$results = array(
			'strings'        => array(),
			'domain_counts'  => array(),
			'total'          => 0,
		);

		foreach ( $files as $file ) {
			$relative = $this->relativePath( $file, $base_path );
			$ext      = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

			if ( in_array( $ext, self::PHP_EXTENSIONS, true ) ) {
				$found = $this->extractFromPhp( $file, $relative, $domain );
			} elseif ( in_array( $ext, self::JS_EXTENSIONS, true ) ) {
				$found = $this->extractFromJs( $file, $relative, $domain );
			} else {
				continue;
			}

			foreach ( $found as $entry ) {
				$key = $this->entryKey( $entry );

				if ( isset( $results['strings'][ $key ] ) ) {
					// Merge references into existing entry.
					$results['strings'][ $key ]['references'] = array_unique(
						array_merge(
							$results['strings'][ $key ]['references'],
							$entry['references']
						)
					);
				} else {
					$results['strings'][ $key ] = $entry;
				}

				$entry_domain = $entry['domain'] ?: 'default';
				if ( ! isset( $results['domain_counts'][ $entry_domain ] ) ) {
					$results['domain_counts'][ $entry_domain ] = 0;
				}
				$results['domain_counts'][ $entry_domain ]++;
				$results['total']++;
			}
		}

		return $results;
	}

	/**
	 * Extract translatable strings from a PHP file using the tokenizer.
	 *
	 * Walks the token stream looking for known i18n function calls and
	 * collects their string literal arguments.
	 *
	 * @param string $file       Absolute file path.
	 * @param string $fileref    Relative file reference for PO output.
	 * @param string $filter_domain If non-empty, only include strings for this domain.
	 * @return array[] Extracted entries.
	 */
	private function extractFromPhp( string $file, string $fileref, string $filter_domain = '' ): array {
		$content = file_get_contents( $file );

		if ( false === $content ) {
			return array();
		}

		$tokens = token_get_all( $content );
		$found  = array();

		$count = count( $tokens );

		// Stack-based parser to handle nested function calls like sprintf( __( ... ) ).
		// Each stack frame: [ 'rule' => string, 'args' => array, 'arg_idx' => int, 'line_ref' => string, 'depth' => int ]
		$stack   = array();
		$comment = '';

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( is_string( $token ) ) {
				$char = $token;

				if ( ! empty( $stack ) ) {
					$top = count( $stack ) - 1;

					if ( ')' === $char || ']' === $char ) {
						--$stack[ $top ]['depth'];
						if ( 0 === $stack[ $top ]['depth'] ) {
							$frame = $stack[ $top ];
							array_splice( $stack, $top, 1 );

							$entry = $this->buildEntry( $frame['rule'], $frame['args'], $comment, $frame['line_ref'] );
							if ( null !== $entry ) {
								if ( '' === $filter_domain || $filter_domain === $entry['domain'] ) {
									$found[] = $entry;
								}
							}
							$comment = '';
						}
					} elseif ( '(' === $char || '[' === $char ) {
						++$stack[ $top ]['depth'];
					} elseif ( 1 === $stack[ $top ]['depth'] && ',' === $char ) {
						++$stack[ $top ]['arg_idx'];
					}
				}
			} else {
				$type = $token[0];
				$text = $token[1];

				$top = count( $stack ) - 1;

				if ( ! empty( $stack ) && 1 === $stack[ $top ]['depth'] ) {
					if ( T_CONSTANT_ENCAPSED_STRING === $type ) {
						$stack[ $top ]['args'][ $stack[ $top ]['arg_idx'] ] = $this->decodeString( $text );
					}
				} elseif ( T_COMMENT === $type || T_DOC_COMMENT === $type ) {
					if ( preg_match( '/translators:/i', $text ) ) {
						$comment = preg_replace( '!^\s*\*?\s*!m', '', $text );
						$comment = trim( $comment );
					}
				} elseif ( T_STRING === $type && isset( self::WP_FUNCTIONS[ $text ] ) ) {
					$next = $i + 1;
					while ( $next < $count && is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) {
						++$next;
					}

					if ( $next < $count && '(' === $tokens[ $next ] ) {
						$stack[] = array(
							'rule'     => self::WP_FUNCTIONS[ $text ],
							'args'     => array(),
							'arg_idx'  => 0,
							'line_ref' => $fileref . ':' . $token[2],
							'depth'    => 0,
						);
					}
				}
			}
		}

		return $found;
	}

	/**
	 * Extract translatable strings from a JavaScript file using regex.
	 *
	 * @param string $file          Absolute file path.
	 * @param string $fileref       Relative file reference for PO output.
	 * @param string $filter_domain If non-empty, only include strings for this domain.
	 * @return array[] Extracted entries.
	 */
	private function extractFromJs( string $file, string $fileref, string $filter_domain = '' ): array {
		$content = file_get_contents( $file );

		if ( false === $content ) {
			return array();
		}

		$found = array();

		if ( ! preg_match_all( self::JS_PATTERN, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $found;
		}

		$line_number_map = $this->buildLineMap( $content );

		foreach ( $matches as $match ) {
			$func_name = $match[1][0];
			$all_args  = $match[2][0];
			$offset    = $match[0][1];

			if ( ! isset( self::WP_FUNCTIONS[ $func_name ] ) ) {
				continue;
			}

			$rule = self::WP_FUNCTIONS[ $func_name ];
			$args = $this->parseJsArgs( $all_args );

			$line = $this->getLineAtOffset( $line_number_map, $offset );
			$ref  = $fileref . ':' . $line;

			$entry = $this->buildEntry( $rule, $args, '', $ref );

			if ( null !== $entry ) {
				if ( '' === $filter_domain || $filter_domain === $entry['domain'] ) {
					$found[] = $entry;
				}
			}
		}

		return $found;
	}

	/**
	 * Build a structured extraction entry from function call arguments.
	 *
	 * @param string   $rule    Positional rule string (e.g. "scd").
	 * @param array    $args    Parsed function arguments.
	 * @param string   $comment Translator comment.
	 * @param string   $ref     File:line reference.
	 * @return array|null Entry data or null if the source string is missing.
	 */
	private function buildEntry( string $rule, array $args, string $comment, string $ref ): ?array {
		$s = strpos( $rule, 's' );
		$p = strpos( $rule, 'p' );
		$c = strpos( $rule, 'c' );
		$d = strpos( $rule, 'd' );

		// Source string is required.
		if ( false === $s || ! isset( $args[ $s ] ) || ! is_string( $args[ $s ] ) ) {
			return null;
		}

		$msgid = $args[ $s ];

		// Skip empty source strings.
		if ( '' === $msgid ) {
			return null;
		}

		$entry = array(
			'msgid'         => $msgid,
			'msgid_plural'  => '',
			'msgctxt'       => '',
			'references'    => array(),
			'comments'      => $comment,
			'domain'        => '',
		);

		// Context.
		if ( false !== $c && isset( $args[ $c ] ) && is_string( $args[ $c ] ) ) {
			$entry['msgctxt'] = $args[ $c ];
		}

		// Plural.
		if ( false !== $p && isset( $args[ $p ] ) && is_string( $args[ $p ] ) ) {
			$entry['msgid_plural'] = $args[ $p ];
		}

		// Domain.
		if ( false !== $d && array_key_exists( $d, $args ) ) {
			$entry['domain'] = is_string( $args[ $d ] ) ? $args[ $d ] : '';
		}

		// Reference.
		if ( '' !== $ref ) {
			$entry['references'][] = $ref;
		}

		return $entry;
	}

	/**
	 * Decode a PHP string literal (remove quotes and unescape).
	 *
	 * @param string $token Raw token text (e.g. "'hello'" or '"world"').
	 * @return string Decoded string value.
	 */
	private function decodeString( string $token ): string {
		// Strip surrounding quotes.
		if ( strlen( $token ) >= 2 ) {
			$first  = $token[0];
			$last   = $token[ strlen( $token ) - 1 ];

			if ( ( "'" === $first && "'" === $last ) || ( '"' === $first && '"' === $last ) ) {
				$token = substr( $token, 1, -1 );
			}
		}

		return $token;
	}

	/**
	 * Parse JavaScript function arguments from a raw argument string.
	 *
	 * Handles single and double-quoted string literals, separated by commas.
	 *
	 * @param string $args_str Raw argument string (e.g. "'text', 'domain'").
	 * @return array Parsed arguments (strings or null).
	 */
	private function parseJsArgs( string $args_str ): array {
		$args  = array();
		$depth = 0;
		$current = '';
		$in_string = false;
		$quote_char = '';
		$len = strlen( $args_str );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $args_str[ $i ];

			if ( $in_string ) {
				if ( '\\' === $char && $i + 1 < $len ) {
					$current .= $char . $args_str[ ++$i ];
				} elseif ( $quote_char === $char ) {
					$current .= $char;
					$in_string = false;
				} else {
					$current .= $char;
				}
			} else {
				if ( "'" === $char || '"' === $char ) {
					$in_string  = true;
					$quote_char = $char;
					$current   .= $char;
				} elseif ( '(' === $char || '[' === $char || '{' === $char ) {
					++$depth;
				} elseif ( ')' === $char || ']' === $char || '}' === $char ) {
					--$depth;
				} elseif ( ',' === $char && 0 === $depth ) {
					$args[] = $this->cleanJsArg( $current );
					$current = '';
				} else {
					$current .= $char;
				}
			}
		}

		// Don't forget the last argument.
		if ( '' !== trim( $current ) ) {
			$args[] = $this->cleanJsArg( $current );
		}

		return $args;
	}

	/**
	 * Clean a JavaScript argument value: strip quotes and whitespace.
	 *
	 * @param string $arg Raw argument.
	 * @return string|null Cleaned string or null if not a string literal.
	 */
	private function cleanJsArg( string $arg ): ?string {
		$arg = trim( $arg );

		if ( preg_match( '/^([\'"])(.*)\1$/s', $arg, $m ) ) {
			return stripslashes( $m[2] );
		}

		// Non-string argument.
		return null;
	}

	/**
	 * Build a character-offset to line-number map.
	 *
	 * @param string $content File content.
	 * @return int[] Array of line-start offsets.
	 */
	private function buildLineMap( string $content ): array {
		$map   = array();
		$map[] = 0;

		$offset = 0;
		$len    = strlen( $content );

		while ( $offset < $len ) {
			$nl = strpos( $content, "\n", $offset );
			if ( false === $nl ) {
				break;
			}
			$map[] = $nl + 1;
			$offset = $nl + 1;
		}

		return $map;
	}

	/**
	 * Get the line number for a character offset using a pre-built line map.
	 *
	 * @param int[] $line_map Line-start offsets from `buildLineMap()`.
	 * @param int   $offset   Character offset.
	 * @return int Line number (1-based).
	 */
	private function getLineAtOffset( array $line_map, int $offset ): int {
		$lo = 0;
		$hi = count( $line_map ) - 1;

		while ( $lo <= $hi ) {
			$mid = (int) ( ( $lo + $hi ) / 2 );
			if ( $line_map[ $mid ] <= $offset ) {
				$lo = $mid + 1;
			} else {
				$hi = $mid - 1;
			}
		}

		return $hi + 1;
	}

	/**
	 * Recursively find PHP and JS source files in a directory.
	 *
	 * @param string $directory Directory to scan.
	 * @return string[] Absolute file paths.
	 */
	private function findSourceFiles( string $directory ): array {
		$files   = array();
		$entries = array();

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS )
			);
		} catch ( \UnexpectedValueException $e ) {
			return $files;
		}

		foreach ( $iterator as $file_info ) {
			/** @var \SplFileInfo $file_info */
			if ( ! $file_info->isFile() ) {
				continue;
			}

			// Skip excluded directories.
			$path = $file_info->getPathname();
			foreach ( self::SKIP_DIRS as $skip ) {
				if ( str_contains( $path, '/' . $skip . '/' ) || str_contains( $path, '\\' . $skip . '\\' ) ) {
					continue 2;
				}
			}

			$ext = strtolower( $file_info->getExtension() );
			if ( in_array( $ext, self::PHP_EXTENSIONS, true ) || in_array( $ext, self::JS_EXTENSIONS, true ) ) {
				$files[] = $path;
			}
		}

		return $files;
	}

	/**
	 * Compute a relative path from an absolute path against a base.
	 *
	 * @param string $path Absolute file path.
	 * @param string $base Base directory path.
	 * @return string Relative path.
	 */
	private function relativePath( string $path, string $base ): string {
		$base = rtrim( str_replace( '\\', '/', $base ), '/' ) . '/';
		$path = str_replace( '\\', '/', $path );

		if ( 0 === strpos( $path, $base ) ) {
			return substr( $path, strlen( $base ) );
		}

		return $path;
	}

	/**
	 * Build a unique key for a translation entry (for deduplication).
	 *
	 * @param array $entry Extracted entry.
	 * @return string Unique key combining domain, context, and msgid.
	 */
	private function entryKey( array $entry ): string {
		return $entry['domain'] . "\x04" . $entry['msgctxt'] . "\x04" . $entry['msgid'];
	}
}
