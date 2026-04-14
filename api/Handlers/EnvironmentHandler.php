<?php
/**
 * Environment item handler for the receiver API.
 *
 * Applies plugin/theme state changes on the receiver site.
 *
 * @package Stagify\Api\Handlers
 */

declare(strict_types=1);

namespace Stagify\Api\Handlers;

use Stagify\Domain\TaskAction;

/**
 * Applies a single environment change (plugin/theme lifecycle) on the receiver site.
 */
final class EnvironmentHandler {

	/**
	 * Process an environment item.
	 *
	 * @param TaskAction $action      The task action (Create, Update, Delete).
	 * @param string     $object_type "plugin" or "theme".
	 * @param int        $object_id   Original item ID from the sender (unused, slug is in payload).
	 * @param mixed      $payload     Decoded payload data.
	 * @return array{success: bool, message: string}
	 */
	public function handle( TaskAction $action, string $object_type, int $object_id, mixed $payload ): array {
		if ( ! is_array( $payload ) || empty( $payload['action'] ) || empty( $payload['slug'] ) ) {
			return $this->error( 'Invalid environment payload.' );
		}

		$env_action = (string) $payload['action'];
		$slug       = (string) $payload['slug'];

		return match ( $object_type ) {
			'plugin' => $this->handle_plugin( $env_action, $slug, $payload ),
			'theme'  => $this->handle_theme( $env_action, $slug, $payload ),
			default  => $this->error( sprintf( 'Unknown environment object type: %s.', $object_type ) ),
		};
	}

	/**
	 * Handle a plugin state change.
	 *
	 * @param string               $env_action activate, deactivate, install, update, or delete.
	 * @param string               $slug       Plugin basename (e.g. "woocommerce/woocommerce.php").
	 * @param array<string, mixed> $payload    Full payload data.
	 * @return array{success: bool, message: string}
	 */
	private function handle_plugin( string $env_action, string $slug, array $payload ): array {
		$name = $payload['name'] ?? $slug;

		switch ( $env_action ) {
			case 'activate':
				if ( is_plugin_active( $slug ) ) {
					return $this->success( sprintf( 'Plugin "%s" is already active.', $name ) );
				}

				// If the plugin files don't exist, try installing from WordPress.org first.
				if ( ! file_exists( WP_PLUGIN_DIR . '/' . $slug ) ) {
					$install = $this->install_plugin_from_wporg( $slug, $payload );
					if ( ! $install['success'] ) {
						return $install;
					}
				}

				$result = activate_plugin( $slug, '', false, true );
				if ( is_wp_error( $result ) ) {
					return $this->error( sprintf( 'Failed to activate plugin "%s": %s', $name, $result->get_error_message() ) );
				}

				return $this->success( sprintf( 'Plugin "%s" installed and activated.', $name ) );

			case 'deactivate':
				if ( ! is_plugin_active( $slug ) ) {
					return $this->success( sprintf( 'Plugin "%s" is already inactive.', $name ) );
				}

				deactivate_plugins( $slug, true );

				return $this->success( sprintf( 'Plugin "%s" deactivated.', $name ) );

			case 'install':
				return $this->install_plugin_from_wporg( $slug, $payload );

			case 'update':
				return $this->update_plugin_from_wporg( $slug, $payload );

			case 'delete':
				if ( is_plugin_active( $slug ) ) {
					deactivate_plugins( $slug, true );
				}

				$result = delete_plugins( array( $slug ) );
				if ( is_wp_error( $result ) ) {
					return $this->error( sprintf( 'Failed to delete plugin "%s": %s', $name, $result->get_error_message() ) );
				}

				return $this->success( sprintf( 'Plugin "%s" deleted.', $name ) );

			default:
				return $this->error( sprintf( 'Unknown plugin action: %s.', $env_action ) );
		}
	}

	/**
	 * Handle a theme state change.
	 *
	 * @param string               $env_action switch, install, update, or delete.
	 * @param string               $slug       Theme stylesheet slug.
	 * @param array<string, mixed> $payload    Full payload data.
	 * @return array{success: bool, message: string}
	 */
	private function handle_theme( string $env_action, string $slug, array $payload ): array {
		$name = $payload['name'] ?? $slug;

		switch ( $env_action ) {
			case 'switch':
				$theme = wp_get_theme( $slug );

				// If the theme doesn't exist, try installing from WordPress.org first.
				if ( ! $theme->exists() ) {
					$install = $this->install_theme_from_wporg( $slug, $payload );
					if ( ! $install['success'] ) {
						return $install;
					}
				}

				switch_theme( $slug );

				return $this->success( sprintf( 'Theme "%s" installed and activated.', $name ) );

			case 'install':
				return $this->install_theme_from_wporg( $slug, $payload );

			case 'update':
				return $this->update_theme_from_wporg( $slug, $payload );

			case 'delete':
				$active_theme = get_stylesheet();
				if ( $active_theme === $slug ) {
					return $this->error( sprintf( 'Cannot delete theme "%s" because it is the active theme.', $name ) );
				}

				$result = delete_theme( $slug );
				if ( is_wp_error( $result ) ) {
					return $this->error( sprintf( 'Failed to delete theme "%s": %s', $name, $result->get_error_message() ) );
				}

				return $this->success( sprintf( 'Theme "%s" deleted.', $name ) );

			default:
				return $this->error( sprintf( 'Unknown theme action: %s.', $env_action ) );
		}
	}

	/**
	 * Load WordPress admin files required for upgrader operations.
	 *
	 * These are not available in a REST API context by default.
	 *
	 * @return void
	 */
	private function load_upgrader_dependencies(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}

	/**
	 * Install a plugin from the WordPress.org repository.
	 *
	 * @param string               $slug    Plugin basename.
	 * @param array<string, mixed> $payload Payload data.
	 * @return array{success: bool, message: string}
	 */
	private function install_plugin_from_wporg( string $slug, array $payload ): array {
		$name    = $payload['name'] ?? $slug;
		$wp_slug = $payload['wp_slug'] ?? ( strstr( $slug, '/', true ) ?: $slug );

		$this->load_upgrader_dependencies();

		$api_result = plugins_api( 'plugin_information', array(
			'slug'   => $wp_slug,
			'fields' => array( 'sections' => false ),
		) );

		if ( is_wp_error( $api_result ) ) {
			return $this->error( sprintf(
				'Plugin "%s" is not available on WordPress.org and must be installed manually.',
				$name
			) );
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api_result->download_link );

		if ( is_wp_error( $result ) || false === $result || null === $result ) {
			$error_msg = is_wp_error( $result ) ? $result->get_error_message() : ( $skin->get_errors()->get_error_message() ?: 'Unknown error.' );
			return $this->error( sprintf( 'Failed to install plugin "%s" from WordPress.org: %s', $name, $error_msg ) );
		}

		// If the sender flagged activate_after, activate the plugin now.
		if ( ! empty( $payload['activate_after'] ) ) {
			$activate_result = activate_plugin( $slug, '', false, true );
			if ( is_wp_error( $activate_result ) ) {
				return $this->error( sprintf( 'Plugin "%s" installed but activation failed: %s', $name, $activate_result->get_error_message() ) );
			}
			return $this->success( sprintf( 'Plugin "%s" installed and activated from WordPress.org.', $name ) );
		}

		return $this->success( sprintf( 'Plugin "%s" installed from WordPress.org.', $name ) );
	}

	/**
	 * Update a plugin from the WordPress.org repository.
	 *
	 * @param string               $slug    Plugin basename.
	 * @param array<string, mixed> $payload Payload data.
	 * @return array{success: bool, message: string}
	 */
	private function update_plugin_from_wporg( string $slug, array $payload ): array {
		$name    = $payload['name'] ?? $slug;
		$wp_slug = $payload['wp_slug'] ?? ( strstr( $slug, '/', true ) ?: $slug );

		$this->load_upgrader_dependencies();

		$api_result = plugins_api( 'plugin_information', array(
			'slug'   => $wp_slug,
			'fields' => array( 'sections' => false ),
		) );

		if ( is_wp_error( $api_result ) ) {
			return $this->error( sprintf(
				'Plugin "%s" is not available on WordPress.org and must be updated manually.',
				$name
			) );
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $slug );

		if ( is_wp_error( $result ) || false === $result || null === $result ) {
			$error_msg = is_wp_error( $result ) ? $result->get_error_message() : ( $skin->get_errors()->get_error_message() ?: 'Unknown error.' );
			return $this->error( sprintf( 'Failed to update plugin "%s": %s', $name, $error_msg ) );
		}

		return $this->success( sprintf( 'Plugin "%s" updated from WordPress.org.', $name ) );
	}

	/**
	 * Install a theme from the WordPress.org repository.
	 *
	 * @param string               $slug    Theme stylesheet slug.
	 * @param array<string, mixed> $payload Payload data.
	 * @return array{success: bool, message: string}
	 */
	private function install_theme_from_wporg( string $slug, array $payload ): array {
		$name = $payload['name'] ?? $slug;

		$this->load_upgrader_dependencies();

		$api_result = themes_api( 'theme_information', array(
			'slug'   => $slug,
			'fields' => array( 'sections' => false ),
		) );

		if ( is_wp_error( $api_result ) ) {
			return $this->error( sprintf(
				'Theme "%s" is not available on WordPress.org and must be installed manually.',
				$name
			) );
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$result   = $upgrader->install( $api_result->download_link );

		if ( is_wp_error( $result ) || false === $result || null === $result ) {
			$error_msg = is_wp_error( $result ) ? $result->get_error_message() : ( $skin->get_errors()->get_error_message() ?: 'Unknown error.' );
			return $this->error( sprintf( 'Failed to install theme "%s" from WordPress.org: %s', $name, $error_msg ) );
		}

		// If the sender flagged activate_after, switch to the theme now.
		if ( ! empty( $payload['activate_after'] ) ) {
			switch_theme( $slug );
			return $this->success( sprintf( 'Theme "%s" installed and activated from WordPress.org.', $name ) );
		}

		return $this->success( sprintf( 'Theme "%s" installed from WordPress.org.', $name ) );
	}

	/**
	 * Update a theme from the WordPress.org repository.
	 *
	 * @param string               $slug    Theme stylesheet slug.
	 * @param array<string, mixed> $payload Payload data.
	 * @return array{success: bool, message: string}
	 */
	private function update_theme_from_wporg( string $slug, array $payload ): array {
		$name = $payload['name'] ?? $slug;

		$this->load_upgrader_dependencies();

		$api_result = themes_api( 'theme_information', array(
			'slug'   => $slug,
			'fields' => array( 'sections' => false ),
		) );

		if ( is_wp_error( $api_result ) ) {
			return $this->error( sprintf(
				'Theme "%s" is not available on WordPress.org and must be updated manually.',
				$name
			) );
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $slug );

		if ( is_wp_error( $result ) || false === $result || null === $result ) {
			$error_msg = is_wp_error( $result ) ? $result->get_error_message() : ( $skin->get_errors()->get_error_message() ?: 'Unknown error.' );
			return $this->error( sprintf( 'Failed to update theme "%s": %s', $name, $error_msg ) );
		}

		return $this->success( sprintf( 'Theme "%s" updated from WordPress.org.', $name ) );
	}

	/**
	 * Build a success response.
	 *
	 * @param string $message Success message.
	 * @return array{success: bool, message: string}
	 */
	private function success( string $message ): array {
		return array(
			'success' => true,
			'message' => $message,
		);
	}

	/**
	 * Build an error response.
	 *
	 * @param string $message Error message.
	 * @return array{success: bool, message: string}
	 */
	private function error( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}
}
