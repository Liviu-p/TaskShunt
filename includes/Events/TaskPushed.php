<?php
/**
 * TaskPushed domain event.
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
