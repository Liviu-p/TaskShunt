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
			'meta' => $this->unpack_meta( get_post_meta( $object_id ) ),
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

	/**
	 * Unpack serialized meta values into real PHP values, stripping objects.
	 *
	 * The wire format is JSON, so meta must be expanded here rather than letting
	 * the receiver call unserialize() on attacker-controllable bytes (PHP object
	 * injection). Objects are dropped — meta values that contain them cannot
	 * round-trip safely and almost always indicate a code smell on the source.
	 *
	 * @param array<string, array<int, string>> $meta Raw meta map from get_post_meta().
	 * @return array<string, array<int, mixed>>
	 */
	private function unpack_meta( array $meta ): array {
		$out = array();

		foreach ( $meta as $key => $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}

			$unpacked = array();
			foreach ( $values as $value ) {
				$decoded = is_string( $value ) ? maybe_unserialize( $value ) : $value;
				$clean   = $this->strip_objects( $decoded );
				if ( null !== $clean ) {
					$unpacked[] = $clean;
				}
			}

			if ( array() !== $unpacked ) {
				$out[ $key ] = $unpacked;
			}
		}

		return $out;
	}

	/**
	 * Recursively drop objects from a decoded meta value.
	 *
	 * Returns null if the whole value is an object (caller skips it).
	 * Arrays are walked; object members are removed.
	 *
	 * @param mixed $value Decoded meta value.
	 * @return mixed
	 */
	private function strip_objects( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			return null;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$out = array();
		foreach ( $value as $k => $v ) {
			$cleaned = $this->strip_objects( $v );
			if ( null !== $cleaned || ! is_object( $v ) ) {
				$out[ $k ] = $cleaned;
			}
		}

		return $out;
	}
}
