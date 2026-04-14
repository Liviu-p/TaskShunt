<?php
/**
 * Activate task AJAX action.
 *
 * @package Stagify\Admin\Ajax
 */

declare(strict_types=1);

namespace Stagify\Admin\Ajax;

use Stagify\Contracts\EventDispatcherInterface;
use Stagify\Contracts\TaskItemRepositoryInterface;
use Stagify\Contracts\TaskRepositoryInterface;
use Stagify\Domain\Task;
use Stagify\Domain\TaskItem;
use Stagify\Domain\TaskItemType;
use Stagify\Domain\TaskStatus;
use Stagify\Events\TaskActivated;

/**
 * Handles the wp_ajax_stagify_activate_task request.
 *
 * Switches the active task and returns JSON with updated admin bar data.
 */
final class ActivateTaskAction {

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

		$task_id = isset( $_POST['task_id'] ) ? (int) $_POST['task_id'] : 0;
		$task    = $task_id > 0 ? $this->task_repository->find_by_id( $task_id ) : null;

		if ( null === $task || TaskStatus::Pending !== $task->status ) {
			wp_send_json_error( array( 'message' => __( 'Invalid task.', 'stagify' ) ), 400 );
		}

		$this->task_repository->clear_active();
		$this->task_repository->set_active( $task_id );
		$this->event_dispatcher->dispatch( new TaskActivated( $task ) );

		wp_send_json_success( array(
			'admin_bar_title' => $this->build_title( $task ),
			'items'           => $this->build_item_list( $task_id ),
			'total_items'     => $task->item_count,
			'task_id'         => $task_id,
			'tasks'           => $this->build_task_list( $task_id ),
		) );
	}

	/**
	 * Build the admin bar title HTML for the newly active task.
	 *
	 * @param Task $task The active task.
	 * @return string
	 */
	private function build_title( Task $task ): string {
		$label = esc_html( $task->title )
			. ' &middot; '
			. esc_html( (string) $task->item_count )
			. ' '
			. esc_html__( 'changes', 'stagify' );

		return '<span style="color:#46b450;">' . $label . '</span>';
	}

	/**
	 * Build the recent items list for the admin bar (max 5).
	 *
	 * @param int $task_id The task ID.
	 * @return list<array{id: int, label: string}>
	 */
	private function build_item_list( int $task_id ): array {
		$all   = $this->task_item_repository->find_by_task( $task_id );
		$shown = array_slice( $all, 0, 5 );
		$items = array();

		foreach ( $shown as $item ) {
			$items[] = array(
				'id'    => $item->id,
				'label' => $this->format_item_label( $item ),
			);
		}

		return $items;
	}

	/**
	 * Format a task item into a compact label for the admin bar.
	 *
	 * @param TaskItem $item Task item.
	 * @return string HTML label.
	 */
	private function format_item_label( TaskItem $item ): string {
		$action_colors = array(
			'create' => '#46b450',
			'update' => '#f0b849',
			'delete' => '#dc3232',
		);
		$color = $action_colors[ $item->action->value ] ?? '#a0a5aa';

		$icon = match ( $item->action->value ) {
			'create' => '+',
			'update' => '~',
			'delete' => '−',
			default  => '•',
		};

		$name = $item->object_type . ' #' . $item->object_id;
		if ( TaskItemType::File === $item->type ) {
			$name = basename( $item->object_id );
		} elseif ( TaskItemType::Content === $item->type ) {
			$post_title = get_the_title( (int) $item->object_id );
			if ( '' !== $post_title ) {
				$name = $post_title;
			}
		} elseif ( TaskItemType::Environment === $item->type ) {
			$item_payload = json_decode( $item->payload, true );
			$name         = ( $item_payload['name'] ?? $item->object_id ) . ' (' . $item->object_type . ')';
		}

		return sprintf(
			'<span style="color:%s;font-weight:700;margin-right:4px;">%s</span>%s',
			esc_attr( $color ),
			esc_html( $icon ),
			esc_html( mb_strimwidth( $name, 0, 35, '…' ) )
		);
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
