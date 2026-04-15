<?php
/**
 * TaskCreated domain event.
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
