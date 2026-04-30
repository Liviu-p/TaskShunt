<?php
/**
 * Retry failed task action handler.
 *
 * @package TaskShunt\Admin\Actions
 */

declare(strict_types=1);

namespace TaskShunt\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Admin\Notices;
use TaskShunt\Contracts\TaskRepositoryInterface;
use TaskShunt\Domain\TaskStatus;
use TaskShunt\Services\PushService;

/**
 * Handles the admin_post_taskshunt_retry_task request.
 *
 * Resets a failed task to Pending, then immediately pushes it.
 */
final class RetryTaskAction {

	/**
	 * Create the action handler.
	 *
	 * @param TaskRepositoryInterface $task_repository Task repository.
	 * @param PushService             $push_service    Push service.
	 */
	public function __construct(
		private readonly TaskRepositoryInterface $task_repository,
		private readonly PushService $push_service,
	) {}

	/**
	 * Handle the POST request.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_admin_referer( 'taskshunt_retry_task' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'taskshunt' ) );
		}

		$task_id = isset( $_REQUEST['task_id'] ) ? (int) $_REQUEST['task_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $task_id <= 0 ) {
			Notices::add( 'error', __( 'Invalid task ID.', 'taskshunt' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=taskshunt' ) );
			exit;
		}

		$this->task_repository->update_status( $task_id, TaskStatus::Pending );

		$result = $this->push_service->push( $task_id );

		Notices::add(
			$result->success ? 'success' : 'error',
			$result->message
		);

		wp_safe_redirect( admin_url( 'admin.php?page=taskshunt' ) );
		exit;
	}
}
