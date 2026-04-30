<?php
/**
 * Discard task AJAX action.
 *
 * @package TaskShunt\Admin\Ajax
 */

declare(strict_types=1);

namespace TaskShunt\Admin\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Contracts\EventDispatcherInterface;
use TaskShunt\Contracts\TaskRepositoryInterface;
use TaskShunt\Domain\TaskStatus;
use TaskShunt\Events\TaskDeleted;

/**
 * Handles the wp_ajax_taskshunt_discard_task AJAX request.
 *
 * Discards the active task and returns updated admin bar data.
 */
final class DiscardTaskAction {

	/**
	 * Create the action handler.
	 *
	 * @param TaskRepositoryInterface  $task_repository  Task repository.
	 * @param EventDispatcherInterface $event_dispatcher Event dispatcher.
	 */
	public function __construct(
		private readonly TaskRepositoryInterface $task_repository,
		private readonly EventDispatcherInterface $event_dispatcher,
	) {}

	/**
	 * Handle the AJAX request.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_ajax_referer( 'taskshunt_activate_task' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'taskshunt' ) ), 403 );
		}

		$task_id = isset( $_POST['task_id'] ) ? (int) $_POST['task_id'] : 0;
		$task    = $task_id > 0 ? $this->task_repository->find_by_id( $task_id ) : null;

		if ( null === $task || TaskStatus::Pushing === $task->status ) {
			wp_send_json_error( array( 'message' => __( 'Invalid task or task is being pushed.', 'taskshunt' ) ), 400 );
		}

		$active_task_id = $this->task_repository->get_active_task_id();
		if ( $active_task_id === $task_id ) {
			$this->task_repository->clear_active();
		}

		$this->task_repository->delete( $task_id );
		$this->event_dispatcher->dispatch( new TaskDeleted( $task_id ) );

		wp_send_json_success(
			array(
				'admin_bar_title' => '<span style="color:#a0a5aa;">' . esc_html__( 'No active task', 'taskshunt' ) . '</span>',
				'items'           => array(),
				'total_items'     => 0,
				'task_id'         => 0,
				'tasks'           => $this->get_pending_tasks(),
			)
		);
	}

	/**
	 * Return an array of pending tasks for the admin bar switcher.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	private function get_pending_tasks(): array {
		$tasks = array();
		foreach ( $this->task_repository->find_all() as $t ) {
			if ( TaskStatus::Pending !== $t->status ) {
				continue;
			}
			$tasks[] = array(
				'id'    => $t->id,
				'title' => $t->title,
			);
		}
		return $tasks;
	}
}
