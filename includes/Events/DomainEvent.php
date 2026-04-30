<?php
/**
 * Base domain event.
 *
 * @package TaskShunt\Events
 */

declare(strict_types=1);

namespace TaskShunt\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for all domain events.
 */
abstract readonly class DomainEvent {

	/**
	 * Create a new domain event.
	 *
	 * @param \DateTimeImmutable $occurred_at When the event occurred.
	 */
	public function __construct(
		public readonly \DateTimeImmutable $occurred_at = new \DateTimeImmutable(),
	) {}
}
