<?php
/**
 * Admin menu registration.
 *
 * @package TaskShunt\Admin
 */

declare(strict_types=1);

namespace TaskShunt\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DI\Container;
use TaskShunt\Admin\Pages\SettingsPage;
use TaskShunt\Admin\Pages\TasksPage;
use TaskShunt\Admin\TaskDetailPage;
use TaskShunt\Contracts\EventDispatcherInterface;
use TaskShunt\Contracts\ServerRepositoryInterface;
use TaskShunt\Contracts\TaskItemRepositoryInterface;
use TaskShunt\Contracts\TaskRepositoryInterface;
use TaskShunt\Domain\TaskStatus;
use TaskShunt\Events\TaskActivated;

/**
 * Registers the TaskShunt admin menu pages and admin bar node.
 */
final class AdminMenu {

	/**
	 * Create the admin menu.
	 *
	 * @param TaskRepositoryInterface   $task_repository   Task repository.
	 * @param ServerRepositoryInterface $server_repository Server repository.
	 * @param Container                 $container         DI container for lazy page resolution.
	 */
	public function __construct(
		private readonly TaskRepositoryInterface $task_repository,
		private readonly ServerRepositoryInterface $server_repository,
		private readonly Container $container,
	) {}

	/**
	 * Register all admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'admin_init',
			function (): void {
				$this->handle_activate_task();
			}
		);
		add_action(
			'admin_menu',
			function (): void {
				$this->register_menu_pages();
			}
		);
		add_action(
			'admin_bar_menu',
			function ( \WP_Admin_Bar $wp_admin_bar ): void {
				$this->add_admin_bar_node( $wp_admin_bar );
			},
			100
		);
		$this->register_asset_hooks();
	}

	/**
	 * Register script/style enqueue and footer hooks.
	 *
	 * @return void
	 */
	private function register_asset_hooks(): void {
		add_action(
			'admin_enqueue_scripts',
			function ( string $hook ): void {
				$this->enqueue_admin_bar_script();
				$this->container->get( SettingsPage::class )->enqueue_scripts( $hook );
			}
		);
		add_action(
			'admin_footer',
			static function (): void {
				self::render_confirm_modal();
			}
		);
	}

	/**
	 * Render the global confirm modal HTML.
	 *
	 * @return void
	 */
	private static function render_confirm_modal(): void {
		echo '<div class="taskshunt-modal-overlay" id="taskshunt-modal-overlay">'
			. '<div class="taskshunt-modal">'
			. '<strong id="taskshunt-modal-title"></strong>'
			. '<p id="taskshunt-modal-message"></p>'
			. '<div id="taskshunt-modal-prompt" style="display:none;margin:12px 0 0;">'
			. '<input type="text" id="taskshunt-modal-input" class="regular-text" style="width:100%;" maxlength="200">'
			. '</div>'
			. '<div id="taskshunt-modal-preview" class="taskshunt-modal-preview"></div>'
			. '<div class="taskshunt-modal-actions">'
			. '<button type="button" class="button" id="taskshunt-modal-cancel">' . esc_html__( 'Cancel', 'taskshunt' ) . '</button>'
			. '<button type="button" class="button button-primary" id="taskshunt-modal-ok"></button>'
			. '</div></div></div>';
	}

	/**
	 * Register the top-level menu and submenus.
	 *
	 * @return void
	 */
	private function register_menu_pages(): void {
		$icon_svg = file_get_contents( TASKSHUNT_PLUGIN_DIR . 'assets/img/icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$icon_uri = 'data:image/svg+xml;base64,' . base64_encode( $icon_svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$hook = add_menu_page(
			__( 'TaskShunt', 'taskshunt' ),
			__( 'TaskShunt', 'taskshunt' ),
			'manage_options',
			'taskshunt',
			function (): void {
				$this->render_tasks_router();
			},
			$icon_uri,
			80
		);

		add_action(
			"load-$hook",
			function (): void {
				$this->container->get( TasksPage::class )->handle_post();
			}
		);

		$this->add_tasks_submenu();
		$this->add_settings_submenu();
	}

	/**
	 * Register the Tasks submenu page.
	 *
	 * @return void
	 */
	private function add_tasks_submenu(): void {
		add_submenu_page(
			'taskshunt',
			__( 'Tasks', 'taskshunt' ),
			__( 'Tasks', 'taskshunt' ),
			'manage_options',
			'taskshunt'
		);
	}

	/**
	 * Route the main tasks page to the list or detail view.
	 *
	 * Routes to TaskDetailPage when action=view and task_id are present,
	 * otherwise falls back to the TasksPage list.
	 *
	 * @return void
	 */
	private function render_tasks_router(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$task_id = isset( $_GET['task_id'] ) ? (int) $_GET['task_id'] : 0;

		if ( 'view' === $action && $task_id > 0 ) {
			$this->container->get( TaskDetailPage::class )->render( $task_id );
			return;
		}

		$this->container->get( TasksPage::class )->render();
	}

	/**
	 * Register the Settings submenu page.
	 *
	 * @return void
	 */
	private function add_settings_submenu(): void {
		add_submenu_page(
			'taskshunt',
			__( 'Settings', 'taskshunt' ),
			__( 'Settings', 'taskshunt' ),
			'manage_options',
			'taskshunt-settings',
			function (): void {
				$this->container->get( SettingsPage::class )->render();
			}
		);
	}

	/**
	 * Enqueue the admin bar task-switcher script on every admin page.
	 *
	 * @return void
	 */
	private function enqueue_admin_bar_script(): void {
		wp_enqueue_style( 'taskshunt-admin', TASKSHUNT_PLUGIN_URL . 'assets/css/taskshunt-admin.css', array(), TASKSHUNT_VERSION );

		wp_enqueue_script( 'taskshunt-admin', TASKSHUNT_PLUGIN_URL . 'assets/dist/admin.js', array(), TASKSHUNT_VERSION, true );
		wp_enqueue_script( 'taskshunt-modal', TASKSHUNT_PLUGIN_URL . 'assets/dist/modal.js', array(), TASKSHUNT_VERSION, true );
		wp_localize_script(
			'taskshunt-modal',
			'taskshuntModal',
			array(
				'actionLabels'   => array(
					'create' => __( 'Create', 'taskshunt' ),
					'update' => __( 'Update', 'taskshunt' ),
					'delete' => __( 'Delete', 'taskshunt' ),
				),
				'typeLabels'     => array(
					'content'     => __( 'Content', 'taskshunt' ),
					'file'        => __( 'File', 'taskshunt' ),
					'environment' => __( 'Plugin/Theme', 'taskshunt' ),
					'database'    => __( 'Database', 'taskshunt' ),
				),
				'loadingPreview' => __( 'Loading preview…', 'taskshunt' ),
			)
		);

		wp_enqueue_script( 'taskshunt-admin-bar', TASKSHUNT_PLUGIN_URL . 'assets/dist/admin-bar.js', array( 'taskshunt-modal' ), TASKSHUNT_VERSION, true );
		wp_localize_script( 'taskshunt-admin-bar', 'taskshuntAdminBar', $this->get_admin_bar_data() );
	}

	/**
	 * Return the localized data array for the admin bar script.
	 *
	 * @return array<string, mixed>
	 */
	private function get_admin_bar_data(): array {
		return array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'taskshunt_activate_task' ),
			'allTasksUrl'    => admin_url( 'admin.php?page=taskshunt' ),
			'allTasksLabel'  => __( 'All tasks', 'taskshunt' ),
			'pushLabel'      => __( 'Push now', 'taskshunt' ),
			'noServerLabel'  => __( 'Configure server to push', 'taskshunt' ),
			'settingsUrl'    => admin_url( 'admin.php?page=taskshunt-settings' ),
			'hasServer'      => null !== $this->server_repository->find(),
			'discardLabel'   => __( 'Discard task', 'taskshunt' ),
			'discardConfirm' => __( 'Discard this task?', 'taskshunt' ),
			'discardMessage' => __( 'This will permanently delete this task and all its tracked changes. This action cannot be undone.', 'taskshunt' ),
			'pushConfirm'    => __( 'Push this task to production?', 'taskshunt' ),
			'pushMessage'    => __( 'All tracked changes in this task will be sent to your production site and applied automatically.', 'taskshunt' ),
			'pushingLabel'   => __( 'Pushing…', 'taskshunt' ),
			'pushedLabel'    => __( 'Pushed!', 'taskshunt' ),
			'noActiveLabel'  => __( 'No active task', 'taskshunt' ),
			'activeTaskId'   => $this->task_repository->get_active_task_id() ?? 0,
			'newTaskLabel'   => __( '+ New task', 'taskshunt' ),
			'newTaskPrompt'  => __( 'Task name:', 'taskshunt' ),
			'creatingLabel'  => __( 'Creating…', 'taskshunt' ),
			/* translators: %d: number of additional changes not shown in admin bar */
			'moreLabel'      => __( '+ %d more…', 'taskshunt' ),
		);
	}

	/**
	 * Handle the taskshunt_action=activate GET request.
	 *
	 * Processes task activation from admin bar links and list table row actions,
	 * then redirects back to the referring page.
	 *
	 * @return void
	 */
	private function handle_activate_task(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['taskshunt_action'] ) ? sanitize_key( $_GET['taskshunt_action'] ) : '';
		if ( 'activate' !== $action ) {
			return;
		}

		check_admin_referer( 'taskshunt_task_action' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'taskshunt' ) );
		}

		$task_id = isset( $_GET['task_id'] ) ? (int) $_GET['task_id'] : 0;
		$task    = $task_id > 0 ? $this->task_repository->find_by_id( $task_id ) : null;

		if ( null !== $task && TaskStatus::Pending === $task->status ) {
			$this->task_repository->clear_active();
			$this->task_repository->set_active( $task_id );

			$this->container->get( EventDispatcherInterface::class )->dispatch( new TaskActivated( $task ) );
			Notices::add(
				'success',
				sprintf(
				/* translators: %s: task title */
					__( 'Task "%s" is now active.', 'taskshunt' ),
					$task->title
				) 
			);
		}

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? remove_query_arg( array( 'taskshunt_action', 'task_id', '_wpnonce' ), $referer ) : admin_url( 'admin.php?page=taskshunt' ) );
		exit;
	}

	/**
	 * Add the TaskShunt node and task-switcher children to the WP admin bar.
	 *
	 * Structure:
	 *  - Active task items (recent changes)
	 *  - Push button (links to push form)
	 *  - View all changes link
	 *  - Separator
	 *  - Switch to: other pending tasks
	 *  - All tasks link
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WordPress admin bar instance.
	 * @return void
	 */
	private function add_admin_bar_node( \WP_Admin_Bar $wp_admin_bar ): void {
		$active_task    = $this->task_repository->find_active();
		$active_task_id = null !== $active_task ? $active_task->id : 0;

		$wp_admin_bar->add_node(
			array(
				'id'    => 'taskshunt',
				'title' => $this->get_admin_bar_title( $active_task ),
				'href'  => admin_url( 'admin.php?page=taskshunt' ),
			)
		);

		if ( null !== $active_task ) {
			$this->add_active_task_nodes( $wp_admin_bar, $active_task );
		}

		$this->add_switch_task_nodes( $wp_admin_bar, $active_task_id );

		$wp_admin_bar->add_node(
			array(
				'parent' => 'taskshunt',
				'id'     => 'taskshunt-new-task',
				'title'  => esc_html__( '+ New task', 'taskshunt' ),
				'href'   => '#',
				'meta'   => array( 'class' => 'taskshunt-ab-new-task' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'parent' => 'taskshunt',
				'id'     => 'taskshunt-all-tasks',
				'title'  => esc_html__( 'All tasks', 'taskshunt' ),
				'href'   => admin_url( 'admin.php?page=taskshunt' ),
			)
		);
	}

	/**
	 * Add nodes for the active task: recent items, push link, and view link.
	 *
	 * @param \WP_Admin_Bar        $wp_admin_bar WordPress admin bar instance.
	 * @param \TaskShunt\Domain\Task $task         The active task.
	 * @return void
	 */
	private function add_active_task_nodes( \WP_Admin_Bar $wp_admin_bar, \TaskShunt\Domain\Task $task ): void {
		$this->add_item_nodes( $wp_admin_bar, $task );
		$this->add_push_node( $wp_admin_bar, $task );
		$this->add_discard_and_separator( $wp_admin_bar );
	}

	/**
	 * Add item preview nodes for the active task.
	 *
	 * @param \WP_Admin_Bar        $wp_admin_bar WordPress admin bar instance.
	 * @param \TaskShunt\Domain\Task $task         The active task.
	 * @return void
	 */
	private function add_item_nodes( \WP_Admin_Bar $wp_admin_bar, \TaskShunt\Domain\Task $task ): void {
		$items    = $this->container->get( TaskItemRepositoryInterface::class )->find_by_task( $task->id );
		$shown    = array_slice( $items, 0, 5 );
		$task_url = admin_url( 'admin.php?page=taskshunt&action=view&task_id=' . $task->id );

		foreach ( $shown as $item ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'taskshunt',
					'id'     => 'taskshunt-item-' . $item->id,
					'title'  => $this->format_item_label( $item ),
					'href'   => $task_url,
					'meta'   => array( 'class' => 'taskshunt-ab-item' ),
				)
			);
		}

		if ( count( $items ) > 5 ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'taskshunt',
					'id'     => 'taskshunt-items-more',
					'title'  => sprintf(
						/* translators: %d: number of additional changes not shown */
						esc_html__( '+ %d more…', 'taskshunt' ),
						count( $items ) - 5
					),
					'href'   => $task_url,
					'meta'   => array( 'class' => 'taskshunt-ab-item' ),
				)
			);
		}
	}

	/**
	 * Add the push or configure-server node.
	 *
	 * @param \WP_Admin_Bar        $wp_admin_bar WordPress admin bar instance.
	 * @param \TaskShunt\Domain\Task $task         The active task.
	 * @return void
	 */
	private function add_push_node( \WP_Admin_Bar $wp_admin_bar, \TaskShunt\Domain\Task $task ): void {
		if ( 0 === $task->item_count ) {
			return;
		}

		$server = $this->server_repository->find();

		if ( null !== $server ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'taskshunt',
					'id'     => 'taskshunt-push',
					'title'  => esc_html__( 'Push now', 'taskshunt' ),
					'href'   => '#',
					'meta'   => array( 'class' => 'taskshunt-ab-push' ),
				)
			);
		} else {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'taskshunt',
					'id'     => 'taskshunt-push',
					'title'  => esc_html__( 'Configure server to push', 'taskshunt' ),
					'href'   => admin_url( 'admin.php?page=taskshunt-settings' ),
					'meta'   => array( 'class' => 'taskshunt-ab-no-server' ),
				)
			);
		}
	}

	/**
	 * Add the discard node and visual separator.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WordPress admin bar instance.
	 * @return void
	 */
	private function add_discard_and_separator( \WP_Admin_Bar $wp_admin_bar ): void {
		$wp_admin_bar->add_node(
			array(
				'parent' => 'taskshunt',
				'id'     => 'taskshunt-discard',
				'title'  => esc_html__( 'Discard task', 'taskshunt' ),
				'href'   => '#',
				'meta'   => array( 'class' => 'taskshunt-ab-discard' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'parent' => 'taskshunt',
				'id'     => 'taskshunt-separator',
				'title'  => '',
				'meta'   => array( 'class' => 'taskshunt-ab-separator' ),
			)
		);
	}

	/**
	 * Format a task item into a compact label for the admin bar.
	 *
	 * @param \TaskShunt\Domain\TaskItem $item Task item.
	 * @return string HTML label.
	 */
	private function format_item_label( \TaskShunt\Domain\TaskItem $item ): string {
		$action_colors = array(
			'create' => '#39594d',
			'update' => '#ff7759',
			'delete' => '#b20000',
		);
		$color         = $action_colors[ $item->action->value ] ?? '#a0a5aa';

		$icon = match ( $item->action->value ) {
			'create' => '+',
			'update' => '~',
			'delete' => '−',
			default  => '•',
		};

		$name = $item->object_type . ' #' . $item->object_id;
		if ( \TaskShunt\Domain\TaskItemType::File === $item->type ) {
			$name = basename( $item->object_id );
		} elseif ( \TaskShunt\Domain\TaskItemType::Content === $item->type ) {
			$post_title = get_the_title( (int) $item->object_id );
			if ( '' !== $post_title ) {
				$name = $post_title;
			}
		} elseif ( \TaskShunt\Domain\TaskItemType::Environment === $item->type ) {
			$item_payload = json_decode( $item->payload, true );
			$name         = ( $item_payload['name'] ?? $item->object_id ) . ' (' . $item->object_type . ')';
		}

		return sprintf(
			'<span style="color:%s;font-weight:700;margin-right:4px;">%s</span>%s',
			esc_attr( $color ),
			esc_html( $icon ),
			esc_html( mb_strimwidth( $name, 0, 35, '…' ) )
		);
	}

	/**
	 * Add "Switch to" task nodes for other pending tasks.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar   WordPress admin bar instance.
	 * @param int           $active_task_id Currently active task ID (0 if none).
	 * @return void
	 */
	private function add_switch_task_nodes( \WP_Admin_Bar $wp_admin_bar, int $active_task_id ): void {
		$tasks = $this->task_repository->find_all();

		foreach ( $tasks as $task ) {
			if ( TaskStatus::Pending !== $task->status || $task->id === $active_task_id ) {
				continue;
			}

			$activate_url = wp_nonce_url(
				add_query_arg(
					array(
						'taskshunt_action' => 'activate',
						'task_id'        => $task->id,
					),
					admin_url( 'admin.php?page=taskshunt' )
				),
				'taskshunt_task_action'
			);

			$wp_admin_bar->add_node(
				array(
					'parent' => 'taskshunt',
					'id'     => 'taskshunt-task-' . $task->id,
					'title'  => esc_html( $task->title ),
					'href'   => $activate_url,
				)
			);
		}
	}

	/**
	 * Build the admin bar node title HTML.
	 *
	 * Shows "No active task" in muted gray when idle, or the task title
	 * and item count in green when a task is active.
	 *
	 * @param \TaskShunt\Domain\Task|null $task The active task, or null.
	 * @return string HTML string (dynamic parts are escaped).
	 */
	private function get_admin_bar_title( ?\TaskShunt\Domain\Task $task ): string {
		if ( null === $task ) {
			return '<span style="color:#9e9e9e;">' . esc_html__( 'No active task', 'taskshunt' ) . '</span>';
		}

		$label = esc_html( $task->title )
			. ' &middot; '
			. esc_html( (string) $task->item_count )
			. ' '
			. esc_html__( 'changes', 'taskshunt' );

		return '<span style="color:#ff7759;">' . $label . '</span>';
	}
}
