<?php
/**
 * Settings admin page.
 *
 * @package Stagify\Admin\Pages
 */

declare(strict_types=1);

namespace Stagify\Admin\Pages;

use Stagify\Admin\Pages\SetupPage;
use Stagify\Contracts\ServerRepositoryInterface;
use Stagify\Domain\Server;
use Stagify\Services\PostTypeRegistry;

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

		echo '<div class="wrap stagify-wrap">';

		echo '<div class="stagify-page-header">';
		echo '<h1>' . esc_html__( 'Settings', 'stagify' ) . '</h1>';
		echo '</div>';
		echo '<p class="stagify-subheading">' . esc_html__( 'Configure your staging server and content tracking.', 'stagify' ) . '</p>';

		$this->render_server_section( $server );
		$this->render_tracking_section();
		$this->render_mode_section();

		echo '</div>';
		$this->render_api_key_toggle_script();
	}

	/**
	 * Render the plugin mode section with the current mode and a change link.
	 *
	 * @return void
	 */
	private function render_mode_section(): void {
		$mode      = SetupPage::get_mode();
		$setup_url = esc_url( admin_url( 'admin.php?page=stagify-setup' ) );

		printf(
			'<div class="stagify-mode-bar">'
			. '<span>%s <strong>%s</strong></span>'
			. '<button type="button" class="button button-small" id="stagify-switch-mode-btn">%s</button>'
			. '</div>',
			esc_html__( 'Mode:', 'stagify' ),
			null !== $mode ? esc_html( $mode->label() ) : esc_html__( 'Not set', 'stagify' ),
			esc_html__( 'Switch mode', 'stagify' )
		);

		printf(
			'<div class="stagify-mode-confirm" id="stagify-mode-confirm" style="display:none;">'
			. '<div class="stagify-mode-confirm-inner">'
			. '<strong>%s</strong>'
			. '<p>%s</p>'
			. '<div class="stagify-mode-confirm-actions">'
			. '<a href="%s" class="button button-primary stagify-btn-danger">%s</a>'
			. '<button type="button" class="button" id="stagify-switch-mode-cancel">%s</button>'
			. '</div>'
			. '</div>'
			. '</div>',
			esc_html__( 'Change plugin mode?', 'stagify' ),
			esc_html__( 'You will be redirected to choose a new mode. This will change which features are active on this site.', 'stagify' ),
			$setup_url,
			esc_html__( 'Continue', 'stagify' ),
			esc_html__( 'Cancel', 'stagify' )
		);

		echo '<script>'
			. '(function(){'
			. 'var btn=document.getElementById("stagify-switch-mode-btn");'
			. 'var panel=document.getElementById("stagify-mode-confirm");'
			. 'var cancel=document.getElementById("stagify-switch-mode-cancel");'
			. 'if(!btn||!panel||!cancel)return;'
			. 'btn.addEventListener("click",function(){panel.style.display="block";btn.style.display="none";});'
			. 'cancel.addEventListener("click",function(){panel.style.display="none";btn.style.display="inline-flex";});'
			. '})();'
			. '</script>';
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
		echo '<div class="stagify-section-card">';
		echo '<h2>' . esc_html__( 'Production server', 'stagify' ) . '</h2>';

		if ( null !== $server ) {
			$this->render_server_card( $server );
		} else {
			echo '<p>' . esc_html__( 'Connect the production site where changes will be pushed.', 'stagify' ) . '</p>';
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

		echo '<div class="stagify-section-card">';
		echo '<h2>' . esc_html__( 'Content tracking', 'stagify' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose which post types to track for changes.', 'stagify' ) . '</p>';

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="stagify_save_tracking">';
		wp_nonce_field( 'stagify_save_tracking' );

		$this->render_post_type_checkboxes( $post_types, $tracked );

		printf(
			'<button type="submit" class="button button-primary" style="margin-top:16px;">%s</button>',
			esc_html__( 'Save', 'stagify' )
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
				. '<input type="checkbox" name="stagify_post_types[]" value="%s"%s> %s <code>%s</code>'
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
		if ( 'stagify_page_stagify-settings' !== $hook ) {
			return;
		}

		$asset_url = STAGIFY_PLUGIN_URL . 'assets/dist/settings.js';

		wp_enqueue_script(
			'stagify-settings',
			$asset_url,
			array(),
			STAGIFY_VERSION,
			true
		);

		wp_localize_script(
			'stagify-settings',
			'stagifySettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'stagify_test_connection' ),
			)
		);
	}

	/**
	 * Render a card showing the currently configured server.
	 *
	 * @param Server $server The configured server entity.
	 * @return void
	 */
	private function render_server_card( Server $server ): void {
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'stagify_delete_server',
					'task_id' => $server->id,
				),
				admin_url( 'admin-post.php' )
			),
			'stagify_delete_server'
		);

		printf(
			'<div class="stagify-status-card stagify-status-card--ready" style="margin-top:0;">'
			. '<span class="stagify-status-dot stagify-status-dot--ready"></span>'
			. '<div>'
			. '<strong>%s</strong>'
			. '<p>%s</p>'
			. '</div>'
			. '<div class="stagify-server-actions">',
			esc_html( $server->name ),
			esc_html( $server->url->get_value() )
		);
		$this->render_test_button();
		printf(
			'<a href="%s" class="button button-small stagify-link-danger stagify-confirm-link" data-confirm-title="%s" data-confirm-message="%s" data-confirm-label="%s" data-confirm-danger="1">%s</a>',
			esc_url( $delete_url ),
			esc_attr__( 'Disconnect server?', 'stagify' ),
			esc_attr__( 'This will remove the production server connection. You can re-add it later.', 'stagify' ),
			esc_attr__( 'Disconnect', 'stagify' ),
			esc_html__( 'Disconnect', 'stagify' )
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
			'<button type="button" id="stagify-test-connection" class="button button-small">%s</button>'
			. '<span id="stagify-test-result" class="stagify-test-result"></span>',
			esc_html__( 'Test connection', 'stagify' )
		);
	}

	/**
	 * Render the add-server form.
	 *
	 * @return void
	 */
	private function render_server_form(): void {
		printf(
			'<form method="post" action="%s" class="stagify-server-form">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="stagify_save_server">';
		wp_nonce_field( 'stagify_save_server' );

		$this->render_name_field();
		$this->render_url_field();
		$this->render_api_key_field();

		printf(
			'<button type="submit" class="button button-primary">%s</button>',
			esc_html__( 'Connect server', 'stagify' )
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
			'<div class="stagify-field">'
			. '<label for="stagify_server_name">%s</label>'
			. '<input type="text" id="stagify_server_name" name="stagify_server_name" placeholder="%s" required>'
			. '</div>',
			esc_html__( 'Name', 'stagify' ),
			esc_attr__( 'e.g. Production', 'stagify' )
		);
	}

	/**
	 * Render the server URL field.
	 *
	 * @return void
	 */
	private function render_url_field(): void {
		printf(
			'<div class="stagify-field">'
			. '<label for="stagify_server_url">%s</label>'
			. '<input type="url" id="stagify_server_url" name="stagify_server_url" placeholder="%s" required>'
			. '</div>',
			esc_html__( 'URL', 'stagify' ),
			esc_attr__( 'https://yoursite.com', 'stagify' )
		);
	}

	/**
	 * Render the API key field with show/hide toggle.
	 *
	 * @return void
	 */
	private function render_api_key_field(): void {
		printf(
			'<div class="stagify-field">'
			. '<label for="stagify_api_key">%s</label>'
			. '<div class="stagify-field-row">'
			. '<input type="password" id="stagify_api_key" name="stagify_api_key" autocomplete="new-password" placeholder="%s" required>'
			. '<button type="button" id="stagify-toggle-key" class="button button-small">%s</button>'
			. '</div>'
			. '</div>',
			esc_html__( 'API Key', 'stagify' ),
			esc_attr__( 'Paste from production site', 'stagify' ),
			esc_html__( 'Show', 'stagify' )
		);
	}

	/**
	 * Output the inline JS for the API key show/hide toggle.
	 *
	 * @return void
	 */
	private function render_api_key_toggle_script(): void {
		echo '<script>'
			. '(function(){'
			. 'var btn=document.getElementById("stagify-toggle-key");'
			. 'var inp=document.getElementById("stagify_api_key");'
			. 'if(!btn||!inp)return;'
			. 'btn.addEventListener("click",function(){'
			. 'var shown=inp.type==="text";'
			. 'inp.type=shown?"password":"text";'
			. 'btn.textContent=shown?"' . esc_js( __( 'Show', 'stagify' ) ) . '":"' . esc_js( __( 'Hide', 'stagify' ) ) . '";'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. '});'
			. '})();'
			. '</script>';
	}
}
