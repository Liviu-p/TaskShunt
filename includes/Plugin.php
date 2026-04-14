<?php
/**
 * Main plugin class.
 *
 * @package Stagify
 */

declare(strict_types=1);

namespace Stagify;

use DI\Container;
use Stagify\Admin\Actions\DiscardTaskAction;
use Stagify\Admin\Actions\PushTaskAction;
use Stagify\Admin\Actions\RetryTaskAction;
use Stagify\Admin\Actions\SaveModeAction;
use Stagify\Admin\Actions\SaveServerAction;
use Stagify\Admin\Actions\SaveTrackingAction;
use Stagify\Admin\Ajax\ActivateTaskAction;
use Stagify\Admin\Ajax\DiscardTaskAction as AjaxDiscardTaskAction;
use Stagify\Admin\Ajax\PushTaskAction as AjaxPushTaskAction;
use Stagify\Admin\Ajax\TestConnectionAction;
use Stagify\Admin\AdminMenu;
use Stagify\Admin\Notices;
use Stagify\Admin\Pages\ReceiverSettingsPage;
use Stagify\Admin\Pages\SetupPage;
use Stagify\Api\ReceiverApi;
use Stagify\Domain\PluginMode;

/**
 * Plugin singleton that bootstraps the application.
 */
final class Plugin {

	/**
	 * Plugin singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Create the plugin instance.
	 *
	 * @param string    $version    Plugin version.
	 * @param string    $plugin_dir Absolute path to the plugin directory.
	 * @param string    $plugin_url URL to the plugin directory.
	 * @param Container $container  DI container.
	 */
	private function __construct(
		public readonly string $version,
		public readonly string $plugin_dir,
		public readonly string $plugin_url,
		private readonly Container $container,
	) {}

	/**
	 * Get or create the plugin singleton.
	 *
	 * @param Container $container DI container.
	 * @return self
	 */
	public static function get_instance( Container $container ): self {
		return self::$instance ??= new self(
			version: STAGIFY_VERSION,
			plugin_dir: STAGIFY_PLUGIN_DIR,
			plugin_url: STAGIFY_PLUGIN_URL,
			container: $container,
		);
	}

	/**
	 * Bootstrap the plugin.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->register_mode_action();

		$mode = SetupPage::get_mode();

		if ( is_admin() && null === $mode ) {
			$this->boot_setup();
			return;
		}

		if ( PluginMode::Sender === $mode ) {
			$this->boot_sender();
		}

		if ( PluginMode::Receiver === $mode ) {
			$this->boot_receiver();
		}
	}

	/**
	 * Boot the first-run setup screen when no mode is selected.
	 *
	 * @return void
	 */
	private function boot_setup(): void {
		$setup = $this->container->get( SetupPage::class );
		$setup->register();
		$setup->maybe_redirect();
	}

	/**
	 * Boot the sender (staging) feature set.
	 *
	 * @return void
	 */
	private function boot_sender(): void {
		$this->container->get( HookManager::class )->register();
		if ( is_admin() ) {
			$this->container->get( AdminMenu::class )->register();
			Notices::register();
			$this->register_sender_actions();
		}
	}

	/**
	 * Boot the receiver (production) feature set.
	 *
	 * @return void
	 */
	private function boot_receiver(): void {
		$this->container->get( ReceiverApi::class )->register();

		if ( is_admin() ) {
			$this->container->get( ReceiverSettingsPage::class )->register();

			// Allow the setup page to render so the user can switch modes.
			$setup = $this->container->get( SetupPage::class );
			$setup->register();
		}
	}

	/**
	 * Register the save-mode admin_post action (always available).
	 *
	 * @return void
	 */
	private function register_mode_action(): void {
		add_action(
			'admin_post_stagify_save_mode',
			function (): void {
				$this->container->get( SaveModeAction::class )->handle();
			}
		);
	}

	/**
	 * Register admin_post and AJAX handlers for sender mode.
	 *
	 * @return void
	 */
	private function register_sender_actions(): void {
		$this->register_task_actions();
		$this->register_server_actions();
	}

	/**
	 * Register task-related admin_post handlers.
	 *
	 * @return void
	 */
	private function register_task_actions(): void {
		add_action(
			'admin_post_stagify_discard_task',
			function (): void {
				$this->container->get( DiscardTaskAction::class )->handle();
			}
		);
		add_action(
			'admin_post_stagify_push_task',
			function (): void {
				$this->container->get( PushTaskAction::class )->handle();
			}
		);
		add_action(
			'admin_post_stagify_retry_task',
			function (): void {
				$this->container->get( RetryTaskAction::class )->handle();
			}
		);
	}

	/**
	 * Register server-related admin_post and AJAX handlers.
	 *
	 * @return void
	 */
	private function register_server_actions(): void {
		add_action(
			'admin_post_stagify_save_server',
			function (): void {
				$this->container->get( SaveServerAction::class )->handle();
			}
		);
		add_action(
			'admin_post_stagify_delete_server',
			function (): void {
				check_admin_referer( 'stagify_delete_server' );

				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have permission to perform this action.', 'stagify' ) );
				}

				$server_id = isset( $_GET['task_id'] ) ? (int) $_GET['task_id'] : 0;
				if ( $server_id > 0 ) {
					$this->container->get( \Stagify\Contracts\ServerRepositoryInterface::class )->delete( $server_id );
				}

				wp_safe_redirect( admin_url( 'admin.php?page=stagify-settings' ) );
				exit;
			}
		);
		add_action(
			'admin_post_stagify_save_tracking',
			function (): void {
				$this->container->get( SaveTrackingAction::class )->handle();
			}
		);
		add_action(
			'wp_ajax_stagify_test_connection',
			function (): void {
				$this->container->get( TestConnectionAction::class )->handle();
			}
		);
		add_action(
			'wp_ajax_stagify_activate_task',
			function (): void {
				$this->container->get( ActivateTaskAction::class )->handle();
			}
		);
		add_action(
			'wp_ajax_stagify_discard_task_ajax',
			function (): void {
				$this->container->get( AjaxDiscardTaskAction::class )->handle();
			}
		);
		add_action(
			'wp_ajax_stagify_push_task_ajax',
			function (): void {
				$this->container->get( AjaxPushTaskAction::class )->handle();
			}
		);
	}
}
