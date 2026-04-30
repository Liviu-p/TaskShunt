<?php
/**
 * TaskCreated domain event.
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
 * Fired after a new task is persisted.
 */
final readonly class TaskCreated extends DomainEvent {

	/**
	 * Create the event.
	 *
	 * @param Task $task The newly created task.
	 */
	public function __construct(
		public Task $task,
	) {
		parent::__construct();
	}
}
