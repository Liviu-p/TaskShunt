<?php
/**
 * First-run setup page — mode chooser.
 *
 * @package TaskShunt\Admin\Pages
 */

declare(strict_types=1);

namespace TaskShunt\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Domain\PluginMode;

/**
 * Renders a full-page mode selection screen on first activation.
 */
final class SetupPage {

	/**
	 * WordPress option key that stores the selected mode.
	 */
	public const OPTION_KEY = 'taskshunt_plugin_mode';

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
					__( 'TaskShunt Setup', 'taskshunt' ),
					'',
					'manage_options',
					'taskshunt-setup',
					function (): void {
						$this->render();
					}
				);
			}
		);

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue setup page assets.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( 'taskshunt-setup' !== $page ) {
			return;
		}

		wp_enqueue_style( 'taskshunt-admin', TASKSHUNT_PLUGIN_URL . 'assets/css/taskshunt-admin.css', array(), TASKSHUNT_VERSION );
		wp_enqueue_script( 'taskshunt-admin', TASKSHUNT_PLUGIN_URL . 'assets/dist/admin.js', array(), TASKSHUNT_VERSION, true );
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

				if ( 'taskshunt-setup' === $page ) {
					return;
				}

				if ( wp_doing_ajax() || wp_doing_cron() ) {
					return;
				}

				// Allow the save-mode form submission through.
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$action = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : '';
				if ( 'taskshunt_save_mode' === $action ) {
					return;
				}

				wp_safe_redirect( admin_url( 'admin.php?page=taskshunt-setup' ) );
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
		echo '<div class="wrap taskshunt-wrap taskshunt-setup">';

		echo '<h1>' . esc_html__( 'Welcome to TaskShunt', 'taskshunt' ) . '</h1>';
		echo '<p class="taskshunt-subtitle">'
			. esc_html__( 'Sync content changes between staging and production.', 'taskshunt' )
			. '</p>';
		echo '<h2 class="taskshunt-setup-choose">' . esc_html__( 'What is this site?', 'taskshunt' ) . '</h2>';

		$this->render_setup_form();

		echo '<p class="taskshunt-setup-hint">'
			. esc_html__( 'Install TaskShunt on both sites. We\'ll guide you through the rest.', 'taskshunt' )
			. '</p>';

		echo '</div>';
	}

	/**
	 * Render the setup form with mode cards and submit button.
	 *
	 * @return void
	 */
	private function render_setup_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="taskshunt-setup-form">';
		echo '<input type="hidden" name="action" value="taskshunt_save_mode">';
		echo '<input type="hidden" name="taskshunt_mode" value="" id="taskshunt-mode-input">';
		wp_nonce_field( 'taskshunt_save_mode' );

		echo '<div class="taskshunt-setup-cards">';
		$this->render_mode_card(
			PluginMode::Sender,
			__( 'Staging', 'taskshunt' ),
			__( 'I edit and test content here.', 'taskshunt' ),
			'dashicons-edit'
		);
		$this->render_mode_card(
			PluginMode::Receiver,
			__( 'Production', 'taskshunt' ),
			__( 'This is my live website.', 'taskshunt' ),
			'dashicons-admin-site-alt3'
		);
		echo '</div>';

		echo '<div class="taskshunt-setup-submit" id="taskshunt-setup-submit" style="display:none;">';
		printf(
			'<button type="submit" class="button button-primary taskshunt-setup-go" id="taskshunt-setup-go">%s</button>',
			esc_html__( 'Continue', 'taskshunt' )
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
			'<div class="taskshunt-setup-card-form">'
			. '<div class="taskshunt-card taskshunt-card--selectable" data-mode="%s" data-label="%s">'
			. '<span class="taskshunt-card-radio"></span>'
			. '<span class="dashicons %s taskshunt-card-icon"></span>'
			. '<h3>%s</h3>'
			. '<p>%s</p>'
			. '</div>'
			. '</div>',
			esc_attr( $mode->value ),
			esc_attr(
				sprintf(
				/* translators: %s: mode name */
					__( 'Continue as %s', 'taskshunt' ),
					$title
				) 
			),
			esc_attr( $icon ),
			esc_html( $title ),
			esc_html( $desc )
		);
	}
}
