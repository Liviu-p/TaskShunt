<?php
/**
 * Push task action handler.
 *
 * @package Stagify\Admin\Actions
 */

declare(strict_types=1);

namespace Stagify\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stagify\Admin\Notices;
use Stagify\Services\PushService;

/**
 * Handles the admin_post_stagify_push_task request.
 *
 * Verifies the nonce and capability, delegates to PushService,
 * then redirects back with a success or error notice param.
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
	 * Handle the POST request.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_admin_referer( 'stagify_push_task' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stagify' ) );
		}

		$task_id = isset( $_REQUEST['task_id'] ) ? (int) $_REQUEST['task_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $task_id <= 0 ) {
			Notices::add( 'error', __( 'Invalid task ID.', 'stagify' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=stagify' ) );
			exit;
		}

		$result = $this->push_service->push( $task_id );

		Notices::add(
			$result->success ? 'success' : 'error',
			$result->message
		);

		wp_safe_redirect( admin_url( 'admin.php?page=stagify' ) );
		exit;
	}
}
