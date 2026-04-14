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
		echo '<h1>' . esc_html__( 'Stagify Settings', 'stagify' ) . '</h1>';

		$this->render_mode_section();
		$this->render_server_section( $server );
		$this->render_tracking_section();

		echo '</div>';
		$this->render_api_key_toggle_script();
	}

	/**
	 * Render the plugin mode section with the current mode and a change link.
	 *
	 * @return void
	 */
	private function render_mode_section(): void {
		$mode = SetupPage::get_mode();

		echo '<h2>' . esc_html__( 'Plugin mode', 'stagify' ) . '</h2>';
		printf(
			'<p><strong>%s</strong> &mdash; <a href="%s" onclick="return confirm(\'%s\');">%s</a></p>',
			null !== $mode ? esc_html( $mode->label() ) : esc_html__( 'Not set', 'stagify' ),
			esc_url( admin_url( 'admin.php?page=stagify-setup' ) ),
			esc_js( __( 'Changing the mode will alter which features are active. Continue?', 'stagify' ) ),
			esc_html__( 'Change', 'stagify' )
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
		echo '<h2>' . esc_html__( 'Target server', 'stagify' ) . '</h2>';

		if ( null !== $server ) {
			$this->render_server_card( $server );
			return;
		}

		$this->render_server_form();
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

		echo '<h2>' . esc_html__( 'Content tracking', 'stagify' ) . '</h2>';
		echo '<p>' . esc_html__( 'Select which post types Stagify should track.', 'stagify' ) . '</p>';

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="stagify_save_tracking">';
		wp_nonce_field( 'stagify_save_tracking' );

		$this->render_post_type_checkboxes( $post_types, $tracked );

		submit_button( __( 'Save tracking settings', 'stagify' ) );
		echo '</form>';
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
			'<table class="form-table" role="presentation"><tbody>'
			. '<tr><th>%s</th><td><strong>%s</strong></td></tr>'
			. '<tr><th>%s</th><td><code>%s</code></td></tr>'
			. '<tr><th>%s</th><td><code>%s</code></td></tr>'
			. '</tbody></table>',
			esc_html__( 'Name', 'stagify' ),
			esc_html( $server->name ),
			esc_html__( 'URL', 'stagify' ),
			esc_html( $server->url->get_value() ),
			esc_html__( 'API Key', 'stagify' ),
			esc_html( str_repeat( '•', 16 ) )
		);

		$this->render_test_button();

		printf(
			'<p style="margin-top:12px;"><a href="%s" class="button button-link-delete" onclick="return confirm(\'%s\');">%s</a></p>',
			esc_url( $delete_url ),
			esc_js( __( 'Remove this server?', 'stagify' ) ),
			esc_html__( 'Remove server', 'stagify' )
		);
	}

	/**
	 * Render the test connection button and inline result placeholder.
	 *
	 * @return void
	 */
	private function render_test_button(): void {
		printf(
			'<p>'
			. '<button type="button" id="stagify-test-connection" class="button button-secondary">%s</button>'
			. '<span id="stagify-test-result"></span>'
			. '</p>',
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
			'<form method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="stagify_save_server">';
		wp_nonce_field( 'stagify_save_server' );

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_name_field();
		$this->render_url_field();
		$this->render_api_key_field();
		echo '</tbody></table>';

		submit_button( __( 'Save server', 'stagify' ) );
		echo '</form>';
	}

	/**
	 * Render the server name table row.
	 *
	 * @return void
	 */
	private function render_name_field(): void {
		printf(
			'<tr><th scope="row"><label for="stagify_server_name">%s</label></th>'
			. '<td><input type="text" id="stagify_server_name" name="stagify_server_name" class="regular-text" required></td></tr>',
			esc_html__( 'Server name', 'stagify' )
		);
	}

	/**
	 * Render the server URL table row.
	 *
	 * @return void
	 */
	private function render_url_field(): void {
		printf(
			'<tr><th scope="row"><label for="stagify_server_url">%s</label></th>'
			. '<td><input type="url" id="stagify_server_url" name="stagify_server_url" class="regular-text" placeholder="%s" required></td></tr>',
			esc_html__( 'Server URL', 'stagify' ),
			esc_attr__( 'https://yoursite.com', 'stagify' )
		);
	}

	/**
	 * Render the API key table row with a show/hide toggle.
	 *
	 * @return void
	 */
	private function render_api_key_field(): void {
		printf(
			'<tr><th scope="row"><label for="stagify_api_key">%s</label></th>'
			. '<td>'
			. '<input type="password" id="stagify_api_key" name="stagify_api_key" class="regular-text" autocomplete="new-password" required>'
			. ' <button type="button" id="stagify-toggle-key" class="button button-secondary">%s</button>'
			. '</td></tr>',
			esc_html__( 'API Key', 'stagify' ),
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
