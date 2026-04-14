<?php
/**
 * TaskPushed domain event.
 *
 * @package Stagify\Events
 */

declare(strict_types=1);

namespace Stagify\Events;

use Stagify\Domain\Task;

/**
 * Fired after a task is successfully pushed to the server.
 */
final readonly class TaskPushed extends DomainEvent {

	/**
	 * Create the event.
	 *
	 * @param Task $task      The task that was pushed.
	 * @param int  $http_code HTTP response code returned by the server.
	 */
	public function __construct(
		public Task $task,
		public int $http_code,
	) {
		parent::__construct();
	}
}
