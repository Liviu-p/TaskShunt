<?php
/**
 * Push task AJAX action.
 *
 * @package TaskShunt\Admin\Ajax
 */

declare(strict_types=1);

namespace TaskShunt\Admin\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Services\PushService;

/**
 * Handles the wp_ajax_taskshunt_push_task_ajax request.
 *
 * Pushes the task via PushService and returns a JSON response.
 */
final class PushTaskAction {

	/**
	 * Create the action handler.
	 *
	 * @param PushService $push_service Push service.
	 */
	public function __construct(
		private readonly PushService $push_service,
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

		if ( $task_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid task ID.', 'taskshunt' ) ) );
		}

		$result = $this->push_service->push( $task_id );

		if ( $result->success ) {
			wp_send_json_success( array( 'message' => $result->message ) );
		} else {
			wp_send_json_error( array( 'message' => $result->message ) );
		}
	}
}
