<?php
/**
 * Post payload serializer.
 *
 * @package TaskShunt\Serializers
 */

declare(strict_types=1);

namespace TaskShunt\Serializers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Contracts\PayloadSerializerInterface;

/**
 * Serializes any post type into a JSON payload including all post meta.
 */
final class PostSerializer implements PayloadSerializerInterface {

	/**
	 * Whether this serializer handles the given object type.
	 *
	 * Returns true for all post types (acts as the universal fallback).
	 *
	 * @param string $object_type WordPress object type slug.
	 * @return bool
	 */
	public function supports( string $object_type ): bool { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return true;
	}

	/**
	 * Serialize the post and all its meta into a JSON payload string.
	 *
	 * @param int      $object_id WordPress post ID.
	 * @param \WP_Post $post      WordPress post object.
	 * @return string JSON-encoded payload.
	 */
	public function serialize( int $object_id, \WP_Post $post ): string {
		$payload = array(
			'post' => (array) $post,
			'meta' => get_post_meta( $object_id ),
		);

		if ( 'attachment' === $post->post_type ) {
			$payload['attachment_url'] = wp_get_attachment_url( $object_id );

			$file_path = get_attached_file( $object_id );
			if ( $file_path && file_exists( $file_path ) ) {
				global $wp_filesystem;
				if ( ! $wp_filesystem ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
					WP_Filesystem();
				}
				$contents = $wp_filesystem->get_contents( $file_path );
				if ( false !== $contents ) {
					$payload['attachment_data']     = base64_encode( $contents ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					$payload['attachment_filename'] = basename( $file_path );
				}
			}
		}

		return (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
	}
}
