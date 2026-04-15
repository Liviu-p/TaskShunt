<?php
/**
 * First-run setup page — mode chooser.
 *
 * @package Stagify\Admin\Pages
 */

declare(strict_types=1);

namespace Stagify\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stagify\Domain\PluginMode;

/**
 * Renders a full-page mode selection screen on first activation.
 */
final class SetupPage {

	/**
	 * WordPress option key that stores the selected mode.
	 */
	public const OPTION_KEY = 'stagify_plugin_mode';

	/**
	 * Return the currently stored mode, or null if not yet chosen.
	 *
	 * @return PluginMode|null
	 */
	public static function get_mode(): ?PluginMode {
		$value = get_option( self::OPTION_KEY, '' );
		return PluginMode::tryFrom( (string) $value );
	}

	/**
	 * Register the hidden setup admin page.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'admin_menu',
			function (): void {
				add_submenu_page(
					'',
					__( 'Stagify Setup', 'stagify' ),
					'',
					'manage_options',
					'stagify-setup',
					function (): void {
						$this->render();
					}
				);
			}
		);

		add_action(
			'admin_enqueue_scripts',
			static function (): void {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
				if ( 'stagify-setup' === $page ) {
					wp_enqueue_style(
						'stagify-admin',
						STAGIFY_PLUGIN_URL . 'assets/css/stagify-admin.css',
						array(),
						STAGIFY_VERSION
					);
				}
			}
		);
	}

	/**
	 * Redirect to the setup page if no mode is set.
	 *
	 * Hooked on admin_init so it fires before any page renders.
	 *
	 * @return void
	 */
	public function maybe_redirect(): void {
		add_action(
			'admin_init',
			static function (): void {
				if ( null !== self::get_mode() ) {
					return;
				}

				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}

				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

				if ( 'stagify-setup' === $page ) {
					return;
				}

				if ( wp_doing_ajax() || wp_doing_cron() ) {
					return;
				}

				// Allow the save-mode form submission through.
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$action = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : '';
				if ( 'stagify_save_mode' === $action ) {
					return;
				}

				wp_safe_redirect( admin_url( 'admin.php?page=stagify-setup' ) );
				exit;
			}
		);
	}

	/**
	 * Output the setup page HTML.
	 *
	 * @return void
	 */
	public function render(): void {
		echo '<div class="wrap stagify-wrap stagify-setup">';

		echo '<h1>' . esc_html__( 'Welcome to Stagify', 'stagify' ) . '</h1>';
		echo '<p class="stagify-subtitle">'
			. esc_html__( 'Sync content changes between staging and production.', 'stagify' )
			. '</p>';
		echo '<h2 class="stagify-setup-choose">' . esc_html__( 'What is this site?', 'stagify' ) . '</h2>';

		$this->render_setup_form();

		echo '<p class="stagify-setup-hint">'
			. esc_html__( 'Install Stagify on both sites. We\'ll guide you through the rest.', 'stagify' )
			. '</p>';

		echo '</div>';
	}

	/**
	 * Render the setup form with mode cards and submit button.
	 *
	 * @return void
	 */
	private function render_setup_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="stagify-setup-form">';
		echo '<input type="hidden" name="action" value="stagify_save_mode">';
		echo '<input type="hidden" name="stagify_mode" value="" id="stagify-mode-input">';
		wp_nonce_field( 'stagify_save_mode' );

		echo '<div class="stagify-setup-cards">';
		$this->render_mode_card(
			PluginMode::Sender,
			__( 'Staging', 'stagify' ),
			__( 'I edit and test content here.', 'stagify' ),
			'dashicons-edit'
		);
		$this->render_mode_card(
			PluginMode::Receiver,
			__( 'Production', 'stagify' ),
			__( 'This is my live website.', 'stagify' ),
			'dashicons-admin-site-alt3'
		);
		echo '</div>';

		echo '<div class="stagify-setup-submit" id="stagify-setup-submit" style="display:none;">';
		printf(
			'<button type="submit" class="button button-primary stagify-setup-go" id="stagify-setup-go">%s</button>',
			esc_html__( 'Continue', 'stagify' )
		);
		echo '</div>';
		echo '</form>';
	}

	/**
	 * Render a selectable mode card (no submit button inside).
	 *
	 * @param PluginMode $mode  The mode enum value.
	 * @param string     $title Card title.
	 * @param string     $desc  Short description.
	 * @param string     $icon  Dashicons class.
	 * @return void
	 */
	private function render_mode_card( PluginMode $mode, string $title, string $desc, string $icon ): void {
		printf(
			'<div class="stagify-setup-card-form">'
			. '<div class="stagify-card stagify-card--selectable" data-mode="%s" data-label="%s">'
			. '<span class="stagify-card-radio"></span>'
			. '<span class="dashicons %s stagify-card-icon"></span>'
			. '<h3>%s</h3>'
			. '<p>%s</p>'
			. '</div>'
			. '</div>',
			esc_attr( $mode->value ),
			esc_attr(
				sprintf(
				/* translators: %s: mode name */
					__( 'Continue as %s', 'stagify' ),
					$title
				) 
			),
			esc_attr( $icon ),
			esc_html( $title ),
			esc_html( $desc )
		);
	}
}
