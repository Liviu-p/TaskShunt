<?php
/**
 * TaskActivated domain event.
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
 * Fired after a task is set as the active task.
 */
final readonly class TaskActivated extends DomainEvent {

	/**
	 * Create the event.
	 *
	 * @param Task $task The task that was activated.
	 */
	public function __construct(
		public Task $task,
	) {
		parent::__construct();
	}
}
