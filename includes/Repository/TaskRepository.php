<?php
/**
 * Task repository.
 *
 * @package Stagify\Repository
 */

declare(strict_types=1);

namespace Stagify\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stagify\Contracts\TaskItemRepositoryInterface;
use Stagify\Contracts\TaskRepositoryInterface;
use Stagify\Domain\Task;
use Stagify\Domain\TaskStatus;

/**
 * Persists and retrieves task entities using a custom DB table.
 */
final class TaskRepository implements TaskRepositoryInterface {

	/**
	 * The wp_options key that stores the active task ID.
	 */
	private const OPTION_ACTIVE_TASK = 'stagify_active_task_id';

	/**
	 * Fully-qualified table name (with prefix).
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Create the repository.
	 *
	 * @param \wpdb                       $wpdb                 WordPress database object.
	 * @param TaskItemRepositoryInterface $task_item_repository Task item repository for cascaded deletes.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly TaskItemRepositoryInterface $task_item_repository,
	) {
		$this->table = $wpdb->prefix . 'stagify_tasks';
	}

	/**
	 * Find a task by its ID.
	 *
	 * @param int $id Task ID.
	 * @return Task|null
	 */
	public function find_by_id( int $id ): ?Task {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM `{$this->table}` WHERE id = %d LIMIT 1", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? Task::from_db_row( $row ) : null;
	}

	/**
	 * Return all tasks ordered by creation date descending.
	 *
	 * @return array<int, Task>
	 */
	public function find_all(): array {
		$rows = $this->wpdb->get_results( "SELECT * FROM `{$this->table}` ORDER BY created_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( Task::class, 'from_db_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Return the currently active task, or null if none is set.
	 *
	 * @return Task|null
	 */
	public function find_active(): ?Task {
		$id = $this->get_active_task_id();
		return null !== $id ? $this->find_by_id( $id ) : null;
	}

	/**
	 * Create a new task and return its ID.
	 *
	 * @param string $title Human-readable title.
	 * @return int The ID of the newly created task.
	 */
	public function create( string $title ): int {
		$this->wpdb->insert(
			$this->table,
			array(
				'title'      => $title,
				'status'     => TaskStatus::Pending->value,
				'item_count' => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%d', '%s' )
		);
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Mark a task as the active task.
	 *
	 * @param int $task_id The task to activate.
	 * @return void
	 */
	public function set_active( int $task_id ): void {
		update_option( self::OPTION_ACTIVE_TASK, $task_id, false );
	}

	/**
	 * Clear the active task flag.
	 *
	 * @return void
	 */
	public function clear_active(): void {
		delete_option( self::OPTION_ACTIVE_TASK );
	}

	/**
	 * Return the ID of the active task, or null if none is set.
	 *
	 * @return int|null
	 */
	public function get_active_task_id(): ?int {
		$id = get_option( self::OPTION_ACTIVE_TASK, null );
		return null !== $id ? (int) $id : null;
	}

	/**
	 * Update a task's status, setting pushed_at when transitioning to Pushed.
	 *
	 * @param int        $id     Task ID.
	 * @param TaskStatus $status New status.
	 * @return void
	 */
	public function update_status( int $id, TaskStatus $status ): void {
		$data   = array( 'status' => $status->value );
		$format = array( '%s' );

		if ( TaskStatus::Pushed === $status ) {
			$data['pushed_at'] = gmdate( 'Y-m-d H:i:s' );
			$format[]          = '%s';
		}

		$this->wpdb->update( $this->table, $data, array( 'id' => $id ), $format, array( '%d' ) );
	}

	/**
	 * Delete a task and cascade-delete its items.
	 *
	 * @param int $id Task ID.
	 * @return void
	 */
	public function delete( int $id ): void {
		$this->task_item_repository->delete_by_task( $id );
		$this->wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Delete all Pushed tasks older than the configured retention period.
	 *
	 * Respects the stagify_cleanup option: if disabled, does nothing.
	 *
	 * @return void
	 */
	public function purge_old(): void {
		$settings = (array) get_option( 'stagify_cleanup', array() );

		if ( ! ( $settings['enabled'] ?? true ) ) {
			return;
		}

		$days   = max( 1, (int) ( $settings['days'] ?? 30 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) strtotime( "-{$days} days" ) );
		$this->wpdb->query(
			$this->wpdb->prepare( "DELETE FROM `{$this->table}` WHERE status = %s AND pushed_at < %s", TaskStatus::Pushed->value, $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
