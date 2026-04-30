<?php
/**
 * Settings admin page.
 *
 * @package TaskShunt\Admin\Pages
 */

declare(strict_types=1);

namespace TaskShunt\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Admin\Pages\SetupPage;
use TaskShunt\Contracts\ServerRepositoryInterface;
use TaskShunt\Domain\Server;
use TaskShunt\Services\PostTypeRegistry;

/**
 * Renders the Settings admin page — server connection form.
 */
final class SettingsPage {

	/**
	 * Create the settings page.
	 *
	 * @param ServerRepositoryInterface $server_repository Server repository.
	 */
	public function __construct(
		private readonly ServerRepositoryInterface $server_repository,
	) {}

	/**
	 * Output the page HTML.
	 *
	 * @return void
	 */
	public function render(): void {
		$server = $this->server_repository->find();

		echo '<div class="wrap taskshunt-wrap">';

		echo '<div class="taskshunt-page-header">';
		echo '<h1>' . esc_html__( 'Settings', 'taskshunt' ) . '</h1>';
		echo '</div>';
		echo '<p class="taskshunt-subheading">' . esc_html__( 'Configure your staging server and content tracking.', 'taskshunt' ) . '</p>';

		$this->render_server_section( $server );
		$this->render_tracking_section();
		$this->render_cleanup_section();
		$this->render_mode_section();

		echo '</div>';
	}

	/**
	 * Render the auto-cleanup section with enable toggle and days input.
	 *
	 * @return void
	 */
	private function render_cleanup_section(): void {
		$settings = (array) get_option( 'taskshunt_cleanup', array() );
		$enabled  = ( $settings['enabled'] ?? true );
		$days     = (int) ( $settings['days'] ?? 30 );

		echo '<div class="taskshunt-section-card">';
		echo '<h2>' . esc_html__( 'Auto-cleanup', 'taskshunt' ) . '</h2>';
		echo '<p>' . esc_html__( 'Automatically delete pushed tasks after a set number of days.', 'taskshunt' ) . '</p>';

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="taskshunt_save_cleanup">';
		wp_nonce_field( 'taskshunt_save_cleanup' );

		$this->render_cleanup_fields( $enabled, $days );

		printf(
			'<button type="submit" class="button button-primary" style="margin-top:16px;">%s</button>',
			esc_html__( 'Save', 'taskshunt' )
		);
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the cleanup form fields.
	 *
	 * @param bool $enabled Whether auto-cleanup is enabled.
	 * @param int  $days    Retention period in days.
	 * @return void
	 */
	private function render_cleanup_fields( bool $enabled, int $days ): void {
		printf(
			'<fieldset style="margin-top:8px;">'
			. '<label style="display:block;margin-bottom:12px;">'
			. '<input type="checkbox" name="taskshunt_cleanup_enabled" value="1"%s> %s'
			. '</label>'
			. '<label style="display:block;">'
			. '%s '
			. '<input type="number" name="taskshunt_cleanup_days" value="%d" min="1" max="365" style="width:70px;"> '
			. '%s'
			. '</label>'
			. '</fieldset>',
			$enabled ? ' checked' : '',
			esc_html__( 'Enable auto-cleanup', 'taskshunt' ),
			esc_html__( 'Delete pushed tasks older than', 'taskshunt' ),
			esc_attr( (string) $days ),
			esc_html__( 'days', 'taskshunt' )
		);
	}

	/**
	 * Render the plugin mode section with the current mode and a change link.
	 *
	 * @return void
	 */
	private function render_mode_section(): void {
		$mode = SetupPage::get_mode();

		printf(
			'<div class="taskshunt-mode-bar">'
			. '<span>%s <strong>%s</strong></span>'
			. '<button type="button" class="button button-small" id="taskshunt-switch-mode-btn">%s</button>'
			. '</div>',
			esc_html__( 'Mode:', 'taskshunt' ),
			null !== $mode ? esc_html( $mode->label() ) : esc_html__( 'Not set', 'taskshunt' ),
			esc_html__( 'Switch mode', 'taskshunt' )
		);

		$this->render_mode_confirm_panel();
	}

	/**
	 * Render the mode-switch confirmation panel (hidden by default).
	 *
	 * @return void
	 */
	private function render_mode_confirm_panel(): void {
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

	/**
	 * Render the server connection section.
	 *
	 * Shows the current server card if one is configured, otherwise the add form.
	 *
	 * @param Server|null $server Currently configured server, or null.
	 * @return void
	 */
	private function render_server_section( ?Server $server ): void {
		echo '<div class="taskshunt-section-card">';
		echo '<h2>' . esc_html__( 'Production server', 'taskshunt' ) . '</h2>';

		if ( null !== $server ) {
			$this->render_server_card( $server );
		} else {
			echo '<p>' . esc_html__( 'Connect the production site where changes will be pushed.', 'taskshunt' ) . '</p>';
			$this->render_server_form();
		}

		echo '</div>';
	}

	/**
	 * Render the content tracking section with post type checkboxes.
	 *
	 * @return void
	 */
	private function render_tracking_section(): void {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$tracked    = get_option( PostTypeRegistry::OPTION_KEY, false );
		$tracked    = false !== $tracked ? (array) $tracked : array_keys( $post_types );

		echo '<div class="taskshunt-section-card">';
		echo '<h2>' . esc_html__( 'Content tracking', 'taskshunt' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose which post types to track for changes.', 'taskshunt' ) . '</p>';

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="taskshunt_save_tracking">';
		wp_nonce_field( 'taskshunt_save_tracking' );

		$this->render_post_type_checkboxes( $post_types, $tracked );

		printf(
			'<button type="submit" class="button button-primary" style="margin-top:16px;">%s</button>',
			esc_html__( 'Save', 'taskshunt' )
		);
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render a checkbox for each public post type.
	 *
	 * @param array<string, \WP_Post_Type> $post_types All public post type objects.
	 * @param array<int, string>           $tracked    Currently tracked slugs.
	 * @return void
	 */
	private function render_post_type_checkboxes( array $post_types, array $tracked ): void {
		echo '<fieldset style="margin-top:8px;">';

		foreach ( $post_types as $slug => $type ) {
			$checked = in_array( $slug, $tracked, true ) ? ' checked' : '';
			printf(
				'<label style="display:block;margin-bottom:6px;">'
				. '<input type="checkbox" name="taskshunt_post_types[]" value="%s"%s> %s <code>%s</code>'
				. '</label>',
				esc_attr( $slug ),
				esc_attr( $checked ),
				esc_html( $type->labels->name ),
				esc_html( $slug )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Enqueue settings page assets.
	 *
	 * Called on admin_enqueue_scripts — only enqueues on the settings page hook.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'taskshunt_page_taskshunt-settings' !== $hook ) {
			return;
		}

		$asset_url = TASKSHUNT_PLUGIN_URL . 'assets/dist/settings.js';

		wp_enqueue_script(
			'taskshunt-settings',
			$asset_url,
			array(),
			TASKSHUNT_VERSION,
			true
		);

		wp_localize_script(
			'taskshunt-settings',
			'taskshuntSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'taskshunt_test_connection' ),
			)
		);
	}

	/**
	 * Render a card showing the currently configured server.
	 *
	 * @param Server $server The configured server entity.
	 * @return void
	 */
	private function render_server_card( Server $server ): void { // phpcs:ignore SlevomatCodingStandard.Functions.FunctionLength.FunctionLength
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'taskshunt_delete_server',
					'task_id' => $server->id,
				),
				admin_url( 'admin-post.php' )
			),
			'taskshunt_delete_server'
		);

		printf(
			'<div class="taskshunt-status-card taskshunt-status-card--ready" style="margin-top:0;">'
			. '<span class="taskshunt-status-dot taskshunt-status-dot--ready"></span>'
			. '<div>'
			. '<strong>%s</strong>'
			. '<p>%s</p>'
			. '</div>'
			. '<div class="taskshunt-server-actions">',
			esc_html( $server->name ),
			esc_html( $server->url->get_value() )
		);
		$this->render_test_button();
		printf(
			'<a href="%s" class="button button-small taskshunt-link-danger taskshunt-confirm-link" data-confirm-title="%s" data-confirm-message="%s" data-confirm-label="%s" data-confirm-danger="1">%s</a>',
			esc_url( $delete_url ),
			esc_attr__( 'Disconnect server?', 'taskshunt' ),
			esc_attr__( 'This will remove the production server connection. You can re-add it later.', 'taskshunt' ),
			esc_attr__( 'Disconnect', 'taskshunt' ),
			esc_html__( 'Disconnect', 'taskshunt' )
		);
		echo '</div></div>';
	}

	/**
	 * Render the test connection button and inline result placeholder.
	 *
	 * @return void
	 */
	private function render_test_button(): void {
		printf(
			'<button type="button" id="taskshunt-test-connection" class="button button-small">%s</button>'
			. '<span id="taskshunt-test-result" class="taskshunt-test-result"></span>',
			esc_html__( 'Test connection', 'taskshunt' )
		);
	}

	/**
	 * Render the add-server form.
	 *
	 * @return void
	 */
	private function render_server_form(): void {
		printf(
			'<form method="post" action="%s" class="taskshunt-server-form">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="taskshunt_save_server">';
		wp_nonce_field( 'taskshunt_save_server' );

		$this->render_name_field();
		$this->render_url_field();
		$this->render_api_key_field();

		printf(
			'<button type="submit" class="button button-primary">%s</button>',
			esc_html__( 'Connect server', 'taskshunt' )
		);
		echo '</form>';
	}

	/**
	 * Render the server name table row.
	 *
	 * @return void
	 */
	private function render_name_field(): void {
		printf(
			'<div class="taskshunt-field">'
			. '<label for="taskshunt_server_name">%s</label>'
			. '<input type="text" id="taskshunt_server_name" name="taskshunt_server_name" placeholder="%s" required>'
			. '</div>',
			esc_html__( 'Name', 'taskshunt' ),
			esc_attr__( 'e.g. Production', 'taskshunt' )
		);
	}

	/**
	 * Render the server URL field.
	 *
	 * @return void
	 */
	private function render_url_field(): void {
		printf(
			'<div class="taskshunt-field">'
			. '<label for="taskshunt_server_url">%s</label>'
			. '<input type="url" id="taskshunt_server_url" name="taskshunt_server_url" placeholder="%s" required>'
			. '</div>',
			esc_html__( 'URL', 'taskshunt' ),
			esc_attr__( 'https://yoursite.com', 'taskshunt' )
		);
	}

	/**
	 * Render the API key field with show/hide toggle.
	 *
	 * @return void
	 */
	private function render_api_key_field(): void {
		printf(
			'<div class="taskshunt-field">'
			. '<label for="taskshunt_api_key">%s</label>'
			. '<div class="taskshunt-field-row">'
			. '<input type="password" id="taskshunt_api_key" name="taskshunt_api_key" autocomplete="new-password" placeholder="%s" required>'
			. '<button type="button" id="taskshunt-toggle-key" class="button button-small" data-label-show="%s" data-label-hide="%s">%s</button>'
			. '</div>'
			. '</div>',
			esc_html__( 'API Key', 'taskshunt' ),
			esc_attr__( 'Paste from production site', 'taskshunt' ),
			esc_attr__( 'Show', 'taskshunt' ),
			esc_attr__( 'Hide', 'taskshunt' ),
			esc_html__( 'Show', 'taskshunt' )
		);
	}
}
