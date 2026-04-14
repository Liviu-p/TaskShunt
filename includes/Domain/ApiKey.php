<?php
/**
 * API key value object.
 *
 * @package Stagify\Domain
 */

declare(strict_types=1);

namespace Stagify\Domain;

/**
 * Represents a validated API key.
 * Construction fails fast if the key is shorter than 16 characters.
 */
final readonly class ApiKey extends ValueObject {

	/**
	 * Minimum required key length.
	 */
	private const MIN_LENGTH = 16;

	/**
	 * The validated API key string.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Create a new ApiKey.
	 *
	 * @param string $value The API key to validate and wrap.
	 *
	 * @throws \InvalidArgumentException If the key is shorter than 16 characters.
	 */
	public function __construct( string $value ) {
		if ( strlen( $value ) < self::MIN_LENGTH ) {
			throw new \InvalidArgumentException(
				esc_html(
					sprintf(
						'API key must be at least %d characters long, %d given.',
						self::MIN_LENGTH,
						strlen( $value )
					)
				)
			);
		}

		$this->value = $value;
	}

	/**
	 * Return the API key string.
	 *
	 * @return string
	 */
	public function get_value(): string {
		return $this->value;
	}

	/**
	 * Return the API key string.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return $this->value;
	}

	/**
	 * Check equality with another ApiKey.
	 *
	 * @param self $other The other value object to compare.
	 * @return bool
	 */
	public function equals( ValueObject $other ): bool {
		return $other instanceof self && hash_equals( $this->value, $other->value );
	}
}
