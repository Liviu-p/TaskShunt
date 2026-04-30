<?php
/**
 * Discard task action handler.
 *
 * @package TaskShunt\Admin\Actions
 */

declare(strict_types=1);

namespace TaskShunt\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Admin\Notices;
use TaskShunt\Contracts\EventDispatcherInterface;
use TaskShunt\Contracts\TaskRepositoryInterface;
use TaskShunt\Events\TaskDeleted;

/**
 * Handles the admin_post_taskshunt_discard_task request.
 *
 * Verifies the nonce and capability, deletes the task, dispatches
 * TaskDeleted, then redirects back to the tasks list with a notice param.
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
	 * Handle the POST request.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_admin_referer( 'taskshunt_discard_task' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'taskshunt' ) );
		}

		$task_id = isset( $_REQUEST['task_id'] ) ? (int) $_REQUEST['task_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $task_id <= 0 ) {
			Notices::add( 'error', __( 'Invalid task ID.', 'taskshunt' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=taskshunt' ) );
			exit;
		}

		$active_task_id = $this->task_repository->get_active_task_id();
		if ( $active_task_id === $task_id ) {
			$this->task_repository->clear_active();
		}

		$this->task_repository->delete( $task_id );
		$this->event_dispatcher->dispatch( new TaskDeleted( $task_id ) );

		Notices::add( 'success', __( 'Task discarded successfully.', 'taskshunt' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=taskshunt' ) );
		exit;
	}
}
