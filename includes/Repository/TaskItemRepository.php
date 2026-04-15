<?php
/**
 * Task item repository.
 *
 * @package Stagify\Repository
 */

declare(strict_types=1);

namespace Stagify\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stagify\Contracts\TaskItemRepositoryInterface;
use Stagify\Domain\TaskAction;
use Stagify\Domain\TaskItem;
use Stagify\Domain\TaskItemType;
use Stagify\Domain\TaskStatus;

/**
 * Persists and retrieves task item entities using a custom DB table.
 */
final class TaskItemRepository implements TaskItemRepositoryInterface {

	/**
	 * Fully-qualified task items table name (with prefix).
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Fully-qualified tasks table name (with prefix), used to update item_count.
	 *
	 * @var string
	 */
	private string $tasks_table;

	/**
	 * Create the repository.
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 */
	public function __construct( private readonly \wpdb $wpdb ) {
		$this->table       = $wpdb->prefix . 'stagify_task_items';
		$this->tasks_table = $wpdb->prefix . 'stagify_tasks';
	}

	/**
	 * Insert a task item and increment the owning task's item_count.
	 *
	 * @param int          $task_id     Owning task ID.
	 * @param TaskItemType $type        Item category.
	 * @param TaskAction   $action      Action to perform.
	 * @param string       $object_type WordPress object type.
	 * @param string       $object_id   Object identifier.
	 * @param string       $payload     Serialised action data.
	 * @return int The ID of the newly created item.
	 */
	public function add_item(
		int $task_id,
		TaskItemType $type,
		TaskAction $action,
		string $object_type,
		string $object_id,
		string $payload
	): int {
		$this->wpdb->insert(
			$this->table,
			array(
				'task_id'     => $task_id,
				'type'        => $type->value,
				'action'      => $action->value,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'payload'     => $payload,
				'status'      => TaskStatus::Pending->value,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$item_id = (int) $this->wpdb->insert_id;
		$this->increment_item_count( $task_id );
		return $item_id;
	}

	/**
	 * Return all items for a given task, hydrated as TaskItem entities.
	 *
	 * @param int $task_id Task ID.
	 * @return array<int, TaskItem>
	 */
	public function find_by_task( int $task_id ): array {
		$rows = $this->wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is safe, built from $wpdb->prefix.
			$this->wpdb->prepare( 'SELECT * FROM `' . $this->table . '` WHERE task_id = %d ORDER BY id ASC', $task_id ),
			ARRAY_A
		);
		return array_map( array( TaskItem::class, 'from_db_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Check whether an item with the given identifiers already exists in the task.
	 *
	 * @param int          $task_id     Task ID.
	 * @param TaskItemType $type        Item category.
	 * @param string       $object_type WordPress object type.
	 * @param string       $object_id   Object identifier.
	 * @return bool
	 */
	public function item_exists( int $task_id, TaskItemType $type, string $object_type, string $object_id ): bool {
		$count = $this->wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is safe, built from $wpdb->prefix.
			$this->wpdb->prepare( 'SELECT COUNT(*) FROM `' . $this->table . '` WHERE task_id = %d AND type = %s AND object_type = %s AND object_id = %s LIMIT 1', $task_id, $type->value, $object_type, $object_id )
		);
		return (int) $count > 0;
	}

	/**
	 * Find a single item by its composite key within a task.
	 *
	 * @param int          $task_id     Task ID.
	 * @param TaskItemType $type        Item category.
	 * @param string       $object_type WordPress object type.
	 * @param string       $object_id   Object identifier.
	 * @return TaskItem|null
	 */
	public function find_item( int $task_id, TaskItemType $type, string $object_type, string $object_id ): ?TaskItem {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table name is safe, built from $wpdb->prefix.
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM `' . $this->table . '` WHERE task_id = %d AND type = %s AND object_type = %s AND object_id = %s LIMIT 1',
				$task_id,
				$type->value,
				$object_type,
				$object_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? TaskItem::from_db_row( $row ) : null;
	}

	/**
	 * Delete a single item by its ID and decrement the parent task's item_count.
	 *
	 * @param int $item_id Item ID.
	 * @param int $task_id Owning task ID.
	 * @return void
	 */
	public function delete_item( int $item_id, int $task_id ): void {
		$this->wpdb->delete( $this->table, array( 'id' => $item_id ), array( '%d' ) );
		$this->decrement_item_count( $task_id );
	}

	/**
	 * Update the payload of an existing item.
	 *
	 * @param int    $item_id Item ID.
	 * @param string $payload New serialised payload.
	 * @return void
	 */
	public function update_payload( int $item_id, string $payload ): void {
		$this->wpdb->update(
			$this->table,
			array( 'payload' => $payload ),
			array( 'id' => $item_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Count all items belonging to a task.
	 *
	 * @param int $task_id Task ID.
	 * @return int
	 */
	public function count_by_task( int $task_id ): int {
		$count = $this->wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is safe, built from $wpdb->prefix.
			$this->wpdb->prepare( 'SELECT COUNT(*) FROM `' . $this->table . '` WHERE task_id = %d', $task_id )
		);
		return (int) $count;
	}

	/**
	 * Delete all items belonging to a task.
	 *
	 * @param int $task_id Task ID.
	 * @return void
	 */
	public function delete_by_task( int $task_id ): void {
		$this->wpdb->delete( $this->table, array( 'task_id' => $task_id ), array( '%d' ) );
	}

	/**
	 * Increment the item_count column on the parent task.
	 *
	 * @param int $task_id Task ID.
	 * @return void
	 */
	private function increment_item_count( int $task_id ): void {
		$this->wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is safe, built from $wpdb->prefix.
			$this->wpdb->prepare( 'UPDATE `' . esc_sql( $this->tasks_table ) . '` SET item_count = item_count + 1 WHERE id = %d', $task_id )
		);
	}

	/**
	 * Decrement the item_count column on the parent task (floor at zero).
	 *
	 * @param int $task_id Task ID.
	 * @return void
	 */
	private function decrement_item_count( int $task_id ): void {
		$this->wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is safe, built from $wpdb->prefix.
			$this->wpdb->prepare( 'UPDATE `' . esc_sql( $this->tasks_table ) . '` SET item_count = GREATEST(item_count - 1, 0) WHERE id = %d', $task_id )
		);
	}
}
