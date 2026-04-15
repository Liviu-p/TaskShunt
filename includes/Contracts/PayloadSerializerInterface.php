<?php
/**
 * Payload serializer interface.
 *
 * @package Stagify\Contracts
 */

declare(strict_types=1);

namespace Stagify\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts a WordPress object into a JSON payload string for a task item.
 */
interface PayloadSerializerInterface {

	/**
	 * Whether this serializer handles the given object type.
	 *
	 * @param string $object_type WordPress object type slug (e.g. a post type name).
	 * @return bool
	 */
	public function supports( string $object_type ): bool;

	/**
	 * Serialize the object into a JSON payload string.
	 *
	 * @param int      $object_id Object identifier (e.g. post ID).
	 * @param \WP_Post $post      WordPress post object.
	 * @return string JSON-encoded payload.
	 */
	public function serialize( int $object_id, \WP_Post $post ): string;
}
