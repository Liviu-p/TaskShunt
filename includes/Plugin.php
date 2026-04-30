<?php
/**
 * Main plugin class.
 *
 * @package TaskShunt
 */

declare(strict_types=1);

namespace TaskShunt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DI\Container;
use TaskShunt\Admin\Actions\DiscardTaskAction;
use TaskShunt\Admin\Actions\PushTaskAction;
use TaskShunt\Admin\Actions\RetryTaskAction;
use TaskShunt\Admin\Actions\SaveModeAction;
use TaskShunt\Admin\Actions\SaveServerAction;
use TaskShunt\Admin\Actions\SaveCleanupAction;
use TaskShunt\Admin\Actions\SaveTrackingAction;
use TaskShunt\Admin\Ajax\ActivateTaskAction;
use TaskShunt\Admin\Ajax\CreateTaskAction as AjaxCreateTaskAction;
use TaskShunt\Admin\Ajax\DiscardTaskAction as AjaxDiscardTaskAction;
use TaskShunt\Admin\Ajax\PreviewTaskAction;
use TaskShunt\Admin\Ajax\PushTaskAction as AjaxPushTaskAction;
use TaskShunt\Admin\Ajax\RenameTaskAction;
use TaskShunt\Admin\Ajax\TestConnectionAction;
use TaskShunt\Admin\AdminMenu;
use TaskShunt\Admin\DashboardWidget;
use TaskShunt\Admin\Notices;
use TaskShunt\Admin\Pages\ReceiverSettingsPage;
use TaskShunt\Admin\Pages\SetupPage;
use TaskShunt\Api\ReceiverApi;
use TaskShunt\Domain\PluginMode;

/**
 * Plugin singleton that bootstraps the application.
 *
 * TaskShunt operates in one of two modes:
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
			version: TASKSHUNT_VERSION,
			plugin_dir: TASKSHUNT_PLUGIN_DIR,
			plugin_url: TASKSHUNT_PLUGIN_URL,
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
	 * AdminMenu  — registers the TaskShunt menu pages (Tasks, Settings) and admin bar widget.
	 * Actions    — form handlers for push, discard, retry, save server, etc.
	 *
	 * @return void
	 */
	private function boot_sender(): void {
		$this->container->get( HookManager::class )->register();
		if ( is_admin() ) {
			$this->container->get( AdminMenu::class )->register();
			$this->container->get( DashboardWidget::class )->register();
			Notices::register();
			$this->register_sender_actions();
		}
	}

	/**
	 * Boot the receiver (production) feature set.
	 *
	 * ReceiverApi          — registers the REST endpoint (POST /taskshunt/v1/receive) that accepts
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
			'admin_post_taskshunt_save_mode',
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
			'admin_post_taskshunt_discard_task',
			function (): void {
				$this->container->get( DiscardTaskAction::class )->handle();
			}
		);
		add_action(
			'admin_post_taskshunt_push_task',
			function (): void {
				$this->container->get( PushTaskAction::class )->handle();
			}
		);
		add_action(
			'admin_post_taskshunt_retry_task',
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
			'admin_post_taskshunt_save_server',
			function (): void {
				$this->container->get( SaveServerAction::class )->handle();
			}
		);
		add_action(
			'admin_post_taskshunt_delete_server',
			function (): void {
				$this->container->get( \TaskShunt\Admin\Actions\DeleteServerAction::class )->handle();
			}
		);
		add_action(
			'admin_post_taskshunt_save_tracking',
			function (): void {
				$this->container->get( SaveTrackingAction::class )->handle();
			}
		);
		add_action(
			'admin_post_taskshunt_save_cleanup',
			function (): void {
				$this->container->get( SaveCleanupAction::class )->handle();
			}
		);
		add_action(
			'wp_ajax_taskshunt_test_connection',
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
		$this->register_task_ajax_actions();
		$this->register_utility_ajax_actions();
	}

	/**
	 * Register AJAX handlers for task CRUD operations.
	 *
	 * @return void
	 */
	private function register_task_ajax_actions(): void {
		add_action(
			'wp_ajax_taskshunt_activate_task',
			function (): void {
				$this->container->get( ActivateTaskAction::class )->handle();
			}
		);
		add_action(
			'wp_ajax_taskshunt_create_task',
			function (): void {
				$this->container->get( AjaxCreateTaskAction::class )->handle();
			}
		);
		add_action(
			'wp_ajax_taskshunt_discard_task_ajax',
			function (): void {
				$this->container->get( AjaxDiscardTaskAction::class )->handle();
			}
		);
	}

	/**
	 * Register AJAX handlers for push, preview, and rename.
	 *
	 * @return void
	 */
	private function register_utility_ajax_actions(): void {
		add_action(
			'wp_ajax_taskshunt_push_task_ajax',
			function (): void {
				$this->container->get( AjaxPushTaskAction::class )->handle();
			}
		);
		add_action(
			'wp_ajax_taskshunt_preview_task',
			function (): void {
				$this->container->get( PreviewTaskAction::class )->handle();
			}
		);
		add_action(
			'wp_ajax_taskshunt_rename_task',
			function (): void {
				$this->container->get( RenameTaskAction::class )->handle();
			}
		);
	}
}
