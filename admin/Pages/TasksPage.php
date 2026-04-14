<?php
/**
 * Tasks admin page.
 *
 * @package Stagify\Admin\Pages
 */

declare(strict_types=1);

namespace Stagify\Admin\Pages;

use DI\Container;
use Stagify\Admin\Notices;
use Stagify\Admin\OnboardingChecklist;
use Stagify\Admin\TasksListTable;
use Stagify\Contracts\EventDispatcherInterface;
use Stagify\Contracts\ServerRepositoryInterface;
use Stagify\Contracts\TaskRepositoryInterface;
use Stagify\Domain\TaskStatus;
use Stagify\Events\TaskActivated;

/**
 * Renders the Tasks admin page including the create-task form and list table.
 */
final class TasksPage {

	/**
	 * Maximum allowed task title length.
	 */
	private const MAX_TITLE_LENGTH = 200;

	/**
	 * Create the tasks page.
	 *
	 * @param TaskRepositoryInterface   $task_repository   Task repository.
	 * @param ServerRepositoryInterface $server_repository Server repository.
	 * @param EventDispatcherInterface  $event_dispatcher  Event dispatcher.
	 * @param Container                 $container         DI container.
	 */
	public function __construct(
		private readonly TaskRepositoryInterface $task_repository,
		private readonly ServerRepositoryInterface $server_repository,
		private readonly EventDispatcherInterface $event_dispatcher,
		private readonly Container $container,
	) {}

	/**
	 * Handle POST submissions before output is sent.
	 *
	 * Must be called on the load-{page} hook so redirects happen
	 * before WordPress sends headers.
	 *
	 * @return void
	 */
	public function handle_post(): void {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $request_method ) {
			return;
		}

		$bulk_action = $this->current_bulk_action();
		if ( 'bulk_discard' === $bulk_action ) {
			$this->handle_bulk_discard();
		} else {
			$this->handle_create_task();
		}
	}

	/**
	 * Output the page HTML.
	 *
	 * @return void
	 */
	public function render(): void {
		$active_task = $this->task_repository->find_active();
		$server      = $this->server_repository->find();

		echo '<div class="wrap stagify-wrap">';

		echo '<div class="stagify-page-header">';
		echo '<h1>' . esc_html__( 'Tasks', 'stagify' ) . '</h1>';
		$this->render_create_form();
		echo '</div>';
		echo '<p class="stagify-subheading">' . esc_html__( 'Group your changes into tasks and push them to production when ready.', 'stagify' ) . '</p>';

		OnboardingChecklist::render_sender( $this->server_repository );
		$this->render_server_badge( $server );

		if ( null !== $active_task && 0 === $active_task->item_count ) {
			$this->render_active_guide( $active_task->title );
		}

		if ( null === $active_task ) {
			$this->render_no_active_prompt();
		}

		$this->render_list_table();

		echo '</div>';
	}

	/**
	 * Return the selected bulk action from either the top or bottom dropdown, or empty string.
	 *
	 * @return string
	 */
	private function current_bulk_action(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$action  = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : '';
		$action2 = isset( $_POST['action2'] ) ? sanitize_key( $_POST['action2'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '-1' !== $action && '' !== $action ) {
			return $action;
		}
		return ( '-1' !== $action2 && '' !== $action2 ) ? $action2 : '';
	}

	/**
	 * Process the 'Discard selected' bulk action.
	 *
	 * Skips tasks with status Active or Pushing; deletes the rest.
	 * Clears the active flag first if the active task is among the selection.
	 *
	 * @return void
	 */
	private function handle_bulk_discard(): void {
		check_admin_referer( 'bulk-tasks' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stagify' ) );
		}

		$raw_ids        = isset( $_POST['task'] ) && is_array( $_POST['task'] ) ? $_POST['task'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$task_ids       = array_map( 'intval', $raw_ids );
		$active_task_id = $this->task_repository->get_active_task_id();
		$discarded      = 0;

		if ( null !== $active_task_id && in_array( $active_task_id, $task_ids, true ) ) {
			$this->task_repository->clear_active();
		}

		foreach ( $task_ids as $task_id ) {
			$discarded += $this->maybe_discard_task( $task_id );
		}

		Notices::add(
			'success',
			sprintf(
				/* translators: %d: number of tasks discarded */
				_n( '%d task discarded.', '%d tasks discarded.', $discarded, 'stagify' ),
				$discarded
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=stagify' ) );
		exit;
	}

	/**
	 * Delete a single task if its status allows it.
	 *
	 * Skips tasks with status Pushing (Active is Pending — Pending is discardable).
	 *
	 * @param int $task_id Task ID to discard.
	 * @return int 1 if discarded, 0 if skipped.
	 */
	private function maybe_discard_task( int $task_id ): int {
		$task = $this->task_repository->find_by_id( $task_id );

		if ( null === $task ) {
			return 0;
		}

		if ( TaskStatus::Pushing === $task->status ) {
			return 0;
		}

		$this->task_repository->delete( $task_id );
		return 1;
	}

	/**
	 * Process the create-task form submission.
	 *
	 * Creates the task, sets it active, dispatches the event, then redirects.
	 *
	 * @return void
	 */
	private function handle_create_task(): void {
		check_admin_referer( 'stagify_create_task' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stagify' ) );
		}

		$raw_title = isset( $_POST['stagify_task_title'] ) ? sanitize_text_field( wp_unslash( $_POST['stagify_task_title'] ) ) : '';
		$title     = substr( $raw_title, 0, self::MAX_TITLE_LENGTH );

		if ( '' === $title ) {
			Notices::add( 'error', __( 'Task title cannot be empty.', 'stagify' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=stagify' ) );
			exit;
		}

		$task_id = $this->task_repository->create( $title );
		$this->task_repository->set_active( $task_id );

		$task = $this->task_repository->find_by_id( $task_id );
		if ( null !== $task ) {
			$this->event_dispatcher->dispatch( new TaskActivated( $task ) );
		}

		Notices::add( 'success', __( 'Task created and set as active.', 'stagify' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=stagify' ) );
		exit;
	}

	/**
	 * Render the active task banner.
	 *
	 * @param string $title      Task title.
	 * @param int    $item_count Number of tracked items.
	 * @param int    $task_id    Task ID for the push link.
	 * @return void
	 */
	private function render_active_banner( string $title, int $item_count, int $task_id ): void {
		echo '<div class="stagify-active-banner">';
		printf( '<strong>%s</strong>', esc_html( $title ) );
		/* translators: %d: number of tracked changes in the task */
		printf( '<span class="stagify-change-count">%s</span>', esc_html( sprintf( _n( '%d change', '%d changes', $item_count, 'stagify' ), $item_count ) ) );

		if ( $item_count > 0 ) {
			printf(
				'<a href="#" class="button button-primary button-small stagify-push-btn" data-task-id="%d">%s</a>',
				$task_id,
				esc_html__( 'Push now', 'stagify' )
			);
		}

		echo '</div>';
	}

	/**
	 * Render the server status badge.
	 *
	 * Shows a yellow warning with a settings link when no server is configured,
	 * or a gray badge with the server name when one exists.
	 *
	 * @param \Stagify\Domain\Server|null $server The configured server, or null.
	 * @return void
	 */
	private function render_server_badge( ?\Stagify\Domain\Server $server ): void {
		if ( null !== $server ) {
			return;
		}

		// Don't show if onboarding checklist is visible — it already explains this.
		if ( OnboardingChecklist::should_show() && ! OnboardingChecklist::is_complete(
			\Stagify\Domain\PluginMode::Sender,
			$this->server_repository
		) ) {
			return;
		}

		printf(
			'<div class="stagify-notice stagify-notice--warning">'
			. '<span class="dashicons dashicons-warning"></span>'
			. '<div>'
			. '<strong>%s</strong>'
			. '<p>%s</p>'
			. '</div>'
			. '<a href="%s" class="button button-small stagify-btn-coral">%s</a>'
			. '</div>',
			esc_html__( 'No server connected', 'stagify' ),
			esc_html__( 'Connect a production server to start pushing changes.', 'stagify' ),
			esc_url( admin_url( 'admin.php?page=stagify-settings' ) ),
			esc_html__( 'Set up server', 'stagify' )
		);
	}

	/**
	 * Render the active task guide when a task has 0 changes.
	 *
	 * @param string $title Active task title.
	 * @return void
	 */
	private function render_active_guide( string $title ): void {
		printf(
			'<div class="stagify-guide-inline">'
			. '<span class="stagify-pulse-dot"></span>'
			. '<span><strong>"%s"</strong> %s</span>'
			. '</div>',
			esc_html( $title ),
			esc_html__( 'is active — edit pages, posts, or media as usual. Changes are tracked automatically. Push when done.', 'stagify' )
		);
	}

	/**
	 * Render a prompt when no task is active.
	 *
	 * Shows contextual message — success after push, or nudge to start.
	 *
	 * @return void
	 */
	private function render_no_active_prompt(): void {
		$all_tasks  = $this->task_repository->find_all();
		$has_pushed = false;

		foreach ( $all_tasks as $task ) {
			if ( \Stagify\Domain\TaskStatus::Pushed === $task->status ) {
				$has_pushed = true;
				break;
			}
		}

		if ( empty( $all_tasks ) ) {
			// First time.
			$icon    = 'dashicons-flag';
			$title   = __( 'Ready to start', 'stagify' );
			$message = __( 'Create your first task to begin tracking content changes.', 'stagify' );
			$class   = '';
		} elseif ( $has_pushed ) {
			// Has pushed tasks — celebrate.
			$icon    = 'dashicons-yes-alt';
			$title   = __( 'All changes pushed!', 'stagify' );
			$message = __( 'Your changes are live on production. Create a new task to keep going.', 'stagify' );
			$class   = ' stagify-prompt--success';
		} else {
			// Has tasks but none active.
			$icon    = 'dashicons-info-outline';
			$title   = __( 'No active task', 'stagify' );
			$message = __( 'Create a new task or click "Work on this" on an existing one.', 'stagify' );
			$class   = '';
		}

		printf(
			'<div class="stagify-prompt%s">'
			. '<span class="dashicons %s"></span>'
			. '<div>'
			. '<strong>%s</strong>'
			. '<p>%s</p>'
			. '</div>'
			. '<button type="button" class="button button-primary" onclick="document.getElementById(\'stagify-new-task-toggle\').click();">%s</button>'
			. '</div>',
			esc_attr( $class ),
			esc_attr( $icon ),
			esc_html( $title ),
			esc_html( $message ),
			esc_html__( '+ New Task', 'stagify' )
		);
	}

	/**
	 * Render the create-new-task form.
	 *
	 * @return void
	 */
	private function render_create_form(): void {
		printf(
			'<button type="button" class="button button-primary stagify-new-task-btn" id="stagify-new-task-toggle">+ %s</button>',
			esc_html__( 'New Task', 'stagify' )
		);
		echo '<form method="post" class="stagify-create-form" id="stagify-create-form" style="display:none;">';
		wp_nonce_field( 'stagify_create_task' );
		printf(
			'<input type="text" name="stagify_task_title" placeholder="%s" maxlength="%d" required>',
			esc_attr__( 'e.g. Homepage redesign, Blog updates…', 'stagify' ),
			(int) self::MAX_TITLE_LENGTH
		);
		printf(
			'<button type="submit" class="button button-primary">%s</button>',
			esc_html__( 'Create', 'stagify' )
		);
		printf(
			'<button type="button" class="button stagify-create-cancel" id="stagify-create-cancel">%s</button>',
			esc_html__( 'Cancel', 'stagify' )
		);
		echo '</form>';
		echo '<script>'
			. '(function(){'
			. 'var btn=document.getElementById("stagify-new-task-toggle");'
			. 'var form=document.getElementById("stagify-create-form");'
			. 'var cancel=document.getElementById("stagify-create-cancel");'
			. 'if(!btn||!form||!cancel)return;'
			. 'btn.addEventListener("click",function(){btn.style.display="none";form.style.display="flex";form.querySelector("input").focus();});'
			. 'cancel.addEventListener("click",function(){form.style.display="none";btn.style.display="inline-flex";});'
			. '})();'
			. '</script>';
	}

	/**
	 * Render the tasks list table.
	 *
	 * @return void
	 */
	private function render_list_table(): void {
		$table = $this->container->get( TasksListTable::class );
		$table->prepare_items();
		echo '<form method="post">';
		$table->display();
		echo '</form>';
	}
}
