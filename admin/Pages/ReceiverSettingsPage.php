<?php
/**
 * Receiver settings admin page.
 *
 * @package Stagify\Admin\Pages
 */

declare(strict_types=1);

namespace Stagify\Admin\Pages;

use Stagify\Api\ReceiverApi;

/**
 * Renders the receiver-mode admin page: API key management and mode switch.
 */
final class ReceiverSettingsPage {

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Register the admin menu page and the save handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'admin_menu',
			function (): void {
				$this->hook = (string) add_menu_page(
					__( 'Stagify', 'stagify' ),
					__( 'Stagify', 'stagify' ),
					'manage_options',
					'stagify',
					function (): void {
						$this->render();
					},
					'dashicons-migrate',
					80
				);
			}
		);

		add_action(
			'admin_init',
			function (): void {
				$this->handle_save();
			}
		);

		add_action(
			'admin_enqueue_scripts',
			function (): void {
				wp_enqueue_style(
					'stagify-admin',
					STAGIFY_PLUGIN_URL . 'assets/css/stagify-admin.css',
					array(),
					STAGIFY_VERSION
				);
			}
		);
	}

	/**
	 * Handle the API key save form submission.
	 *
	 * @return void
	 */
	private function handle_save(): void {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $request_method ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['stagify_receiver_action'] ) ? sanitize_key( $_POST['stagify_receiver_action'] ) : '';
		if ( '' === $action ) {
			return;
		}

		check_admin_referer( 'stagify_receiver_settings' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stagify' ) );
		}

		if ( 'save_api_key' === $action ) {
			$api_key = isset( $_POST['stagify_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stagify_api_key'] ) ) : '';
			update_option( ReceiverApi::API_KEY_OPTION, $api_key );
			add_settings_error( 'stagify', 'stagify_saved', __( 'API key saved.', 'stagify' ), 'success' );
		}

		if ( 'generate_api_key' === $action ) {
			$api_key = wp_generate_password( 40, false );
			update_option( ReceiverApi::API_KEY_OPTION, $api_key );
			add_settings_error( 'stagify', 'stagify_generated', __( 'New API key generated.', 'stagify' ), 'success' );
		}

		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=stagify&settings-updated=true' ) );
		exit;
	}

	/**
	 * Output the page HTML.
	 *
	 * @return void
	 */
	private function render(): void {
		$api_key  = get_option( ReceiverApi::API_KEY_OPTION, '' );
		$has_key  = '' !== $api_key;
		$ping_url = rest_url( 'stagify/v1/ping' );

		echo '<div class="wrap stagify-wrap">';
		echo '<h1>' . esc_html__( 'Stagify — Production (Receiver)', 'stagify' ) . '</h1>';

		settings_errors( 'stagify' );

		$this->render_status_section( $has_key, $ping_url );
		$this->render_api_key_section( $api_key, $has_key );
		$this->render_mode_section();

		echo '</div>';
	}

	/**
	 * Render the connection status section.
	 *
	 * @param bool   $has_key  Whether an API key is configured.
	 * @param string $ping_url The ping endpoint URL.
	 * @return void
	 */
	private function render_status_section( bool $has_key, string $ping_url ): void {
		echo '<h2>' . esc_html__( 'Status', 'stagify' ) . '</h2>';

		if ( $has_key ) {
			printf(
				'<p><span style="color:#39594d;font-weight:600;">&#10003; %s</span></p>',
				esc_html__( 'Receiver is active and ready to accept pushes.', 'stagify' )
			);
		} else {
			printf(
				'<p><span style="color:#ca492d;font-weight:600;">&#9888; %s</span></p>',
				esc_html__( 'No API key configured. Generate or set one below to start receiving pushes.', 'stagify' )
			);
		}

		printf(
			'<p>%s <code>%s</code></p>',
			esc_html__( 'Ping endpoint:', 'stagify' ),
			esc_html( $ping_url )
		);
	}

	/**
	 * Render the API key management section.
	 *
	 * @param string $api_key Current API key value.
	 * @param bool   $has_key Whether a key exists.
	 * @return void
	 */
	private function render_api_key_section( string $api_key, bool $has_key ): void {
		echo '<h2>' . esc_html__( 'API Key', 'stagify' ) . '</h2>';
		echo '<p>' . esc_html__( 'This key must match the one configured on the staging (sender) site.', 'stagify' ) . '</p>';

		printf( '<form method="post">' );
		wp_nonce_field( 'stagify_receiver_settings' );
		echo '<input type="hidden" name="stagify_receiver_action" value="save_api_key">';

		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="stagify_api_key">%s</label></th>'
			. '<td><input type="text" id="stagify_api_key" name="stagify_api_key" value="%s" class="regular-text" autocomplete="off"></td></tr>',
			esc_html__( 'API Key', 'stagify' ),
			esc_attr( $api_key )
		);
		echo '</tbody></table>';

		submit_button( __( 'Save API key', 'stagify' ) );
		echo '</form>';

		// Generate button as a separate form.
		printf( '<form method="post" style="margin-top:-16px;">' );
		wp_nonce_field( 'stagify_receiver_settings' );
		echo '<input type="hidden" name="stagify_receiver_action" value="generate_api_key">';
		printf(
			'<button type="submit" class="button" onclick="return confirm(\'%s\');">%s</button>',
			esc_js( $has_key ? __( 'This will replace the current API key. The sender must be updated to match. Continue?', 'stagify' ) : '' ),
			esc_html__( 'Generate new key', 'stagify' )
		);
		echo '</form>';
	}

	/**
	 * Render the mode switch section.
	 *
	 * @return void
	 */
	private function render_mode_section(): void {
		echo '<h2>' . esc_html__( 'Plugin mode', 'stagify' ) . '</h2>';
		printf(
			'<p><strong>%s</strong> &mdash; <a href="%s" onclick="return confirm(\'%s\');">%s</a></p>',
			esc_html__( 'Production (Receiver)', 'stagify' ),
			esc_url( admin_url( 'admin.php?page=stagify-setup' ) ),
			esc_js( __( 'Changing the mode will alter which features are active. Continue?', 'stagify' ) ),
			esc_html__( 'Change', 'stagify' )
		);
	}
}
