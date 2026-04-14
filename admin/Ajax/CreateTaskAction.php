<?php
/**
 * Create task AJAX action.
 *
 * @package Stagify\Admin\Ajax
 */

declare(strict_types=1);

namespace Stagify\Admin\Ajax;

use Stagify\Contracts\EventDispatcherInterface;
use Stagify\Contracts\TaskItemRepositoryInterface;
use Stagify\Contracts\TaskRepositoryInterface;
use Stagify\Domain\Task;
use Stagify\Domain\TaskStatus;
use Stagify\Events\TaskActivated;

/**
 * Handles the wp_ajax_stagify_create_task request.
 *
 * Creates a new task, sets it active, and returns JSON with updated admin bar data.
 */
final class CreateTaskAction {

	/**
	 * Maximum allowed task title length.
	 */
	private const MAX_TITLE_LENGTH = 200;

	/**
	 * Create the action handler.
	 *
	 * @param TaskRepositoryInterface     $task_repository      Task repository.
	 * @param TaskItemRepositoryInterface $task_item_repository Task item repository.
	 * @param EventDispatcherInterface    $event_dispatcher     Event dispatcher.
	 */
	public function __construct(
		private readonly TaskRepositoryInterface $task_repository,
		private readonly TaskItemRepositoryInterface $task_item_repository,
		private readonly EventDispatcherInterface $event_dispatcher,
	) {}

	/**
	 * Handle the AJAX request.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_ajax_referer( 'stagify_activate_task' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stagify' ) ), 403 );
		}

		$raw_title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$title     = substr( $raw_title, 0, self::MAX_TITLE_LENGTH );

		if ( '' === $title ) {
			wp_send_json_error( array( 'message' => __( 'Task title cannot be empty.', 'stagify' ) ), 400 );
		}

		$task_id = $this->task_repository->create( $title );
		$this->task_repository->clear_active();
		$this->task_repository->set_active( $task_id );

		$task = $this->task_repository->find_by_id( $task_id );
		if ( null !== $task ) {
			$this->event_dispatcher->dispatch( new TaskActivated( $task ) );
		}

		wp_send_json_success(
			array(
				'admin_bar_title' => $this->build_title( $task ),
				'items'           => array(),
				'total_items'     => 0,
				'task_id'         => $task_id,
				'tasks'           => $this->build_task_list( $task_id ),
			)
		);
	}

	/**
	 * Build the admin bar title HTML for the newly active task.
	 *
	 * @param Task|null $task The active task.
	 * @return string
	 */
	private function build_title( ?Task $task ): string {
		if ( null === $task ) {
			return '<span style="color:#9e9e9e;">' . esc_html__( 'No active task', 'stagify' ) . '</span>';
		}

		$label = esc_html( $task->title )
			. ' &middot; '
			. esc_html( (string) $task->item_count )
			. ' '
			. esc_html__( 'changes', 'stagify' );

		return '<span style="color:#ff7759;">' . $label . '</span>';
	}

	/**
	 * Build the list of switchable tasks (pending, excluding the active one).
	 *
	 * @param int $active_id The ID of the newly activated task.
	 * @return list<array{id: int, title: string}>
	 */
	private function build_task_list( int $active_id ): array {
		$all   = $this->task_repository->find_all();
		$tasks = array();

		foreach ( $all as $task ) {
			if ( TaskStatus::Pending !== $task->status || $task->id === $active_id ) {
				continue;
			}
			$tasks[] = array(
				'id'    => $task->id,
				'title' => $task->title,
			);
		}

		return $tasks;
	}
}
