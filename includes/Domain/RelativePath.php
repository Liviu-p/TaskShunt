<?php
/**
 * Relative path value object.
 *
 * @package Stagify\Domain
 */

declare(strict_types=1);

namespace Stagify\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents a validated relative file path.
 * Construction fails fast for empty, absolute, or traversal paths.
 */
final readonly class RelativePath extends ValueObject {

	/**
	 * The validated relative path string.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Create a new RelativePath.
	 *
	 * @param string $value The relative path to validate and wrap.
	 *
	 * @throws \InvalidArgumentException If the path is empty, absolute, or contains traversal sequences.
	 */
	public function __construct( string $value ) {
		if ( '' === $value ) {
			throw new \InvalidArgumentException( esc_html__( 'Path must not be empty.', 'stagify' ) );
		}

		if ( str_starts_with( $value, '/' ) || str_starts_with( $value, '\\' ) ) {
			throw new \InvalidArgumentException(
				/* translators: %s: file path */
				esc_html( sprintf( __( '"%s" must be a relative path.', 'stagify' ), $value ) )
			);
		}

		if ( str_contains( $value, '..' ) ) {
			throw new \InvalidArgumentException(
				/* translators: %s: file path */
				esc_html( sprintf( __( '"%s" contains illegal traversal sequence.', 'stagify' ), $value ) )
			);
		}

		if ( str_contains( $value, "\0" ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Path must not contain null bytes.', 'stagify' ) );
		}

		$this->value = $value;
	}

	/**
	 * Return the relative path string.
	 *
	 * @return string
	 */
	public function get_value(): string {
		return $this->value;
	}

	/**
	 * Return the relative path string.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return $this->value;
	}

	/**
	 * Check equality with another RelativePath.
	 *
	 * @param ValueObject $other The other value object to compare.
	 * @return bool
	 */
	public function equals( ValueObject $other ): bool {
		return $other instanceof self && $this->value === $other->value;
	}
}
