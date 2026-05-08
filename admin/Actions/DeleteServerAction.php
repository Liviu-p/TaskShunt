<?php
/**
 * Delete server action handler.
 *
 * @package TaskShunt\Admin\Actions
 */

declare(strict_types=1);

namespace TaskShunt\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Contracts\ServerRepositoryInterface;

/**
 * Handles the admin_post_taskshunt_delete_server request.
 */
final class DeleteServerAction {

	/**
	 * Create the action handler.
	 *
	 * @param ServerRepositoryInterface $server_repository Server repository.
	 */
	public function __construct(
		private readonly ServerRepositoryInterface $server_repository,
	) {}

	/**
	 * Handle the POST request.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_admin_referer( 'taskshunt_delete_server' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'taskshunt' ) );
		}

		$server_id = isset( $_GET['task_id'] ) ? (int) $_GET['task_id'] : 0;
		if ( $server_id > 0 ) {
			$this->server_repository->delete( $server_id );
		} else {
			// No specific id — used by the "Reset connection" path when the row
			// exists but cannot be hydrated (e.g. the at-rest key is unrecoverable).
			$this->server_repository->delete_all();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=taskshunt-settings' ) );
		exit;
	}
}
