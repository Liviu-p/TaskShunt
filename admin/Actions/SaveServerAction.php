<?php
/**
 * Save server action handler.
 *
 * @package TaskShunt\Admin\Actions
 */

declare(strict_types=1);

namespace TaskShunt\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Admin\Notices;
use TaskShunt\Contracts\ServerRepositoryInterface;
use TaskShunt\Domain\ApiKey;
use TaskShunt\Domain\ServerUrl;

/**
 * Handles the admin_post_taskshunt_save_server request.
 *
 * Validates inputs via value objects, persists via the repository,
 * then redirects back with a success or error notice param.
 */
final class SaveServerAction {

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
		check_admin_referer( 'taskshunt_save_server' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'taskshunt' ) );
		}

		$name    = isset( $_POST['taskshunt_server_name'] ) ? sanitize_text_field( wp_unslash( $_POST['taskshunt_server_name'] ) ) : '';
		$raw_url = isset( $_POST['taskshunt_server_url'] ) ? sanitize_url( wp_unslash( $_POST['taskshunt_server_url'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$api_key = isset( $_POST['taskshunt_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['taskshunt_api_key'] ) ) : '';

		if ( '' === $name ) {
			Notices::add( 'error', __( 'Server name cannot be empty.', 'taskshunt' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=taskshunt-settings' ) );
			exit;
		}

		try {
			$url_vo = new ServerUrl( $raw_url );
			$key_vo = new ApiKey( $api_key );
		} catch ( \InvalidArgumentException $e ) {
			Notices::add( 'error', __( 'Invalid URL or API key.', 'taskshunt' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=taskshunt-settings' ) );
			exit;
		}

		$result = $this->server_repository->save( $name, $url_vo, $key_vo );

		if ( false === $result ) {
			Notices::add( 'error', __( 'A server is already configured.', 'taskshunt' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=taskshunt-settings' ) );
			exit;
		}

		Notices::add( 'success', __( 'Server saved successfully.', 'taskshunt' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=taskshunt-settings' ) );
		exit;
	}
}
