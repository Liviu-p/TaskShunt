<?php
/**
 * File item handler for the receiver API.
 *
 * @package TaskShunt\Api\Handlers
 */

declare(strict_types=1);

namespace TaskShunt\Api\Handlers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Domain\RelativePath;
use TaskShunt\Domain\TaskAction;

/**
 * Applies a single file change on the receiver site.
 *
 * Sender payload format:
 *  {
 *      "path":   "theme/style.css" | "mu-plugins/foo.php",
 *      "action": "create" | "update" | "delete",
 *      "data":   "<base64 contents>"   // omitted for delete
 *  }
 *
 * The "theme/" prefix maps to the receiver's active stylesheet directory and
 * "mu-plugins/" maps to WPMU_PLUGIN_DIR. Files outside these prefixes, files
 * with non-allowlisted extensions, and paths that resolve outside the base
 * directory are rejected.
 */
final class FileHandler {

	/**
	 * Extensions accepted on the receiver side. Mirrors FileScanner's allowlist
	 * so the receiver enforces its own policy without coupling to the sender.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_EXTENSIONS = array( 'php', 'css', 'js', 'json', 'html', 'htm', 'txt', 'svg', 'twig' );

	/**
	 * Maximum decoded file size accepted on write.
	 */
	private const MAX_FILE_BYTES = 5 * 1024 * 1024;

	/**
	 * Process a file item.
	 *
	 * @param TaskAction $action      The action to perform.
	 * @param string     $object_type File category (always "file" for FileScanner items).
	 * @param int        $object_id   Unused — file items are keyed by path.
	 * @param mixed      $payload     Decoded payload data.
	 * @return array{success: bool, message: string}
	 */
	public function handle( TaskAction $action, string $object_type, int $object_id, mixed $payload ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( ! is_array( $payload ) ) {
			return $this->error( __( 'Invalid file payload.', 'taskshunt' ) );
		}

		$relative = (string) ( $payload['path'] ?? '' );

		try {
			$rel = new RelativePath( $relative );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( $e->getMessage() );
		}

		$resolved = $this->resolve_target( $rel->get_value() );
		if ( null === $resolved ) {
			/* translators: %s: relative file path */
			return $this->error( sprintf( __( 'Unsupported file location: %s.', 'taskshunt' ), $rel->get_value() ) );
		}

		[ $base_dir, $abs_path ] = $resolved;

		$ext = strtolower( pathinfo( $abs_path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
			/* translators: %s: file extension */
			return $this->error( sprintf( __( 'Disallowed file extension: %s.', 'taskshunt' ), $ext ) );
		}

		if ( ! $this->is_within_base( $abs_path, $base_dir ) ) {
			return $this->error( __( 'Resolved file path escapes the allowed directory.', 'taskshunt' ) );
		}

		if ( TaskAction::Delete === $action ) {
			return $this->apply_delete( $abs_path, $rel->get_value() );
		}

		return $this->apply_write( $payload, $abs_path, $rel->get_value() );
	}

	/**
	 * Map a "label/sub/path" relative path onto an absolute target on the receiver.
	 *
	 * @param string $relative Validated relative path (no traversal, no leading slash).
	 * @return array{0: string, 1: string}|null Tuple of [base_dir, abs_path], or null if unsupported.
	 */
	private function resolve_target( string $relative ): ?array {
		$parts = explode( '/', $relative, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[1] ) {
			return null;
		}

		[ $label, $sub_path ] = $parts;

		$base = match ( $label ) {
			'theme'      => get_stylesheet_directory(),
			'mu-plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
			default      => '',
		};

		if ( '' === $base ) {
			return null;
		}

		$base = rtrim( $base, '/\\' );

		return array( $base, $base . '/' . $sub_path );
	}

	/**
	 * Verify the absolute path resolves inside the base directory.
	 *
	 * RelativePath already rejects "..", but we re-check the realpath of the
	 * deepest existing ancestor to defend against symlink games on the receiver.
	 *
	 * @param string $abs_path Candidate absolute path (file may not yet exist).
	 * @param string $base_dir Allowed base directory.
	 * @return bool
	 */
	private function is_within_base( string $abs_path, string $base_dir ): bool {
		$real_base = realpath( $base_dir );
		if ( false === $real_base ) {
			return false;
		}

		$real_base = rtrim( $real_base, '/\\' );

		// Walk up to the first ancestor that exists, so we can resolve symlinks safely.
		$ancestor = $abs_path;
		while ( '' !== $ancestor && '/' !== $ancestor && ! file_exists( $ancestor ) ) {
			$parent = dirname( $ancestor );
			if ( $parent === $ancestor ) {
				break;
			}
			$ancestor = $parent;
		}

		$real_ancestor = realpath( $ancestor );
		if ( false === $real_ancestor ) {
			return false;
		}

		return $real_ancestor === $real_base
			|| str_starts_with( $real_ancestor, $real_base . '/' )
			|| str_starts_with( $real_ancestor, $real_base . DIRECTORY_SEPARATOR );
	}

	/**
	 * Decode and write the file contents to disk, creating parent directories as needed.
	 *
	 * @param array<string, mixed> $payload  Decoded payload data.
	 * @param string               $abs_path Target absolute path.
	 * @param string               $relative Relative path for the success message.
	 * @return array{success: bool, message: string}
	 */
	private function apply_write( array $payload, string $abs_path, string $relative ): array {
		if ( ! array_key_exists( 'data', $payload ) || ! is_string( $payload['data'] ) ) {
			return $this->error( __( 'File payload missing contents.', 'taskshunt' ) );
		}

		$decoded = base64_decode( $payload['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded ) {
			return $this->error( __( 'Failed to decode file contents.', 'taskshunt' ) );
		}

		if ( strlen( $decoded ) > self::MAX_FILE_BYTES ) {
			return $this->error( __( 'File exceeds the receiver size limit.', 'taskshunt' ) );
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			if ( ! WP_Filesystem() ) {
				return $this->error( __( 'Filesystem unavailable on receiver.', 'taskshunt' ) );
			}
		}

		$parent_dir = dirname( $abs_path );
		if ( ! $wp_filesystem->is_dir( $parent_dir ) && ! wp_mkdir_p( $parent_dir ) ) {
			return $this->error( __( 'Failed to create parent directory.', 'taskshunt' ) );
		}

		if ( ! $wp_filesystem->put_contents( $abs_path, $decoded, FS_CHMOD_FILE ) ) {
			return $this->error( __( 'Failed to write file.', 'taskshunt' ) );
		}

		$this->bust_opcache( $abs_path );

		return array(
			'success' => true,
			/* translators: %s: relative file path */
			'message' => sprintf( __( 'File "%s" written.', 'taskshunt' ), $relative ),
		);
	}

	/**
	 * Delete the file. Treats "already gone" as success so retries are idempotent.
	 *
	 * @param string $abs_path Target absolute path.
	 * @param string $relative Relative path for the result message.
	 * @return array{success: bool, message: string}
	 */
	private function apply_delete( string $abs_path, string $relative ): array {
		if ( ! file_exists( $abs_path ) ) {
			return array(
				'success' => true,
				/* translators: %s: relative file path */
				'message' => sprintf( __( 'File "%s" already absent.', 'taskshunt' ), $relative ),
			);
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			if ( ! WP_Filesystem() ) {
				return $this->error( __( 'Filesystem unavailable on receiver.', 'taskshunt' ) );
			}
		}

		if ( ! $wp_filesystem->delete( $abs_path ) ) {
			return $this->error( __( 'Failed to delete file.', 'taskshunt' ) );
		}

		$this->bust_opcache( $abs_path );

		return array(
			'success' => true,
			/* translators: %s: relative file path */
			'message' => sprintf( __( 'File "%s" deleted.', 'taskshunt' ), $relative ),
		);
	}

	/**
	 * Invalidate opcache for a PHP file so the new contents take effect immediately.
	 *
	 * Without this, FPM may serve the cached bytecode of the previous version
	 * until the file's mtime crosses opcache.revalidate_freq.
	 *
	 * @param string $abs_path Absolute path of the file just written or deleted.
	 * @return void
	 */
	private function bust_opcache( string $abs_path ): void {
		if ( ! str_ends_with( $abs_path, '.php' ) ) {
			return;
		}

		if ( ! function_exists( 'opcache_invalidate' ) ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.opcache_opcache_invalidate -- We just rewrote this file; serving the cached bytecode would defeat the push.
		opcache_invalidate( $abs_path, true );
	}

	/**
	 * Standard error result.
	 *
	 * @param string $message Error message.
	 * @return array{success: bool, message: string}
	 */
	private function error( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}
}
