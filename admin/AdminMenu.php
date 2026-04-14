<?php
/**
 * Admin menu registration.
 *
 * @package Stagify\Admin
 */

declare(strict_types=1);

namespace Stagify\Admin;

use DI\Container;
use Stagify\Admin\Pages\SettingsPage;
use Stagify\Admin\Pages\TasksPage;
use Stagify\Admin\TaskDetailPage;
use Stagify\Contracts\EventDispatcherInterface;
use Stagify\Contracts\ServerRepositoryInterface;
use Stagify\Contracts\TaskItemRepositoryInterface;
use Stagify\Contracts\TaskRepositoryInterface;
use Stagify\Domain\TaskStatus;
use Stagify\Events\TaskActivated;

/**
 * Registers the Stagify admin menu pages and admin bar node.
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
	 * Render the global confirm modal HTML and JS.
	 *
	 * @return void
	 */
	private static function render_confirm_modal(): void {
		self::render_modal_html();
		self::render_modal_script();
	}

	/**
	 * Output the confirm modal HTML.
	 *
	 * @return void
	 */
	private static function render_modal_html(): void {
		echo '<div class="stagify-modal-overlay" id="stagify-modal-overlay">'
			. '<div class="stagify-modal">'
			. '<strong id="stagify-modal-title"></strong>'
			. '<p id="stagify-modal-message"></p>'
			. '<div id="stagify-modal-preview" class="stagify-modal-preview"></div>'
			. '<div class="stagify-modal-actions">'
			. '<button type="button" class="button" id="stagify-modal-cancel">' . esc_html__( 'Cancel', 'stagify' ) . '</button>'
			. '<button type="button" class="button button-primary" id="stagify-modal-ok"></button>'
			. '</div></div></div>';
	}

	/**
	 * Output the confirm modal inline JS.
	 *
	 * @return void
	 */
	private static function render_modal_script(): void { // phpcs:ignore SlevomatCodingStandard.Functions.FunctionLength.FunctionLength
		$action_labels = wp_json_encode(
			array(
				'create' => __( 'Create', 'stagify' ),
				'update' => __( 'Update', 'stagify' ),
				'delete' => __( 'Delete', 'stagify' ),
			)
		);
		$type_labels   = wp_json_encode(
			array(
				'content'     => __( 'Content', 'stagify' ),
				'file'        => __( 'File', 'stagify' ),
				'environment' => __( 'Plugin/Theme', 'stagify' ),
				'database'    => __( 'Database', 'stagify' ),
			)
		);
		echo '<script>'
			. 'var stagifyActionLabels=' . $action_labels . ';' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. 'var stagifyTypeLabels=' . $type_labels . ';' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. 'window.stagifyConfirm=function(opts){'
			. 'return new Promise(function(resolve){'
			. 'var ov=document.getElementById("stagify-modal-overlay");'
			. 'var ti=document.getElementById("stagify-modal-title");'
			. 'var msg=document.getElementById("stagify-modal-message");'
			. 'var pv=document.getElementById("stagify-modal-preview");'
			. 'var ok=document.getElementById("stagify-modal-ok");'
			. 'var cn=document.getElementById("stagify-modal-cancel");'
			. 'if(!ov)return resolve(false);'
			. 'ti.textContent=opts.title||"";'
			. 'msg.textContent=opts.message||"";'
			. 'pv.innerHTML="";pv.style.display="none";'
			. 'ok.textContent=opts.confirm||"OK";'
			. 'ok.className="button button-primary"+(opts.danger?" stagify-modal-confirm--danger":"");'
			. 'ov.classList.add("stagify-modal--open");'
			// Fetch preview if taskId provided.
			. 'if(opts.previewTaskId&&window.stagifyAdminBar){'
			. 'pv.style.display="block";pv.innerHTML="<div class=\"stagify-preview-loading\">' . esc_js( __( 'Loading preview…', 'stagify' ) ) . '</div>";'
			. 'fetch(stagifyAdminBar.ajaxUrl+"?action=stagify_preview_task&task_id="+opts.previewTaskId+"&_ajax_nonce="+stagifyAdminBar.nonce)'
			. '.then(function(r){return r.json();})'
			. '.then(function(d){'
			. 'if(!d.success){pv.innerHTML="";pv.style.display="none";return;}'
			. 'var items=d.data.items;if(!items.length){pv.innerHTML="";pv.style.display="none";return;}'
			. 'var html="<div class=\"stagify-preview-list\">";'
			. 'items.forEach(function(item){'
			. 'var actionCls="stagify-action--"+item.action;'
			. 'var actionLabel=stagifyActionLabels[item.action]||item.action;'
			. 'var typeLabel=stagifyTypeLabels[item.type]||item.type;'
			. 'html+="<div class=\"stagify-preview-item\">";'
			. 'html+="<span class=\"stagify-preview-action "+actionCls+"\">"+actionLabel+"</span> ";'
			. 'html+="<strong>"+(item.title||item.object_id)+"</strong>";'
			. 'html+="<span class=\"stagify-preview-type\">"+typeLabel+"</span>";'
			. 'if(item.excerpt)html+="<p class=\"stagify-preview-excerpt\">"+item.excerpt+"</p>";'
			. 'html+="</div>";'
			. '});'
			. 'html+="</div>";'
			. 'pv.innerHTML=html;'
			. '}).catch(function(){pv.innerHTML="";pv.style.display="none";});'
			. '}'
			. 'function close(val){ov.classList.remove("stagify-modal--open");ok.onclick=null;cn.onclick=null;pv.innerHTML="";pv.style.display="none";resolve(val);}'
			. 'ok.onclick=function(){close(true);};'
			. 'cn.onclick=function(){close(false);};'
			. 'ov.addEventListener("click",function(e){if(e.target===ov)close(false);},{once:true});'
			. '});'
			. '};'
			// Links with data-confirm attributes.
			. 'document.addEventListener("click",function(e){'
			. 'var el=e.target.closest(".stagify-confirm-link");if(!el)return;e.preventDefault();'
			. 'stagifyConfirm({title:el.dataset.confirmTitle,message:el.dataset.confirmMessage,confirm:el.dataset.confirmLabel,danger:el.dataset.confirmDanger==="1"}).then(function(ok){if(ok)window.location.href=el.href;});'
			. '});'
			// Submit buttons with data-confirm attributes.
			. 'document.addEventListener("click",function(e){'
			. 'var el=e.target.closest(".stagify-confirm-submit");if(!el)return;e.preventDefault();'
			. 'stagifyConfirm({title:el.dataset.confirmTitle,message:el.dataset.confirmMessage,confirm:el.dataset.confirmLabel,danger:el.dataset.confirmDanger==="1"}).then(function(ok){if(ok)el.closest("form").submit();});'
			. '});'
			. '</script>';
	}

	/**
	 * Register the top-level menu and submenus.
	 *
	 * @return void
	 */
	private function register_menu_pages(): void {
		$icon_svg = file_get_contents( STAGIFY_PLUGIN_DIR . 'assets/img/icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$icon_uri = 'data:image/svg+xml;base64,' . base64_encode( $icon_svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$hook = add_menu_page(
			__( 'Stagify', 'stagify' ),
			__( 'Stagify', 'stagify' ),
			'manage_options',
			'stagify',
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
			'stagify',
			__( 'Tasks', 'stagify' ),
			__( 'Tasks', 'stagify' ),
			'manage_options',
			'stagify'
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
			'stagify',
			__( 'Settings', 'stagify' ),
			__( 'Settings', 'stagify' ),
			'manage_options',
			'stagify-settings',
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
		wp_enqueue_style( 'stagify-admin', STAGIFY_PLUGIN_URL . 'assets/css/stagify-admin.css', array(), STAGIFY_VERSION );
		wp_enqueue_script( 'stagify-admin-bar', STAGIFY_PLUGIN_URL . 'assets/dist/admin-bar.js', array(), STAGIFY_VERSION, true );
		wp_localize_script( 'stagify-admin-bar', 'stagifyAdminBar', $this->get_admin_bar_data() );
	}

	/**
	 * Return the localized data array for the admin bar script.
	 *
	 * @return array<string, mixed>
	 */
	private function get_admin_bar_data(): array {
		return array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'stagify_activate_task' ),
			'allTasksUrl'    => admin_url( 'admin.php?page=stagify' ),
			'allTasksLabel'  => __( 'All tasks', 'stagify' ),
			'pushLabel'      => __( 'Push now', 'stagify' ),
			'noServerLabel'  => __( 'Configure server to push', 'stagify' ),
			'settingsUrl'    => admin_url( 'admin.php?page=stagify-settings' ),
			'hasServer'      => null !== $this->server_repository->find(),
			'discardLabel'   => __( 'Discard task', 'stagify' ),
			'discardConfirm' => __( 'Discard this task?', 'stagify' ),
			'discardMessage' => __( 'This will permanently delete this task and all its tracked changes. This action cannot be undone.', 'stagify' ),
			'pushConfirm'    => __( 'Push this task to production?', 'stagify' ),
			'pushMessage'    => __( 'All tracked changes in this task will be sent to your production site and applied automatically.', 'stagify' ),
			'pushingLabel'   => __( 'Pushing…', 'stagify' ),
			'pushedLabel'    => __( 'Pushed!', 'stagify' ),
			'noActiveLabel'  => __( 'No active task', 'stagify' ),
			'activeTaskId'   => $this->task_repository->get_active_task_id() ?? 0,
			/* translators: %d: number of additional changes not shown in admin bar */
			'moreLabel'      => __( '+ %d more…', 'stagify' ),
		);
	}

	/**
	 * Handle the stagify_action=activate GET request.
	 *
	 * Processes task activation from admin bar links and list table row actions,
	 * then redirects back to the referring page.
	 *
	 * @return void
	 */
	private function handle_activate_task(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['stagify_action'] ) ? sanitize_key( $_GET['stagify_action'] ) : '';
		if ( 'activate' !== $action ) {
			return;
		}

		check_admin_referer( 'stagify_task_action' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stagify' ) );
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
					__( 'Task "%s" is now active.', 'stagify' ),
					$task->title
				) 
			);
		}

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? remove_query_arg( array( 'stagify_action', 'task_id', '_wpnonce' ), $referer ) : admin_url( 'admin.php?page=stagify' ) );
		exit;
	}

	/**
	 * Add the Stagify node and task-switcher children to the WP admin bar.
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
				'id'    => 'stagify',
				'title' => $this->get_admin_bar_title( $active_task ),
				'href'  => admin_url( 'admin.php?page=stagify' ),
			)
		);

		if ( null !== $active_task ) {
			$this->add_active_task_nodes( $wp_admin_bar, $active_task );
		}

		$this->add_switch_task_nodes( $wp_admin_bar, $active_task_id );

		$wp_admin_bar->add_node(
			array(
				'parent' => 'stagify',
				'id'     => 'stagify-all-tasks',
				'title'  => esc_html__( 'All tasks', 'stagify' ),
				'href'   => admin_url( 'admin.php?page=stagify' ),
			)
		);
	}

	/**
	 * Add nodes for the active task: recent items, push link, and view link.
	 *
	 * @param \WP_Admin_Bar        $wp_admin_bar WordPress admin bar instance.
	 * @param \Stagify\Domain\Task $task         The active task.
	 * @return void
	 */
	private function add_active_task_nodes( \WP_Admin_Bar $wp_admin_bar, \Stagify\Domain\Task $task ): void {
		$this->add_item_nodes( $wp_admin_bar, $task );
		$this->add_push_node( $wp_admin_bar, $task );
		$this->add_discard_and_separator( $wp_admin_bar );
	}

	/**
	 * Add item preview nodes for the active task.
	 *
	 * @param \WP_Admin_Bar        $wp_admin_bar WordPress admin bar instance.
	 * @param \Stagify\Domain\Task $task         The active task.
	 * @return void
	 */
	private function add_item_nodes( \WP_Admin_Bar $wp_admin_bar, \Stagify\Domain\Task $task ): void {
		$items    = $this->container->get( TaskItemRepositoryInterface::class )->find_by_task( $task->id );
		$shown    = array_slice( $items, 0, 5 );
		$task_url = admin_url( 'admin.php?page=stagify&action=view&task_id=' . $task->id );

		foreach ( $shown as $item ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'stagify',
					'id'     => 'stagify-item-' . $item->id,
					'title'  => $this->format_item_label( $item ),
					'href'   => $task_url,
					'meta'   => array( 'class' => 'stagify-ab-item' ),
				)
			);
		}

		if ( count( $items ) > 5 ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'stagify',
					'id'     => 'stagify-items-more',
					'title'  => sprintf(
						/* translators: %d: number of additional changes not shown */
						esc_html__( '+ %d more…', 'stagify' ),
						count( $items ) - 5
					),
					'href'   => $task_url,
					'meta'   => array( 'class' => 'stagify-ab-item' ),
				)
			);
		}
	}

	/**
	 * Add the push or configure-server node.
	 *
	 * @param \WP_Admin_Bar        $wp_admin_bar WordPress admin bar instance.
	 * @param \Stagify\Domain\Task $task         The active task.
	 * @return void
	 */
	private function add_push_node( \WP_Admin_Bar $wp_admin_bar, \Stagify\Domain\Task $task ): void {
		if ( 0 === $task->item_count ) {
			return;
		}

		$server = $this->server_repository->find();

		if ( null !== $server ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'stagify',
					'id'     => 'stagify-push',
					'title'  => esc_html__( 'Push now', 'stagify' ),
					'href'   => '#',
					'meta'   => array( 'class' => 'stagify-ab-push' ),
				)
			);
		} else {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'stagify',
					'id'     => 'stagify-push',
					'title'  => esc_html__( 'Configure server to push', 'stagify' ),
					'href'   => admin_url( 'admin.php?page=stagify-settings' ),
					'meta'   => array( 'class' => 'stagify-ab-no-server' ),
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
				'parent' => 'stagify',
				'id'     => 'stagify-discard',
				'title'  => esc_html__( 'Discard task', 'stagify' ),
				'href'   => '#',
				'meta'   => array( 'class' => 'stagify-ab-discard' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'parent' => 'stagify',
				'id'     => 'stagify-separator',
				'title'  => '',
				'meta'   => array( 'class' => 'stagify-ab-separator' ),
			)
		);
	}

	/**
	 * Format a task item into a compact label for the admin bar.
	 *
	 * @param \Stagify\Domain\TaskItem $item Task item.
	 * @return string HTML label.
	 */
	private function format_item_label( \Stagify\Domain\TaskItem $item ): string {
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
		if ( \Stagify\Domain\TaskItemType::File === $item->type ) {
			$name = basename( $item->object_id );
		} elseif ( \Stagify\Domain\TaskItemType::Content === $item->type ) {
			$post_title = get_the_title( (int) $item->object_id );
			if ( '' !== $post_title ) {
				$name = $post_title;
			}
		} elseif ( \Stagify\Domain\TaskItemType::Environment === $item->type ) {
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
						'stagify_action' => 'activate',
						'task_id'        => $task->id,
					),
					admin_url( 'admin.php?page=stagify' )
				),
				'stagify_task_action'
			);

			$wp_admin_bar->add_node(
				array(
					'parent' => 'stagify',
					'id'     => 'stagify-task-' . $task->id,
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
	 * @param \Stagify\Domain\Task|null $task The active task, or null.
	 * @return string HTML string (dynamic parts are escaped).
	 */
	private function get_admin_bar_title( ?\Stagify\Domain\Task $task ): string {
		if ( null === $task ) {
			return '<span style="color:#9e9e9e;">' . esc_html__( 'No active task', 'stagify' ) . '</span>';
		}

		$label = esc_html( $task->title )
			. ' &middot; '
			. esc_html( (string) $task->item_count )
			. ' '
			. esc_html__( 'changes', 'stagify' );

		return '<span style="color:#ff7759;">' . $label . '</span>';
	}
}
