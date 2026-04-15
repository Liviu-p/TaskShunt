<?php
/**
 * Save auto-cleanup settings action handler.
 *
 * @package Stagify\Admin\Actions
 */

declare(strict_types=1);

namespace Stagify\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stagify\Admin\Notices;

/**
 * Handles the admin_post_stagify_save_cleanup request.
 *
 * Persists the cleanup enabled flag and retention days.
 */
final class SaveCleanupAction {

	/**
	 * WordPress option key for cleanup settings.
	 */
	public const OPTION_KEY = 'stagify_cleanup';

	/**
	 * Handle the POST request.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_admin_referer( 'stagify_save_cleanup' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stagify' ) );
		}

		$enabled = ! empty( $_POST['stagify_cleanup_enabled'] );
		$days    = isset( $_POST['stagify_cleanup_days'] ) ? absint( $_POST['stagify_cleanup_days'] ) : 30;
		$days    = max( 1, min( 365, $days ) );

		update_option(
			self::OPTION_KEY,
			array(
				'enabled' => $enabled,
				'days'    => $days,
			)
		);

		Notices::add( 'success', __( 'Auto-cleanup settings saved.', 'stagify' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=stagify-settings' ) );
		exit;
	}
}
