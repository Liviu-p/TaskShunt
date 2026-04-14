<?php
/**
 * Task detail page.
 *
 * @package Stagify\Admin
 */

declare(strict_types=1);

namespace Stagify\Admin;

use Stagify\Contracts\TaskItemRepositoryInterface;
use Stagify\Contracts\TaskRepositoryInterface;
use Stagify\Domain\Task;
use Stagify\Domain\TaskAction;
use Stagify\Domain\TaskItem;
use Stagify\Domain\TaskItemType;
use Stagify\Domain\TaskStatus;

/**
 * Renders the detail view for a single task and its items.
 *
 * Displayed when ?page=stagify&action=view&task_id=X.
 */
final class TaskDetailPage {

	/**
	 * Create the task detail page.
	 *
	 * @param TaskRepositoryInterface     $task_repository      Task repository.
	 * @param TaskItemRepositoryInterface $task_item_repository Task item repository.
	 */
	public function __construct(
		private readonly TaskRepositoryInterface $task_repository,
		private readonly TaskItemRepositoryInterface $task_item_repository,
	) {}

	/**
	 * Render the page for the given task ID.
	 *
	 * @param int $task_id Task ID from the URL parameter.
	 * @return void
	 */
	public function render( int $task_id ): void {
		$task = $this->task_repository->find_by_id( $task_id );

		if ( null === $task ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Task not found.', 'stagify' ) . '</p></div>';
			return;
		}

		$items           = $this->task_item_repository->find_by_task( $task_id );
		$active_task_id  = $this->task_repository->get_active_task_id();
		$base_action_url = wp_nonce_url( admin_url( 'admin.php?page=stagify' ), 'stagify_task_action' );

		echo '<div class="wrap">';
		$this->render_header( $task, $active_task_id, $base_action_url );
		$this->render_items_table( $items );
		echo '</div>';
		$this->render_payload_toggle_script();
	}

	/**
	 * Render the page header: meta, back link, and action buttons.
	 *
	 * @param Task     $task            The task entity.
	 * @param int|null $active_task_id  Currently active task ID or null.
	 * @param string   $base_action_url Base URL for action links (nonce included).
	 * @return void
	 */
	private function render_header( Task $task, ?int $active_task_id, string $base_action_url ): void {
		$back_url = admin_url( 'admin.php?page=stagify' );

		echo '<a href="' . esc_url( $back_url ) . '">&larr; ' . esc_html__( 'All tasks', 'stagify' ) . '</a>';
		echo '<h1 style="margin-top:8px;">' . esc_html( $task->title ) . ' ' . $this->status_badge( $task->status ) . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			'<p style="color:#666;">%s &nbsp;|&nbsp; %s</p>',
			esc_html(
				sprintf(
				/* translators: %s: formatted date and time */
					__( 'Created %s', 'stagify' ),
					$task->created_at->format( 'Y-m-d H:i' )
				) 
			),
			esc_html(
				sprintf(
				/* translators: %d: number of items */
					_n( '%d item', '%d items', $task->item_count, 'stagify' ),
					$task->item_count
				) 
			)
		);
		$this->render_action_buttons( $task, $active_task_id, $base_action_url );
	}

	/**
	 * Render the push, activate, and discard action buttons.
	 *
	 * @param Task     $task            The task entity.
	 * @param int|null $active_task_id  Currently active task ID or null.
	 * @param string   $base_action_url Base URL for action links (nonce included).
	 * @return void
	 */
	private function render_action_buttons( Task $task, ?int $active_task_id, string $base_action_url ): void {
		echo '<p>';
		$this->render_push_button( $task->id );
		$this->render_secondary_buttons( $task, $active_task_id, $base_action_url );
		echo '</p>';
	}

	/**
	 * Render the 'Push this task' primary button as a POST form.
	 *
	 * @param int $task_id Task ID.
	 * @return void
	 */
	private function render_push_button( int $task_id ): void {
		echo $this->push_form( $task_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Build a nonce-protected link for the push action.
	 *
	 * @param int $task_id Task ID to push.
	 * @return string HTML link.
	 */
	private function push_form( int $task_id ): string {
		return sprintf(
			'<a href="#" class="button button-primary stagify-push-btn" data-task-id="%d" data-cy="push-task">%s</a> ',
			$task_id,
			esc_html__( 'Push this task', 'stagify' )
		);
	}

	/**
	 * Render the conditional 'Set as active' and 'Discard task' buttons.
	 *
	 * @param Task     $task            The task entity.
	 * @param int|null $active_task_id  Currently active task ID or null.
	 * @param string   $base_action_url Base URL for action links.
	 * @return void
	 */
	private function render_secondary_buttons( Task $task, ?int $active_task_id, string $base_action_url ): void {
		if ( TaskStatus::Pending === $task->status && null === $active_task_id ) {
			printf(
				'<a href="%s" class="button" data-cy="activate-task">%s</a> ',
				esc_url(
					add_query_arg(
						array(
							'stagify_action' => 'activate',
							'task_id'        => $task->id,
						),
						$base_action_url 
					) 
				),
				esc_html__( 'Set as active', 'stagify' )
			);
		}

		if ( TaskStatus::Failed === $task->status ) {
			echo $this->retry_form( $task->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( TaskStatus::Pending === $task->status || TaskStatus::Failed === $task->status ) {
			echo $this->discard_form( $task->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Build a nonce-protected link for the discard action.
	 *
	 * @param int $task_id Task ID to discard.
	 * @return string HTML link.
	 */
	private function discard_form( int $task_id ): string {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'stagify_discard_task',
					'task_id' => $task_id,
				),
				admin_url( 'admin-post.php' )
			),
			'stagify_discard_task'
		);

		return sprintf(
			'<a href="%s" class="button" style="color:#b32d2e;" data-cy="discard-task" onclick="return confirm(\'%s\');">%s</a>',
			esc_url( $url ),
			esc_js( __( 'Discard this task and all its tracked changes?', 'stagify' ) ),
			esc_html__( 'Discard task', 'stagify' )
		);
	}

	/**
	 * Build a nonce-protected link for the retry action.
	 *
	 * @param int $task_id Task ID to retry.
	 * @return string HTML link.
	 */
	private function retry_form( int $task_id ): string {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'stagify_retry_task',
					'task_id' => $task_id,
				),
				admin_url( 'admin-post.php' )
			),
			'stagify_retry_task'
		);

		return sprintf(
			'<a href="%s" class="button" style="color:#f0b849;" data-cy="retry-task">%s</a> ',
			esc_url( $url ),
			esc_html__( 'Retry', 'stagify' )
		);
	}

	/**
	 * Render the items table.
	 *
	 * @param array<int, TaskItem> $items Task items to display.
	 * @return void
	 */
	private function render_items_table( array $items ): void {
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'No items in this task yet.', 'stagify' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped" style="margin-top:16px;">';
		echo '<thead><tr>';
		foreach ( array( __( 'Type', 'stagify' ), __( 'Object', 'stagify' ), __( 'Action', 'stagify' ), __( 'Status', 'stagify' ), __( 'Payload', 'stagify' ) ) as $col ) {
			echo '<th>' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $items as $item ) {
			$this->render_item_row( $item );
		}

		echo '</tbody></table>';
	}

	/**
	 * Render a single item row.
	 *
	 * @param TaskItem $item The task item.
	 * @return void
	 */
	private function render_item_row( TaskItem $item ): void {
		echo '<tr>';
		echo '<td>' . $this->type_badge( $item->type ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td>' . $this->render_object_cell( $item ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td>' . $this->action_badge( $item->action ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td>' . $this->status_badge( $item->status ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td>' . $this->render_payload_cell( $item->id, $item->payload ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</tr>';
	}

	/**
	 * Render the object cell: path+hash for file items, type+ID for content items.
	 *
	 * @param TaskItem $item The task item.
	 * @return string Escaped HTML.
	 */
	private function render_object_cell( TaskItem $item ): string {
		if ( TaskItemType::File === $item->type ) {
			$decoded = json_decode( $item->payload, true );
			$hash    = is_array( $decoded ) && isset( $decoded['hash'] ) ? (string) $decoded['hash'] : '—';
			return '<code>' . esc_html( $item->object_id ) . '</code><br><small style="color:#888;">' . esc_html( $hash ) . '</small>';
		}

		return esc_html( $item->object_type ) . ' #' . esc_html( $item->object_id );
	}

	/**
	 * Render the collapsible payload cell.
	 *
	 * @param int    $item_id The item ID used as a unique toggle target.
	 * @param string $payload Raw JSON payload string.
	 * @return string HTML with a toggle button and hidden pre block.
	 */
	private function render_payload_cell( int $item_id, string $payload ): string {
		$formatted = wp_json_encode( json_decode( $payload, true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		$id        = 'stagify-payload-' . $item_id;

		return sprintf(
			'<button type="button" class="button button-small stagify-payload-toggle" data-target="%s">%s</button>'
			. '<pre id="%s" style="display:none;max-height:200px;overflow:auto;font-size:11px;margin-top:6px;">%s</pre>',
			esc_attr( $id ),
			esc_html__( 'Show payload', 'stagify' ),
			esc_attr( $id ),
			esc_html( false !== $formatted ? $formatted : $payload )
		);
	}

	/**
	 * Render a coloured type badge.
	 *
	 * @param TaskItemType $type Item type.
	 * @return string HTML badge.
	 */
	private function type_badge( TaskItemType $type ): string {
		return match ( $type ) {
			TaskItemType::Content  => '<span style="background:#e8f0fe;color:#1a56db;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">' . esc_html__( 'Content', 'stagify' ) . '</span>',
			TaskItemType::File     => '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">' . esc_html__( 'File', 'stagify' ) . '</span>',
			TaskItemType::Database    => '<span style="background:#f3e8ff;color:#6b21a8;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">' . esc_html__( 'Database', 'stagify' ) . '</span>',
			TaskItemType::Environment => '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">' . esc_html__( 'Environment', 'stagify' ) . '</span>',
		};
	}

	/**
	 * Render a coloured action badge.
	 *
	 * @param TaskAction $action Item action.
	 * @return string HTML badge.
	 */
	private function action_badge( TaskAction $action ): string {
		return match ( $action ) {
			TaskAction::Create => '<span style="color:#46b450;font-weight:600;">' . esc_html__( 'Create', 'stagify' ) . '</span>',
			TaskAction::Update => '<span style="color:#f0b849;font-weight:600;">' . esc_html__( 'Update', 'stagify' ) . '</span>',
			TaskAction::Delete => '<span style="color:#dc3232;font-weight:600;">' . esc_html__( 'Delete', 'stagify' ) . '</span>',
		};
	}

	/**
	 * Render a coloured status badge for a task or item status.
	 *
	 * @param TaskStatus $status Status enum value.
	 * @return string HTML badge.
	 */
	private function status_badge( TaskStatus $status ): string {
		return match ( $status ) {
			TaskStatus::Pending => '<span style="color:#a0a5aa;font-weight:600;">' . esc_html__( 'Pending', 'stagify' ) . '</span>',
			TaskStatus::Pushing => '<span style="color:#f0b849;font-weight:600;">' . esc_html__( 'Pushing', 'stagify' ) . '</span>',
			TaskStatus::Pushed  => '<span style="color:#00a0d2;font-weight:600;">' . esc_html__( 'Pushed', 'stagify' ) . '</span>',
			TaskStatus::Failed  => '<span style="color:#dc3232;font-weight:600;">' . esc_html__( 'Failed', 'stagify' ) . '</span>',
		};
	}

	/**
	 * Output the inline JS for the payload toggle buttons.
	 *
	 * @return void
	 */
	private function render_payload_toggle_script(): void {
		echo '<script>document.querySelectorAll(".stagify-payload-toggle").forEach(function(btn){'
			. 'btn.addEventListener("click",function(){'
			. 'var pre=document.getElementById(btn.dataset.target);'
			. 'if(!pre)return;'
			. 'var hidden=pre.style.display==="none";'
			. 'pre.style.display=hidden?"block":"none";'
			. 'btn.textContent=hidden?"' . esc_js( __( 'Hide payload', 'stagify' ) ) . '":"' . esc_js( __( 'Show payload', 'stagify' ) ) . '";'
			. '});});</script>';
	}
}
