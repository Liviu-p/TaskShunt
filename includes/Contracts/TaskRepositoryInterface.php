<?php
/**
 * Task repository interface.
 *
 * @package Stagify\Contracts
 */

declare(strict_types=1);

namespace Stagify\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stagify\Domain\Task;
use Stagify\Domain\TaskStatus;

interface TaskRepositoryInterface {

	/**
	 * Find a task by its ID.
	 *
	 * @param int $id Task ID.
	 * @return Task|null
	 */
	public function find_by_id( int $id ): ?Task;

	/**
	 * Return all tasks.
	 *
	 * @return array<int, Task>
	 */
	public function find_all(): array;

	/**
	 * Return the currently active task, or null if none is set.
	 *
	 * @return Task|null
	 */
	public function find_active(): ?Task;

	/**
	 * Create a new task and return its ID.
	 *
	 * @param string $title Human-readable title for the task.
	 * @return int The ID of the newly created task.
	 */
	public function create( string $title ): int;

	/**
	 * Mark a task as the active task.
	 *
	 * @param int $task_id The task to activate.
	 * @return void
	 */
	public function set_active( int $task_id ): void;

	/**
	 * Clear the active task flag.
	 *
	 * @return void
	 */
	public function clear_active(): void;

	/**
	 * Return the ID of the currently active task, or null if none is set.
	 *
	 * @return int|null
	 */
	public function get_active_task_id(): ?int;

	/**
	 * Update the status of a task.
	 *
	 * @param int        $id     Task ID.
	 * @param TaskStatus $status The new status.
	 * @return void
	 */
	public function update_status( int $id, TaskStatus $status ): void;

	/**
	 * Delete a task by ID.
	 *
	 * @param int $id Task ID.
	 * @return void
	 */
	public function delete( int $id ): void;

	/**
	 * Delete all tasks that are no longer relevant.
	 *
	 * @return void
	 */
	public function purge_old(): void;
}
