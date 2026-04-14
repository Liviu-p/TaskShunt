<?php
/**
 * First-run setup page — mode chooser.
 *
 * @package Stagify\Admin\Pages
 */

declare(strict_types=1);

namespace Stagify\Admin\Pages;

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
			. esc_html__( 'How will this site use Stagify?', 'stagify' )
			. '</p>';

		echo '<div class="stagify-setup-cards">';
		$this->render_sender_card();
		$this->render_receiver_card();
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the Sender mode card.
	 *
	 * @return void
	 */
	private function render_sender_card(): void {
		$this->render_mode_card(
			PluginMode::Sender,
			__( 'Staging (Sender)', 'stagify' ),
			__( 'This is a staging site. Track content and file changes, then push them to production.', 'stagify' ),
			'dashicons-upload'
		);
	}

	/**
	 * Render the Receiver mode card.
	 *
	 * @return void
	 */
	private function render_receiver_card(): void {
		$this->render_mode_card(
			PluginMode::Receiver,
			__( 'Production (Receiver)', 'stagify' ),
			__( 'This is a production site. Accept and apply changes pushed from a staging server.', 'stagify' ),
			'dashicons-download'
		);
	}

	/**
	 * Render a single mode selection card.
	 *
	 * @param PluginMode $mode  The mode this card represents.
	 * @param string     $title Card heading.
	 * @param string     $desc  Card description text.
	 * @param string     $icon  Dashicons class name.
	 * @return void
	 */
	private function render_mode_card( PluginMode $mode, string $title, string $desc, string $icon ): void {
		printf(
			'<form method="post" action="%s" style="flex:1;max-width:320px;">'
			. '<div class="stagify-card">'
			. '<span class="dashicons %s stagify-card-icon"></span>'
			. '<h2>%s</h2>'
			. '<p>%s</p>'
			. '<input type="hidden" name="action" value="stagify_save_mode">'
			. '<input type="hidden" name="stagify_mode" value="%s">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $icon ),
			esc_html( $title ),
			esc_html( $desc ),
			esc_attr( $mode->value )
		);
		wp_nonce_field( 'stagify_save_mode' );
		printf(
			'<button type="submit" class="button button-primary button-hero" style="margin-top:16px;">%s</button>'
			. '</div></form>',
			esc_html__( 'Select', 'stagify' )
		);
	}
}
