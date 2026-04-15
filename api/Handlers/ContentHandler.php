<?php
/**
 * Content item handler for the receiver API.
 *
 * @package Stagify\Api\Handlers
 */

declare(strict_types=1);

namespace Stagify\Api\Handlers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stagify\Domain\TaskAction;

/**
 * Applies a single content (post/page/CPT) change on the receiver site.
 */
final class ContentHandler {

	/**
	 * Post fields that are safe to copy from the sender payload.
	 *
	 * @var string[]
	 */
	private const ALLOWED_FIELDS = array(
		'post_title',
		'post_content',
		'post_excerpt',
		'post_status',
		'post_name',
		'post_parent',
		'menu_order',
		'comment_status',
		'ping_status',
		'post_password',
		'post_date',
		'post_date_gmt',
	);

	/**
	 * Process a content item.
	 *
	 * @param TaskAction $action      The action to perform.
	 * @param string     $object_type Post type slug.
	 * @param int        $object_id   Original post ID from the sender.
	 * @param mixed      $payload     Decoded payload data.
	 * @param string     $sender_url  Sender site URL for URL rewriting.
	 * @return array{success: bool, message: string, object_id?: int}
	 */
	public function handle( TaskAction $action, string $object_type, int $object_id, mixed $payload, string $sender_url = '' ): array {
		if ( ! post_type_exists( $object_type ) ) {
			/* translators: %s: post type slug */
			return $this->error( sprintf( __( 'Unknown post type: %s.', 'stagify' ), $object_type ) );
		}

		if ( TaskAction::Delete === $action ) {
			return $this->handle_delete( $object_type, $object_id, $payload );
		}

		return $this->handle_upsert( $action, $object_type, $object_id, $payload, $sender_url );
	}

	/**
	 * Handle create or update actions by upserting the post.
	 *
	 * @param TaskAction $action      Create or Update.
	 * @param string     $object_type Post type slug.
	 * @param int        $object_id   Original post ID from the sender.
	 * @param mixed      $payload     Decoded payload data.
	 * @param string     $sender_url  Sender site URL for URL rewriting.
	 * @return array{success: bool, message: string, object_id?: int}
	 */
	private function handle_upsert( TaskAction $action, string $object_type, int $object_id, mixed $payload, string $sender_url = '' ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( ! is_array( $payload ) || empty( $payload['post'] ) ) {
			return $this->error( __( 'Payload must contain a post object.', 'stagify' ) );
		}

		// Attachments need special handling — sideload the file first.
		if ( 'attachment' === $object_type && ( ! empty( $payload['attachment_data'] ) || ! empty( $payload['attachment_url'] ) ) ) {
			return $this->handle_attachment_upsert( $payload, $sender_url );
		}

		$post_data = $this->build_post_data( $payload['post'], $object_type );
		$post_data = $this->rewrite_urls( $post_data, $sender_url );
		$local_id  = $this->find_local_post( $payload['post'], $object_type );

		if ( null !== $local_id ) {
			$post_data['ID'] = $local_id;
		}

		$result = wp_insert_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_message() );
		}

		$meta = $payload['meta'] ?? array();
		$meta = $this->rewrite_meta_urls( $meta, $sender_url );
		$this->sync_meta( $result, $meta );

		return array(
			'success'   => true,
			/* translators: %s: action (created/updated) */
			'message'   => sprintf( __( 'Post %s successfully.', 'stagify' ), null !== $local_id ? __( 'updated', 'stagify' ) : __( 'created', 'stagify' ) ),
			'object_id' => $result,
		);
	}

	/**
	 * Handle attachment upsert by sideloading the file via media_handle_sideload.
	 *
	 * @param array<string, mixed> $payload    Decoded payload data.
	 * @param string               $sender_url Sender site URL.
	 * @return array{success: bool, message: string, object_id?: int}
	 */
	private function handle_attachment_upsert( array $payload, string $sender_url ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Prefer embedded file data (works across networks/localhost); fall back to URL download.
		if ( ! empty( $payload['attachment_data'] ) ) {
			$download = $this->decode_attachment_data( $payload );
		} else {
			$download = $this->download_attachment( (string) $payload['attachment_url'] );
		}

		if ( isset( $download['error'] ) ) {
			return $this->error( $download['error'] );
		}

		$local_id = $this->find_local_post( $payload['post'], 'attachment' );

		if ( null !== $local_id ) {
			return $this->update_existing_attachment( $local_id, $download['file_array'], $payload );
		}

		return $this->create_new_attachment( $download['file_array'], $payload );
	}

	/**
	 * Download an attachment URL and prepare the file array for sideloading.
	 *
	 * @param string $attachment_url Remote URL.
	 * @return array{file_array: array{name: string, tmp_name: string}}|array{error: string}
	 */
	private function download_attachment( string $attachment_url ): array {
		$tmp_file = download_url( $attachment_url );

		if ( is_wp_error( $tmp_file ) ) {
			/* translators: %s: error message */
			return array( 'error' => sprintf( __( 'Failed to download attachment: %s', 'stagify' ), $tmp_file->get_error_message() ) );
		}

		$filename = basename( wp_parse_url( $attachment_url, PHP_URL_PATH ) ?? 'file' );

		return array(
			'file_array' => array(
				'name'     => sanitize_file_name( $filename ),
				'tmp_name' => $tmp_file,
			),
		);
	}

	/**
	 * Decode base64-encoded attachment data from the payload into a temp file.
	 *
	 * @param array<string, mixed> $payload Decoded payload data.
	 * @return array{file_array: array{name: string, tmp_name: string}}|array{error: string}
	 */
	private function decode_attachment_data( array $payload ): array {
		$data = base64_decode( (string) $payload['attachment_data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $data ) {
			return array( 'error' => __( 'Failed to decode attachment data.', 'stagify' ) );
		}

		$tmp_file = wp_tempnam( $payload['attachment_filename'] ?? 'file' );

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem->put_contents( $tmp_file, $data ) ) {
			return array( 'error' => __( 'Failed to write attachment to temp file.', 'stagify' ) );
		}

		$filename = sanitize_file_name( $payload['attachment_filename'] ?? 'file' );

		return array(
			'file_array' => array(
				'name'     => $filename,
				'tmp_name' => $tmp_file,
			),
		);
	}

	/**
	 * Update an existing attachment with a new file.
	 *
	 * @param int                  $local_id   Local attachment post ID.
	 * @param array<string, mixed> $file_array File array for sideloading.
	 * @param array<string, mixed> $payload    Decoded payload data.
	 * @return array{success: bool, message: string, object_id?: int}
	 */
	private function update_existing_attachment( int $local_id, array $file_array, array $payload ): array {
		$upload = wp_handle_sideload( $file_array, array( 'test_form' => false ) );

		if ( isset( $upload['error'] ) ) {
			wp_delete_file( $file_array['tmp_name'] );
			/* translators: %s: error message */
			return $this->error( sprintf( __( 'Sideload failed: %s', 'stagify' ), $upload['error'] ) );
		}

		update_attached_file( $local_id, $upload['file'] );
		wp_update_post(
			array(
				'ID'             => $local_id,
				'post_title'     => $payload['post']['post_title'] ?? '',
				'post_excerpt'   => $payload['post']['post_excerpt'] ?? '',
				'post_mime_type' => $upload['type'],
				'guid'           => $upload['url'],
			)
		);

		$metadata = wp_generate_attachment_metadata( $local_id, $upload['file'] );
		wp_update_attachment_metadata( $local_id, $metadata );

		return array(
			'success'   => true,
			'message'   => __( 'Attachment updated successfully.', 'stagify' ),
			'object_id' => $local_id,
		);
	}

	/**
	 * Create a new attachment via media_handle_sideload.
	 *
	 * @param array<string, mixed> $file_array File array for sideloading.
	 * @param array<string, mixed> $payload    Decoded payload data.
	 * @return array{success: bool, message: string, object_id?: int}
	 */
	private function create_new_attachment( array $file_array, array $payload ): array {
		$post_id = media_handle_sideload( $file_array, 0, $payload['post']['post_title'] ?? '' );

		if ( is_wp_error( $post_id ) ) {
			return $this->error( $post_id->get_error_message() );
		}

		$update_data = array( 'ID' => $post_id );
		foreach ( array( 'post_name', 'post_excerpt', 'post_status' ) as $field ) {
			if ( ! empty( $payload['post'][ $field ] ) ) {
				$update_data[ $field ] = $payload['post'][ $field ];
			}
		}
		if ( count( $update_data ) > 1 ) {
			wp_update_post( $update_data );
		}

		return array(
			'success'   => true,
			'message'   => __( 'Attachment created successfully.', 'stagify' ),
			'object_id' => $post_id,
		);
	}

	/**
	 * Handle delete action by finding and removing the local post.
	 *
	 * @param string $object_type Post type slug.
	 * @param int    $object_id   Original post ID from the sender.
	 * @param mixed  $payload     Decoded payload data.
	 * @return array{success: bool, message: string}
	 */
	private function handle_delete( string $object_type, int $object_id, mixed $payload ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $object_id reserved for future ID mapping
		$slug = $this->extract_slug( $payload );

		if ( null === $slug ) {
			return $this->error( __( 'Payload must contain a post_name for delete.', 'stagify' ) );
		}

		$local_id = $this->find_by_slug( $slug, $object_type );

		if ( null === $local_id ) {
			return $this->error( __( 'Post not found.', 'stagify' ) );
		}

		wp_delete_post( $local_id, true );

		return array(
			'success' => true,
			'message' => __( 'Post deleted successfully.', 'stagify' ),
		);
	}

	/**
	 * Extract the post slug from the payload.
	 *
	 * @param mixed $payload Decoded payload data.
	 * @return string|null The slug, or null if not present.
	 */
	private function extract_slug( mixed $payload ): ?string {
		if ( ! is_array( $payload ) || empty( $payload['post']['post_name'] ) ) {
			return null;
		}

		return (string) $payload['post']['post_name'];
	}

	/**
	 * Find a local post by slug using get_posts().
	 *
	 * @param string $slug        The post_name to search for.
	 * @param string $object_type Post type slug.
	 * @return int|null Local post ID, or null if not found.
	 */
	private function find_by_slug( string $slug, string $object_type ): ?int {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts
		$posts = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => $object_type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

	/**
	 * Build a sanitized post data array from the sender payload.
	 *
	 * @param array<string, mixed> $post_payload Raw post fields from sender.
	 * @param string               $object_type  Post type slug.
	 * @return array<string, mixed>
	 */
	private function build_post_data( array $post_payload, string $object_type ): array {
		$data = array( 'post_type' => $object_type );

		foreach ( self::ALLOWED_FIELDS as $field ) {
			if ( isset( $post_payload[ $field ] ) ) {
				$data[ $field ] = $post_payload[ $field ];
			}
		}

		return $data;
	}

	/**
	 * Find a local post by slug and post type.
	 *
	 * @param array<string, mixed> $post_payload Raw post fields from sender.
	 * @param string               $object_type  Post type slug.
	 * @return int|null Local post ID, or null if not found.
	 */
	private function find_local_post( array $post_payload, string $object_type ): ?int {
		if ( empty( $post_payload['post_name'] ) ) {
			return null;
		}

		$existing = get_page_by_path(
			$post_payload['post_name'],
			OBJECT,
			$object_type
		);

		if ( $existing instanceof \WP_Post ) {
			return $existing->ID;
		}

		return null;
	}

	/**
	 * Sync post meta from the sender payload to the local post.
	 *
	 * @param int                  $post_id Local post ID.
	 * @param array<string, mixed> $meta    Meta key-value pairs from payload.
	 * @return void
	 */
	private function sync_meta( int $post_id, array $meta ): void {
		foreach ( $meta as $key => $values ) {
			if ( str_starts_with( $key, '_edit_' ) ) {
				continue;
			}

			delete_post_meta( $post_id, $key );

			if ( ! is_array( $values ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				add_post_meta( $post_id, $key, maybe_unserialize( $value ) );
			}
		}
	}

	/**
	 * Replace the sender site URL with the local site URL in post data fields.
	 *
	 * Rewrites URLs in post_content, post_excerpt, and post_title to point
	 * to the receiver site instead of the sender.
	 *
	 * @param array<string, mixed> $post_data  Built post data array.
	 * @param string               $sender_url Sender site URL.
	 * @return array<string, mixed>
	 */
	private function rewrite_urls( array $post_data, string $sender_url ): array {
		if ( '' === $sender_url ) {
			return $post_data;
		}

		$local_url = site_url();

		if ( $sender_url === $local_url ) {
			return $post_data;
		}

		$fields = array( 'post_content', 'post_excerpt' );

		foreach ( $fields as $field ) {
			if ( isset( $post_data[ $field ] ) && is_string( $post_data[ $field ] ) ) {
				$post_data[ $field ] = str_replace( $sender_url, $local_url, $post_data[ $field ] );
			}
		}

		return $post_data;
	}

	/**
	 * Replace the sender site URL with the local site URL in meta values.
	 *
	 * @param array<string, mixed> $meta       Meta key-value pairs from payload.
	 * @param string               $sender_url Sender site URL.
	 * @return array<string, mixed>
	 */
	private function rewrite_meta_urls( array $meta, string $sender_url ): array {
		if ( '' === $sender_url ) {
			return $meta;
		}

		$local_url = site_url();

		if ( $sender_url === $local_url ) {
			return $meta;
		}

		foreach ( $meta as $key => $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}

			foreach ( $values as $i => $value ) {
				if ( is_string( $value ) ) {
					$meta[ $key ][ $i ] = str_replace( $sender_url, $local_url, $value );
				}
			}
		}

		return $meta;
	}

	/**
	 * Return a standardized error result.
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
