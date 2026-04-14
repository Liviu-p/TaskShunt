<?php
/**
 * Post payload serializer.
 *
 * @package Stagify\Serializers
 */

declare(strict_types=1);

namespace Stagify\Serializers;

use Stagify\Contracts\PayloadSerializerInterface;

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
		}

		return (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
	}
}
