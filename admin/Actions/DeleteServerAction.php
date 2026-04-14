<?php
/**
 * Delete server action handler.
 *
 * @package Stagify\Admin\Actions
 */

declare(strict_types=1);

namespace Stagify\Admin\Actions;

use Stagify\Contracts\ServerRepositoryInterface;

/**
 * Handles the admin_post_stagify_delete_server request.
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
		check_admin_referer( 'stagify_delete_server' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stagify' ) );
		}

		$server_id = isset( $_GET['task_id'] ) ? (int) $_GET['task_id'] : 0;
		if ( $server_id > 0 ) {
			$this->server_repository->delete( $server_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=stagify-settings' ) );
		exit;
	}
}
