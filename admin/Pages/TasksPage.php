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

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Stagify Tasks', 'stagify' ) . '</h1>';

		$this->render_server_badge( $server );

		if ( null !== $active_task ) {
			$this->render_active_banner( $active_task->title, $active_task->item_count, $active_task->id );
		}

		$this->render_create_form();
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
		printf(
			'<div style="background:#edfaef;border-left:4px solid #46b450;padding:12px 16px;margin:16px 0;display:flex;align-items:center;gap:16px;">'
			. '<strong>%s</strong>'
			. '<span style="color:#555;">%s</span>'
			. '<a href="#" class="button button-primary button-small stagify-push-btn" data-task-id="%d">%s</a>'
			. '</div>',
			esc_html( $title ),
			/* translators: %d: number of tracked changes in the task */
			esc_html( sprintf( _n( '%d change', '%d changes', $item_count, 'stagify' ), $item_count ) ),
			$task_id,
			esc_html__( 'Push now', 'stagify' )
		);
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
			printf(
				'<p style="text-align:right;"><span style="background:#f0f0f1;color:#50575e;padding:4px 10px;border-radius:3px;font-size:13px;">%s</span></p>',
				esc_html( $server->name )
			);
			return;
		}

		printf(
			'<p style="text-align:right;"><span style="background:#fcf9e8;color:#996800;padding:4px 10px;border-radius:3px;font-size:13px;">%s</span> <a href="%s">%s</a></p>',
			esc_html__( 'No server configured', 'stagify' ),
			esc_url( admin_url( 'admin.php?page=stagify-settings' ) ),
			esc_html__( 'Configure', 'stagify' )
		);
	}

	/**
	 * Render the create-new-task form.
	 *
	 * @return void
	 */
	private function render_create_form(): void {
		echo '<h2>' . esc_html__( 'Create new task', 'stagify' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'stagify_create_task' );
		printf(
			'<input type="text" name="stagify_task_title" placeholder="%s" maxlength="%d" style="width:320px;" required> ',
			esc_attr__( 'Task title…', 'stagify' ),
			(int) self::MAX_TITLE_LENGTH
		);
		submit_button( __( 'Create task', 'stagify' ), 'secondary', 'submit', false );
		echo '</form>';
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
