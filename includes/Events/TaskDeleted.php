<?php
/**
 * TaskDeleted domain event.
 *
 * @package Stagify\Events
 */

declare(strict_types=1);

namespace Stagify\Events;

/**
 * Fired after a task is permanently deleted.
 */
final readonly class TaskDeleted extends DomainEvent {

	/**
	 * Create the event.
	 *
	 * @param int $task_id ID of the deleted task.
	 */
	public function __construct(
		public int $task_id,
	) {
		parent::__construct();
	}
}
