<?php
/**
 * Save plugin mode action handler.
 *
 * @package Stagify\Admin\Actions
 */

declare(strict_types=1);

namespace Stagify\Admin\Actions;

use Stagify\Admin\Pages\SetupPage;
use Stagify\Domain\PluginMode;

/**
 * Handles the admin_post_stagify_save_mode request.
 *
 * Persists the chosen plugin mode and redirects to the main dashboard.
 */
final class SaveModeAction {

	/**
	 * Handle the POST request.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_admin_referer( 'stagify_save_mode' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stagify' ) );
		}

		$raw  = isset( $_POST['stagify_mode'] ) ? sanitize_key( $_POST['stagify_mode'] ) : '';
		$mode = PluginMode::tryFrom( $raw );

		if ( null === $mode ) {
			wp_safe_redirect( admin_url( 'admin.php?page=stagify-setup' ) );
			exit;
		}

		update_option( SetupPage::OPTION_KEY, $mode->value );

		wp_safe_redirect( admin_url( 'admin.php?page=stagify' ) );
		exit;
	}
}
