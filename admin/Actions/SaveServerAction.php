<?php
/**
 * Save server action handler.
 *
 * @package Stagify\Admin\Actions
 */

declare(strict_types=1);

namespace Stagify\Admin\Actions;

use Stagify\Admin\Notices;
use Stagify\Contracts\ServerRepositoryInterface;
use Stagify\Domain\ApiKey;
use Stagify\Domain\ServerUrl;

/**
 * Handles the admin_post_stagify_save_server request.
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
		check_admin_referer( 'stagify_save_server' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stagify' ) );
		}

		$name    = isset( $_POST['stagify_server_name'] ) ? sanitize_text_field( wp_unslash( $_POST['stagify_server_name'] ) ) : '';
		$raw_url = isset( $_POST['stagify_server_url'] ) ? sanitize_url( wp_unslash( $_POST['stagify_server_url'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$api_key = isset( $_POST['stagify_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stagify_api_key'] ) ) : '';

		if ( '' === $name ) {
			Notices::add( 'error', __( 'Server name cannot be empty.', 'stagify' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=stagify-settings' ) );
			exit;
		}

		try {
			$url_vo = new ServerUrl( $raw_url );
			$key_vo = new ApiKey( $api_key );
		} catch ( \InvalidArgumentException $e ) {
			Notices::add( 'error', __( 'Invalid URL or API key.', 'stagify' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=stagify-settings' ) );
			exit;
		}

		$result = $this->server_repository->save( $name, $url_vo, $key_vo );

		if ( false === $result ) {
			Notices::add( 'error', __( 'A server is already configured.', 'stagify' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=stagify-settings' ) );
			exit;
		}

		Notices::add( 'success', __( 'Server saved successfully.', 'stagify' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=stagify-settings' ) );
		exit;
	}
}
