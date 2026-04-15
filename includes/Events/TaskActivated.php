<?php
/**
 * TaskActivated domain event.
 *
 * @package Stagify\Events
 */

declare(strict_types=1);

namespace Stagify\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stagify\Domain\Task;

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
