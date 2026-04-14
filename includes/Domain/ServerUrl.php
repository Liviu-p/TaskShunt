<?php
/**
 * Server URL value object.
 *
 * @package Stagify\Domain
 */

declare(strict_types=1);

namespace Stagify\Domain;

/**
 * Represents a validated server URL.
 * Construction fails fast if the URL is not syntactically valid.
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
				esc_html( sprintf( '"%s" is not a valid URL.', $value ) )
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
	 * @param self $other The other value object to compare.
	 * @return bool
	 */
	public function equals( ValueObject $other ): bool {
		return $other instanceof self && $this->value === $other->value;
	}
}
