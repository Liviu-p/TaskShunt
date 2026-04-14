<?php
/**
 * Preview task AJAX action — returns items summary for push preview.
 *
 * @package Stagify\Admin\Ajax
 */

declare(strict_types=1);

namespace Stagify\Admin\Ajax;

use Stagify\Contracts\TaskItemRepositoryInterface;
use Stagify\Contracts\TaskRepositoryInterface;
use Stagify\Domain\TaskItemType;

/**
 * Returns a JSON summary of task items for the push preview modal.
 */
final class PreviewTaskAction {

	/**
	 * Create the action handler.
	 *
	 * @param TaskRepositoryInterface     $task_repository      Task repository.
	 * @param TaskItemRepositoryInterface $task_item_repository Task item repository.
	 */
	public function __construct(
		private readonly TaskRepositoryInterface $task_repository,
		private readonly TaskItemRepositoryInterface $task_item_repository,
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

		$task_id = isset( $_GET['task_id'] ) ? (int) $_GET['task_id'] : 0;

		if ( $task_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid task ID.', 'stagify' ) ) );
		}

		$task = $this->task_repository->find_by_id( $task_id );
		if ( null === $task ) {
			wp_send_json_error( array( 'message' => __( 'Task not found.', 'stagify' ) ) );
		}

		$items   = $this->task_item_repository->find_by_task( $task_id );
		$summary = array();

		foreach ( $items as $item ) {
			$payload = json_decode( $item->payload, true );
			$entry   = array(
				'type'        => $item->type->value,
				'action'      => $item->action->value,
				'object_type' => $item->object_type,
				'object_id'   => $item->object_id,
			);

			if ( TaskItemType::Content === $item->type ) {
				$post_title = get_the_title( (int) $item->object_id );
				$entry['title']   = '' !== $post_title ? $post_title : $item->object_type . ' #' . $item->object_id;
				$entry['excerpt'] = '';
				if ( is_array( $payload ) && isset( $payload['post']['post_content'] ) ) {
					$entry['excerpt'] = wp_trim_words( wp_strip_all_tags( $payload['post']['post_content'] ), 20, '…' );
				}
				$entry['meta_count'] = is_array( $payload ) && isset( $payload['meta'] ) ? count( $payload['meta'] ) : 0;
			} elseif ( TaskItemType::File === $item->type ) {
				$entry['title'] = basename( $item->object_id );
				$entry['path']  = $item->object_id;
			} elseif ( TaskItemType::Environment === $item->type ) {
				$entry['title'] = is_array( $payload ) && isset( $payload['name'] ) ? $payload['name'] : $item->object_id;
				if ( is_array( $payload ) && isset( $payload['version'] ) ) {
					$entry['version'] = $payload['version'];
				}
			} else {
				$entry['title'] = $item->object_type . ' #' . $item->object_id;
			}

			$summary[] = $entry;
		}

		wp_send_json_success(
			array(
				'task'  => array(
					'id'         => $task->id,
					'title'      => $task->title,
					'item_count' => $task->item_count,
				),
				'items' => $summary,
			)
		);
	}
}
