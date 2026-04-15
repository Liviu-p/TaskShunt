<?php
/**
 * Serializer registry.
 *
 * @package Stagify\Serializers
 */

declare(strict_types=1);

namespace Stagify\Serializers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stagify\Contracts\PayloadSerializerInterface;

/**
 * Holds all registered payload serializers and resolves them by object type.
 */
final class SerializerRegistry {

	/**
	 * Registered serializers, checked in order.
	 *
	 * @var PayloadSerializerInterface[]
	 */
	private array $serializers;

	/**
	 * Create the registry with an ordered list of serializers.
	 *
	 * @param PayloadSerializerInterface[] $serializers Serializers checked in order; first match wins.
	 */
	public function __construct( array $serializers ) {
		$this->serializers = $serializers;
	}

	/**
	 * Return the first serializer that supports the given object type.
	 *
	 * @param string $object_type WordPress object type slug.
	 * @return PayloadSerializerInterface
	 * @throws \RuntimeException When no serializer supports the object type.
	 */
	public function resolve( string $object_type ): PayloadSerializerInterface {
		foreach ( $this->serializers as $serializer ) {
			if ( $serializer->supports( $object_type ) ) {
				return $serializer;
			}
		}

		throw new \RuntimeException(
			/* translators: %s: WordPress object type slug */
			esc_html( sprintf( __( 'No serializer registered for object type "%s".', 'stagify' ), $object_type ) )
		);
	}
}
