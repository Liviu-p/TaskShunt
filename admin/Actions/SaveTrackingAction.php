<?php
/**
 * Save tracked post types action handler.
 *
 * @package Stagify\Admin\Actions
 */

declare(strict_types=1);

namespace Stagify\Admin\Actions;

use Stagify\Admin\Notices;
use Stagify\Services\PostTypeRegistry;

/**
 * Handles the admin_post_stagify_save_tracking request.
 *
 * Persists the selected post types to track and redirects back.
 */
final class SaveTrackingAction {

	/**
	 * Handle the POST request.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_admin_referer( 'stagify_save_tracking' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stagify' ) );
		}

		$raw   = isset( $_POST['stagify_post_types'] ) && is_array( $_POST['stagify_post_types'] ) ? $_POST['stagify_post_types'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$types = array_map( 'sanitize_key', $raw );
		$valid = array_values( array_intersect( $types, $this->get_public_types() ) );

		update_option( PostTypeRegistry::OPTION_KEY, $valid );

		Notices::add( 'success', __( 'Content tracking settings saved.', 'stagify' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=stagify-settings' ) );
		exit;
	}

	/**
	 * Return all public post type slugs.
	 *
	 * @return list<string>
	 */
	private function get_public_types(): array {
		return array_values( (array) get_post_types( array( 'public' => true ), 'names' ) );
	}
}
