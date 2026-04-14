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
 *
 * Stagify operates in one of two modes:
 *  - Sender (staging site): tracks content/file/plugin/theme changes, groups them
 *    into "tasks", and pushes the task payload to a production site via HTTP.
 *  - Receiver (production site): exposes a REST API that accepts incoming tasks
 *    and applies the changes (create/update/delete posts, install plugins, etc.).
 *
 * On first activation, neither mode is set and the user sees a setup screen.
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
		// The mode-switch action must always be available so users can change modes.
		$this->register_mode_action();

		$mode = SetupPage::get_mode();

		// No mode selected yet — show the first-run setup screen.
		if ( is_admin() && null === $mode ) {
			$this->boot_setup();
			return;
		}

		// Sender mode: enable change tracking (HookManager), admin UI, and push actions.
		if ( PluginMode::Sender === $mode ) {
			$this->boot_sender();
		}

		// Receiver mode: register the REST API endpoint that accepts pushes.
		if ( PluginMode::Receiver === $mode ) {
			$this->boot_receiver();
		}
	}

	/**
	 * Boot the first-run setup screen when no mode is selected.
	 * Shows a full-page "Sender or Receiver?" choice, and redirects admins there until they pick one.
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
	 * HookManager — listens to WordPress events (post saves, plugin activations, etc.)
	 *               and automatically records every change into the active task.
	 * AdminMenu  — registers the Stagify menu pages (Tasks, Settings) and admin bar widget.
	 * Actions    — form handlers for push, discard, retry, save server, etc.
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
	 * ReceiverApi          — registers the REST endpoint (POST /stagify/v1/receive) that accepts
	 *                        incoming pushes and applies changes to this site.
	 * ReceiverSettingsPage — admin page to manage the API key and view receiver status.
	 * SetupPage            — kept available so the user can switch back to sender mode.
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
	 * Register all admin_post and AJAX handlers for sender mode.
	 *
	 * Admin_post handlers process traditional form submissions (POST → redirect).
	 * AJAX handlers process JavaScript-driven requests from the admin bar and task pages.
	 *
	 * @return void
	 */
	private function register_sender_actions(): void {
		$this->register_task_actions();
		$this->register_server_actions();
		$this->register_ajax_actions();
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
	 * Register server-related admin_post handlers.
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
				$this->container->get( \Stagify\Admin\Actions\DeleteServerAction::class )->handle();
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
	}

	/**
	 * Register AJAX handlers for task management.
	 *
	 * @return void
	 */
	private function register_ajax_actions(): void {
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
