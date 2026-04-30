<?php
/**
 * TaskFailed domain event.
 *
 * @package TaskShunt\Events
 */

declare(strict_types=1);

namespace TaskShunt\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Domain\Task;

/**
 * Fired when a task push attempt fails.
 */
final readonly class TaskFailed extends DomainEvent {

	/**
	 * Create the event.
	 *
	 * @param Task   $task   The task that failed.
	 * @param string $reason Human-readable failure reason.
	 */
	public function __construct(
		public Task $task,
		public string $reason,
	) {
		parent::__construct();
	}
}
