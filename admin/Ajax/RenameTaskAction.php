<?php
/**
 * Rename task AJAX action.
 *
 * @package Stagify\Admin\Ajax
 */

declare(strict_types=1);

namespace Stagify\Admin\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stagify\Contracts\TaskRepositoryInterface;

/**
 * Handles the wp_ajax_stagify_rename_task request.
 */
final class RenameTaskAction {

	/**
	 * Create the action handler.
	 *
	 * @param TaskRepositoryInterface $task_repository Task repository.
	 */
	public function __construct(
		private readonly TaskRepositoryInterface $task_repository,
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
		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

		if ( $task_id <= 0 || '' === $title ) {
			wp_send_json_error( array( 'message' => __( 'Invalid task ID or title.', 'stagify' ) ) );
		}

		$task = $this->task_repository->find_by_id( $task_id );
		if ( null === $task ) {
			wp_send_json_error( array( 'message' => __( 'Task not found.', 'stagify' ) ) );
		}

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'stagify_tasks',
			array( 'title' => substr( $title, 0, 200 ) ),
			array( 'id' => $task_id ),
			array( '%s' ),
			array( '%d' )
		);

		wp_send_json_success( array( 'title' => substr( $title, 0, 200 ) ) );
	}
}
