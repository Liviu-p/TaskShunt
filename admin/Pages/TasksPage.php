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
		$all_tasks   = $this->task_repository->find_all();

		echo '<div class="wrap stagify-wrap">';

		echo '<div class="stagify-page-header">';
		echo '<h1>' . esc_html__( 'Tasks', 'stagify' ) . '</h1>';
		if ( ! empty( $all_tasks ) ) {
			$this->render_create_form();
		}
		echo '</div>';
		if ( ! empty( $all_tasks ) ) {
			echo '<p class="stagify-subheading">' . esc_html__( 'Group your changes into tasks and push them to production when ready.', 'stagify' ) . '</p>';
		}

		OnboardingChecklist::render_sender( $this->server_repository );
		$this->render_server_badge( $server );

		if ( null !== $active_task && 0 === $active_task->item_count ) {
			$this->render_active_guide( $active_task->title );
		}

		if ( empty( $all_tasks ) ) {
			$this->render_welcome_state();
		} else {
			$this->render_list_table();
			$this->render_push_history();
		}

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
				(int) $task_id,
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
			'<div class="stagify-guide-inline" id="stagify-guide">'
			. '<span class="stagify-pulse-dot"></span>'
			. '<span><strong>"%s"</strong> %s</span>'
			. '<button type="button" class="stagify-guide-close" onclick="this.parentElement.remove();" aria-label="%s">&times;</button>'
			. '</div>',
			esc_html( $title ),
			esc_html__( 'is active — just work on your site as usual. Content edits, media uploads, plugin and theme changes are all tracked automatically.', 'stagify' ),
			esc_attr__( 'Dismiss', 'stagify' )
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
		$prompt = $this->resolve_prompt_data();

		printf(
			'<div class="stagify-prompt%s">'
			. '<span class="dashicons %s"></span>'
			. '<div>'
			. '<strong>%s</strong>'
			. '<p>%s</p>'
			. '</div>'
			. '<button type="button" class="button button-primary" onclick="document.getElementById(\'stagify-new-task-toggle\').click();">%s</button>'
			. '</div>',
			esc_attr( $prompt['class'] ),
			esc_attr( $prompt['icon'] ),
			esc_html( $prompt['title'] ),
			esc_html( $prompt['message'] ),
			esc_html__( '+ New Task', 'stagify' )
		);
	}

	/**
	 * Determine the prompt icon, title, message, and class based on task state.
	 *
	 * @return array{icon: string, title: string, message: string, class: string}
	 */
	private function resolve_prompt_data(): array {
		$all_tasks = $this->task_repository->find_all();

		if ( empty( $all_tasks ) ) {
			return array(
				'icon'    => 'dashicons-flag',
				'title'   => __( 'Ready to start', 'stagify' ),
				'message' => __( 'Create your first task to begin tracking content changes.', 'stagify' ),
				'class'   => '',
			);
		}

		return array(
			'icon'    => 'dashicons-info-outline',
			'title'   => __( 'No active task', 'stagify' ),
			'message' => __( 'Create a new task or click "Work on this" on an existing one.', 'stagify' ),
			'class'   => '',
		);
	}

	/**
	 * Render the recent push history log.
	 *
	 * @return void
	 */
	private function render_push_history(): void { // phpcs:ignore SlevomatCodingStandard.Functions.FunctionLength.FunctionLength
		global $wpdb;
		$table = $wpdb->prefix . 'stagify_push_log';
		$tasks = $wpdb->prefix . 'stagify_tasks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$logs = $wpdb->get_results(
			"SELECT l.*, t.title as task_title FROM {$table} l LEFT JOIN {$tasks} t ON l.task_id = t.id ORDER BY l.pushed_at DESC LIMIT 5" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( empty( $logs ) ) {
			return;
		}

		echo '<div class="stagify-history">';
		echo '<h2>' . esc_html__( 'Recent pushes', 'stagify' ) . '</h2>';
		echo '<div class="stagify-history-list">';

		foreach ( $logs as $log ) {
			$is_success = (int) $log->http_code >= 200 && (int) $log->http_code < 300;
			$icon_class = $is_success ? 'stagify-history-icon--success' : 'stagify-history-icon--failed';
			$icon       = $is_success ? 'dashicons-yes-alt' : 'dashicons-warning';
			/* translators: %d: task ID */
			$title = ! empty( $log->task_title ) ? $log->task_title : sprintf( __( 'Task #%d', 'stagify' ), $log->task_id );

			$detail_url = admin_url( 'admin.php?page=stagify&action=view&task_id=' . (int) $log->task_id );

			printf(
				'<a href="%s" class="stagify-history-item">'
				. '<span class="dashicons %s %s"></span>'
				. '<div class="stagify-history-info">'
				. '<strong>%s</strong>'
				. '<span class="stagify-history-meta">%s</span>'
				. '</div>'
				. '<span class="stagify-history-time">%s</span>'
				. '</a>',
				esc_url( $detail_url ),
				esc_attr( $icon ),
				esc_attr( $icon_class ),
				esc_html( $title ),
				$is_success ? esc_html__( 'Pushed successfully', 'stagify' ) : esc_html( $log->response_message ),
				esc_html( human_time_diff( strtotime( $log->pushed_at ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'stagify' ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			);
		}

		echo '</div></div>';
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
	}

	/**
	 * Render the welcome state when no tasks exist yet.
	 *
	 * @return void
	 */
	private function render_welcome_state(): void {
		echo '<div class="stagify-welcome">';
		echo '<div class="stagify-welcome-steps">';
		$this->render_welcome_step_create();
		$this->render_welcome_step_change();
		$this->render_welcome_step_push();
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the "Create a task" welcome step with inline form.
	 *
	 * @return void
	 */
	private function render_welcome_step_create(): void {
		echo '<div class="stagify-welcome-step">';
		printf( '<span class="stagify-welcome-number">1</span>' );
		printf( '<strong>%s</strong>', esc_html__( 'Create a task', 'stagify' ) );
		printf( '<p>%s</p>', esc_html__( 'Give it a name like "Homepage update" or "New blog posts".', 'stagify' ) );
		echo '<form method="post" style="margin-top:12px;">';
		wp_nonce_field( 'stagify_create_task' );
		printf(
			'<div style="display:flex;gap:8px;justify-content:center;">'
			. '<input type="text" name="stagify_task_title" placeholder="%s" maxlength="%d" class="regular-text" required style="max-width:200px;">'
			. '<button type="submit" class="button button-primary">%s</button>'
			. '</div></form>',
			esc_attr__( 'Task name…', 'stagify' ),
			200,
			esc_html__( 'Create', 'stagify' )
		);
		echo '</div>';
	}

	/**
	 * Render the "Make your changes" welcome step.
	 *
	 * @return void
	 */
	private function render_welcome_step_change(): void {
		printf(
			'<div class="stagify-welcome-step">'
			. '<span class="stagify-welcome-number">2</span>'
			. '<strong>%s</strong>'
			. '<p>%s</p>'
			. '</div>',
			esc_html__( 'Make your changes', 'stagify' ),
			esc_html__( 'Edit content, upload media, activate plugins — everything is tracked automatically.', 'stagify' )
		);
	}

	/**
	 * Render the "Push to production" welcome step.
	 *
	 * @return void
	 */
	private function render_welcome_step_push(): void {
		printf(
			'<div class="stagify-welcome-step">'
			. '<span class="stagify-welcome-number">3</span>'
			. '<strong>%s</strong>'
			. '<p>%s</p>'
			. '</div>',
			esc_html__( 'Push to production', 'stagify' ),
			esc_html__( 'Review your changes and send them to your live site in one click.', 'stagify' )
		);
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
