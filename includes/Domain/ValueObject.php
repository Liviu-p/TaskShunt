<?php
/**
 * Base value object.
 *
 * @package Stagify\Domain
 */

declare(strict_types=1);

namespace Stagify\Domain;

/**
 * Base marker for domain value objects.
 * Value objects are immutable; use readonly properties.
 */
abstract readonly class ValueObject {

	/**
	 * Check equality with another value object.
	 *
	 * @param self $other The other value object to compare.
	 * @return bool
	 */
	abstract public function equals( self $other ): bool;
}
