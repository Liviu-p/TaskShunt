<?php
/**
 * Task item repository interface.
 *
 * @package Stagify\Contracts
 */

declare(strict_types=1);

namespace Stagify\Contracts;

use Stagify\Domain\TaskAction;
use Stagify\Domain\TaskItemType;

interface TaskItemRepositoryInterface {

	/**
	 * Add an item to a task and return the new item ID.
	 *
	 * @param int          $task_id     The owning task ID.
	 * @param TaskItemType $type        Category of the item.
	 * @param TaskAction   $action      The action to perform on the item.
	 * @param string       $object_type The WordPress object type (e.g. post, option).
	 * @param string       $object_id   Identifier of the object within its type.
	 * @param string       $payload     Serialised data required to replay the action.
	 * @return int The ID of the newly created item.
	 */
	public function add_item(
		int $task_id,
		TaskItemType $type,
		TaskAction $action,
		string $object_type,
		string $object_id,
		string $payload
	): int;

	/**
	 * Return all items belonging to a task.
	 *
	 * @param int $task_id The task ID.
	 * @return array<int, mixed>
	 */
	public function find_by_task( int $task_id ): array;

	/**
	 * Check whether an item already exists in the task.
	 *
	 * @param int          $task_id     The task ID.
	 * @param TaskItemType $type        Category of the item.
	 * @param string       $object_type The WordPress object type.
	 * @param string       $object_id   Identifier of the object within its type.
	 * @return bool
	 */
	public function item_exists(
		int $task_id,
		TaskItemType $type,
		string $object_type,
		string $object_id
	): bool;

	/**
	 * Find a single item by its composite key within a task.
	 *
	 * @param int          $task_id     The task ID.
	 * @param TaskItemType $type        Category of the item.
	 * @param string       $object_type The WordPress object type.
	 * @param string       $object_id   Identifier of the object within its type.
	 * @return \Stagify\Domain\TaskItem|null
	 */
	public function find_item(
		int $task_id,
		TaskItemType $type,
		string $object_type,
		string $object_id
	): ?\Stagify\Domain\TaskItem;

	/**
	 * Delete a single item by its ID and decrement the parent task's item_count.
	 *
	 * @param int $item_id The item ID.
	 * @param int $task_id The owning task ID.
	 * @return void
	 */
	public function delete_item( int $item_id, int $task_id ): void;

	/**
	 * Update the payload of an existing item.
	 *
	 * @param int    $item_id The item ID.
	 * @param string $payload New serialised payload.
	 * @return void
	 */
	public function update_payload( int $item_id, string $payload ): void;

	/**
	 * Count the number of items in a task.
	 *
	 * @param int $task_id The task ID.
	 * @return int
	 */
	public function count_by_task( int $task_id ): int;

	/**
	 * Delete all items belonging to a task.
	 *
	 * @param int $task_id The task ID.
	 * @return void
	 */
	public function delete_by_task( int $task_id ): void;
}
