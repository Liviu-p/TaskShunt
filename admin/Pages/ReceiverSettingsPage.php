<?php
/**
 * Receiver settings admin page.
 *
 * @package TaskShunt\Admin\Pages
 */

declare(strict_types=1);

namespace TaskShunt\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Admin\OnboardingChecklist;
use TaskShunt\Api\ReceiverApi;

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
		add_action( 'admin_menu', fn() => $this->register_menu() );
		add_action( 'admin_init', fn() => $this->handle_save() );
		add_action(
			'admin_enqueue_scripts',
			static function (): void {
				wp_enqueue_style( 'taskshunt-admin', TASKSHUNT_PLUGIN_URL . 'assets/css/taskshunt-admin.css', array(), TASKSHUNT_VERSION );
				wp_enqueue_script( 'taskshunt-admin', TASKSHUNT_PLUGIN_URL . 'assets/dist/admin.js', array(), TASKSHUNT_VERSION, true );
			}
		);
	}

	/**
	 * Register the admin menu page.
	 *
	 * @return void
	 */
	private function register_menu(): void {
		$icon_svg = file_get_contents( TASKSHUNT_PLUGIN_DIR . 'assets/img/icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$icon_uri = 'data:image/svg+xml;base64,' . base64_encode( $icon_svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$this->hook = (string) add_menu_page(
			__( 'TaskShunt', 'taskshunt' ),
			__( 'TaskShunt', 'taskshunt' ),
			'manage_options',
			'taskshunt',
			function (): void {
				$this->render();
			},
			$icon_uri,
			80
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
		$action = isset( $_POST['taskshunt_receiver_action'] ) ? sanitize_key( $_POST['taskshunt_receiver_action'] ) : '';
		if ( '' === $action ) {
			return;
		}

		check_admin_referer( 'taskshunt_receiver_settings' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'taskshunt' ) );
		}

		if ( 'save_api_key' === $action ) {
			$api_key = isset( $_POST['taskshunt_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['taskshunt_api_key'] ) ) : '';
			update_option( ReceiverApi::API_KEY_OPTION, $api_key );
			add_settings_error( 'taskshunt', 'taskshunt_saved', __( 'API key saved.', 'taskshunt' ), 'success' );
		}

		if ( 'generate_api_key' === $action ) {
			$api_key = wp_generate_password( 40, false );
			update_option( ReceiverApi::API_KEY_OPTION, $api_key );
			add_settings_error( 'taskshunt', 'taskshunt_generated', __( 'New API key generated.', 'taskshunt' ), 'success' );
		}

		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=taskshunt&settings-updated=true' ) );
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
		$site_url = site_url();

		echo '<div class="wrap taskshunt-wrap">';

		echo '<div class="taskshunt-page-header">';
		echo '<h1>' . esc_html__( 'Production', 'taskshunt' ) . '</h1>';
		echo '</div>';
		echo '<p class="taskshunt-subheading">' . esc_html__( 'Accept and apply changes pushed from your staging server.', 'taskshunt' ) . '</p>';

		settings_errors( 'taskshunt' );

		OnboardingChecklist::render_receiver();

		$this->render_status_section( $has_key, $site_url );
		$this->render_api_key_section( $api_key, $has_key );
		$this->render_mode_section();

		echo '</div>';
	}

	/**
	 * Render the connection status section.
	 *
	 * @param bool   $has_key  Whether an API key is configured.
	 * @param string $site_url The site URL to display.
	 * @return void
	 */
	private function render_status_section( bool $has_key, string $site_url ): void {
		if ( $has_key ) {
			printf(
				'<div class="taskshunt-status-card taskshunt-status-card--ready">'
				. '<span class="taskshunt-status-dot taskshunt-status-dot--ready"></span>'
				. '<div>'
				. '<strong>%s</strong>'
				. '<p>%s <code>%s</code></p>'
				. '</div>'
				. '</div>',
				esc_html__( 'Ready to receive', 'taskshunt' ),
				esc_html__( 'Site URL:', 'taskshunt' ),
				esc_html( $site_url )
			);
		} else {
			printf(
				'<div class="taskshunt-status-card taskshunt-status-card--inactive">'
				. '<span class="taskshunt-status-dot taskshunt-status-dot--inactive"></span>'
				. '<div>'
				. '<strong>%s</strong>'
				. '<p>%s</p>'
				. '</div>'
				. '</div>',
				esc_html__( 'Not configured', 'taskshunt' ),
				esc_html__( 'Generate or set an API key below to start receiving pushes.', 'taskshunt' )
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
		echo '<div class="taskshunt-section-card">';
		echo '<h2>' . esc_html__( 'API Key', 'taskshunt' ) . '</h2>';
		echo '<p>' . esc_html__( 'Copy this key and paste it in the staging (sender) site settings.', 'taskshunt' ) . '</p>';

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
			'<div class="taskshunt-apikey-display">'
			. '<code id="taskshunt-key-value" data-key="%s">%s</code>'
			. '<button type="button" class="button button-small" id="taskshunt-toggle-receiver-key" data-label-show="%s" data-label-hide="%s">%s</button>'
			. '<button type="button" class="button button-small" id="taskshunt-copy-key" title="%s" data-copied="%s">%s</button>'
			. '</div>',
			esc_attr( $api_key ),
			esc_html( str_repeat( "\u{2022}", 20 ) ),
			esc_attr__( 'Show', 'taskshunt' ),
			esc_attr__( 'Hide', 'taskshunt' ),
			esc_html__( 'Show', 'taskshunt' ),
			esc_attr__( 'Copy to clipboard', 'taskshunt' ),
			esc_attr__( 'Copied!', 'taskshunt' ),
			esc_html__( 'Copy', 'taskshunt' )
		);

		echo '<form method="post" class="taskshunt-apikey-generate">';
		wp_nonce_field( 'taskshunt_receiver_settings' );
		echo '<input type="hidden" name="taskshunt_receiver_action" value="generate_api_key">';
		printf(
			'<button type="submit" class="button button-small taskshunt-confirm-submit" data-confirm-title="%s" data-confirm-message="%s" data-confirm-label="%s" data-confirm-danger="1"><span class="dashicons dashicons-update"></span> %s</button>',
			esc_attr__( 'Regenerate API key?', 'taskshunt' ),
			esc_attr__( 'This will replace the current key. The staging site must be updated to match.', 'taskshunt' ),
			esc_attr__( 'Regenerate', 'taskshunt' ),
			esc_html__( 'Regenerate', 'taskshunt' )
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
		wp_nonce_field( 'taskshunt_receiver_settings' );
		echo '<input type="hidden" name="taskshunt_receiver_action" value="generate_api_key">';
		printf(
			'<button type="submit" class="button button-primary">%s</button>',
			esc_html__( 'Generate API key', 'taskshunt' )
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
			'<div class="taskshunt-mode-bar">'
			. '<span>%s <strong>%s</strong></span>'
			. '<button type="button" class="button button-small" id="taskshunt-switch-mode-btn">%s</button>'
			. '</div>',
			esc_html__( 'Mode:', 'taskshunt' ),
			esc_html__( 'Production', 'taskshunt' ),
			esc_html__( 'Switch mode', 'taskshunt' )
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
			'<div class="taskshunt-mode-confirm" id="taskshunt-mode-confirm" style="display:none;">'
			. '<div class="taskshunt-mode-confirm-inner">'
			. '<strong>%s</strong>'
			. '<p>%s</p>'
			. '<div class="taskshunt-mode-confirm-actions">'
			. '<a href="%s" class="button button-primary taskshunt-btn-danger">%s</a>'
			. '<button type="button" class="button" id="taskshunt-switch-mode-cancel">%s</button>'
			. '</div></div></div>',
			esc_html__( 'Change plugin mode?', 'taskshunt' ),
			esc_html__( 'You will be redirected to choose a new mode. This will change which features are active on this site.', 'taskshunt' ),
			esc_url( admin_url( 'admin.php?page=taskshunt-setup' ) ),
			esc_html__( 'Continue', 'taskshunt' ),
			esc_html__( 'Cancel', 'taskshunt' )
		);
	}
}
