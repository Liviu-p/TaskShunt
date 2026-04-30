<?php
/**
 * Save tracked post types action handler.
 *
 * @package TaskShunt\Admin\Actions
 */

declare(strict_types=1);

namespace TaskShunt\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Admin\Notices;
use TaskShunt\Services\PostTypeRegistry;

/**
 * Handles the admin_post_taskshunt_save_tracking request.
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
		check_admin_referer( 'taskshunt_save_tracking' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'taskshunt' ) );
		}

		$raw   = isset( $_POST['taskshunt_post_types'] ) && is_array( $_POST['taskshunt_post_types'] ) ? wp_unslash( $_POST['taskshunt_post_types'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$types = array_map( 'sanitize_key', $raw );
		$valid = array_values( array_intersect( $types, $this->get_public_types() ) );

		update_option( PostTypeRegistry::OPTION_KEY, $valid );

		Notices::add( 'success', __( 'Content tracking settings saved.', 'taskshunt' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=taskshunt-settings' ) );
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
