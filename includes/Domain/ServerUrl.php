<?php
/**
 * Server URL value object.
 *
 * @package Stagify\Domain
 */

declare(strict_types=1);

namespace Stagify\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The production site's URL (e.g. "https://example.com").
 *
 * Used by PushService to build the full receive endpoint:
 *   {ServerUrl}/wp-json/stagify/v1/receive
 *
 * Construction throws if the URL is not syntactically valid.
 */
final readonly class ServerUrl extends ValueObject {

	/**
	 * The validated URL string.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Create a new ServerUrl.
	 *
	 * @param string $value The URL string to validate and wrap.
	 *
	 * @throws \InvalidArgumentException If the value is not a valid URL.
	 */
	public function __construct( string $value ) {
		if ( false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
			throw new \InvalidArgumentException(
				/* translators: %s: URL value */
				esc_html( sprintf( __( '"%s" is not a valid URL.', 'stagify' ), $value ) )
			);
		}

		$this->value = $value;
	}

	/**
	 * Return the URL string.
	 *
	 * @return string
	 */
	public function get_value(): string {
		return $this->value;
	}

	/**
	 * Return the URL string.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return $this->value;
	}

	/**
	 * Check equality with another ServerUrl.
	 *
	 * @param ValueObject $other The other value object to compare.
	 * @return bool
	 */
	public function equals( ValueObject $other ): bool {
		return $other instanceof self && $this->value === $other->value;
	}
}
