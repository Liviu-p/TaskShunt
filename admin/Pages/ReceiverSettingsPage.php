<?php
/**
 * Receiver settings admin page.
 *
 * @package Stagify\Admin\Pages
 */

declare(strict_types=1);

namespace Stagify\Admin\Pages;

use Stagify\Admin\OnboardingChecklist;
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
				$icon_svg     = file_get_contents( STAGIFY_PLUGIN_DIR . 'assets/img/icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					$icon_uri = 'data:image/svg+xml;base64,' . base64_encode( $icon_svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

					$this->hook = (string) add_menu_page(
						__( 'Stagify', 'stagify' ),
						__( 'Stagify', 'stagify' ),
						'manage_options',
						'stagify',
						function (): void {
							$this->render();
						},
						$icon_uri,
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
			static function (): void {
				wp_enqueue_style( 'stagify-admin', STAGIFY_PLUGIN_URL . 'assets/css/stagify-admin.css', array(), STAGIFY_VERSION );
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

		echo '<div class="stagify-page-header">';
		echo '<h1>' . esc_html__( 'Production', 'stagify' ) . '</h1>';
		echo '</div>';
		echo '<p class="stagify-subheading">' . esc_html__( 'Accept and apply changes pushed from your staging server.', 'stagify' ) . '</p>';

		settings_errors( 'stagify' );

		OnboardingChecklist::render_receiver();

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
		if ( $has_key ) {
			printf(
				'<div class="stagify-status-card stagify-status-card--ready">'
				. '<span class="stagify-status-dot stagify-status-dot--ready"></span>'
				. '<div>'
				. '<strong>%s</strong>'
				. '<p>%s <code>%s</code></p>'
				. '</div>'
				. '</div>',
				esc_html__( 'Ready to receive', 'stagify' ),
				esc_html__( 'Ping endpoint:', 'stagify' ),
				esc_html( $ping_url )
			);
		} else {
			printf(
				'<div class="stagify-status-card stagify-status-card--inactive">'
				. '<span class="stagify-status-dot stagify-status-dot--inactive"></span>'
				. '<div>'
				. '<strong>%s</strong>'
				. '<p>%s</p>'
				. '</div>'
				. '</div>',
				esc_html__( 'Not configured', 'stagify' ),
				esc_html__( 'Generate or set an API key below to start receiving pushes.', 'stagify' )
			);
		}
	}

	/**
	 * Render the API key management section.
	 *
	 * @param string $api_key Current API key value.
	 * @param bool   $has_key Whether a key exists.
	 * @return void
	 */
	private function render_api_key_section( string $api_key, bool $has_key ): void {
		echo '<div class="stagify-section-card">';
		echo '<h2>' . esc_html__( 'API Key', 'stagify' ) . '</h2>';
		echo '<p>' . esc_html__( 'Copy this key and paste it in the staging (sender) site settings.', 'stagify' ) . '</p>';

		if ( $has_key ) {
			$this->render_existing_key( $api_key );
		} else {
			$this->render_generate_key_form();
		}

		echo '</div>';
	}

	/**
	 * Render the existing key display with copy and regenerate controls.
	 *
	 * @param string $api_key The current API key.
	 * @return void
	 */
	private function render_existing_key( string $api_key ): void {
		printf(
			'<div class="stagify-apikey-display">'
			. '<code id="stagify-key-value">%s</code>'
			. '<button type="button" class="button button-small" id="stagify-copy-key" title="%s" data-copied="%s">%s</button>'
			. '</div>',
			esc_html( $api_key ),
			esc_attr__( 'Copy to clipboard', 'stagify' ),
			esc_attr__( 'Copied!', 'stagify' ),
			esc_html__( 'Copy', 'stagify' )
		);

		echo '<form method="post" class="stagify-apikey-generate">';
		wp_nonce_field( 'stagify_receiver_settings' );
		echo '<input type="hidden" name="stagify_receiver_action" value="generate_api_key">';
		printf(
			'<button type="submit" class="button button-small stagify-confirm-submit" data-confirm-title="%s" data-confirm-message="%s" data-confirm-label="%s" data-confirm-danger="1"><span class="dashicons dashicons-update"></span> %s</button>',
			esc_attr__( 'Regenerate API key?', 'stagify' ),
			esc_attr__( 'This will replace the current key. The staging site must be updated to match.', 'stagify' ),
			esc_attr__( 'Regenerate', 'stagify' ),
			esc_html__( 'Regenerate', 'stagify' )
		);
		echo '</form>';
	}

	/**
	 * Render the initial generate key form (when no key exists).
	 *
	 * @return void
	 */
	private function render_generate_key_form(): void {
		echo '<form method="post">';
		wp_nonce_field( 'stagify_receiver_settings' );
		echo '<input type="hidden" name="stagify_receiver_action" value="generate_api_key">';
		printf(
			'<button type="submit" class="button button-primary">%s</button>',
			esc_html__( 'Generate API key', 'stagify' )
		);
		echo '</form>';
	}

	/**
	 * Render the mode switch section.
	 *
	 * @return void
	 */
	private function render_mode_section(): void {
		printf(
			'<div class="stagify-mode-bar">'
			. '<span>%s <strong>%s</strong></span>'
			. '<button type="button" class="button button-small" id="stagify-switch-mode-btn">%s</button>'
			. '</div>',
			esc_html__( 'Mode:', 'stagify' ),
			esc_html__( 'Production', 'stagify' ),
			esc_html__( 'Switch mode', 'stagify' )
		);

		$this->render_receiver_mode_confirm();
	}

	/**
	 * Render the mode-switch confirmation panel for receiver.
	 *
	 * @return void
	 */
	private function render_receiver_mode_confirm(): void {
		printf(
			'<div class="stagify-mode-confirm" id="stagify-mode-confirm" style="display:none;">'
			. '<div class="stagify-mode-confirm-inner">'
			. '<strong>%s</strong>'
			. '<p>%s</p>'
			. '<div class="stagify-mode-confirm-actions">'
			. '<a href="%s" class="button button-primary stagify-btn-danger">%s</a>'
			. '<button type="button" class="button" id="stagify-switch-mode-cancel">%s</button>'
			. '</div></div></div>',
			esc_html__( 'Change plugin mode?', 'stagify' ),
			esc_html__( 'You will be redirected to choose a new mode. This will change which features are active on this site.', 'stagify' ),
			esc_url( admin_url( 'admin.php?page=stagify-setup' ) ),
			esc_html__( 'Continue', 'stagify' ),
			esc_html__( 'Cancel', 'stagify' )
		);
	}
}
