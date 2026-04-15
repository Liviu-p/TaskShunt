<?php
/**
 * Task detail page.
 *
 * @package Stagify\Admin
 */

declare(strict_types=1);

namespace Stagify\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
			echo '<div class="wrap stagify-wrap"><p>' . esc_html__( 'Task not found.', 'stagify' ) . '</p></div>';
			return;
		}

		$items           = $this->task_item_repository->find_by_task( $task_id );
		$active_task_id  = $this->task_repository->get_active_task_id();
		$base_action_url = wp_nonce_url( admin_url( 'admin.php?page=stagify' ), 'stagify_task_action' );

		echo '<div class="wrap stagify-wrap">';
		$this->render_header( $task, $active_task_id, $base_action_url );

		if ( TaskStatus::Pushed === $task->status ) {
			printf(
				'<div class="stagify-readonly-banner">'
				. '<span class="dashicons dashicons-yes-alt"></span>'
				. '<span>%s</span>'
				. '</div>',
				esc_html__( 'This task has been pushed to production. Changes below are read-only.', 'stagify' )
			);
		}

		$this->render_items_table( $items );
		$this->render_footer_actions( $task, $active_task_id, $base_action_url );
		echo '</div>';
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
		$back_url  = admin_url( 'admin.php?page=stagify' );
		$is_active = TaskStatus::Pending === $task->status && $task->id === $active_task_id;

		echo '<a href="' . esc_url( $back_url ) . '" class="stagify-back-link">&larr; ' . esc_html__( 'Back to tasks', 'stagify' ) . '</a>';

		echo '<div class="stagify-page-header" style="margin-top:8px;">';
		echo '<h1>' . esc_html( $task->title );
		if ( $is_active ) {
			echo ' <span class="stagify-badge stagify-badge--active"><span class="stagify-pulse-dot"></span>' . esc_html__( 'Tracking', 'stagify' ) . '</span>';
		} elseif ( TaskStatus::Pushed === $task->status ) {
			echo ' <span class="stagify-badge stagify-badge--pushed">' . esc_html__( 'Pushed', 'stagify' ) . '</span>';
		} elseif ( TaskStatus::Failed === $task->status ) {
			echo ' <span class="stagify-badge stagify-badge--failed">' . esc_html__( 'Failed', 'stagify' ) . '</span>';
		}
		echo '</h1>';
		echo '<div class="stagify-actions" style="margin:0;">';
		$this->render_action_buttons( $task, $active_task_id, $base_action_url, $is_active );
		echo '</div>';
		echo '</div>';

		$meta_parts   = array( esc_html( $task->created_at->format( 'M j, Y H:i' ) ) );
		$meta_parts[] = esc_html(
			sprintf(
				/* translators: %d: number of changes */
				_n( '%d change', '%d changes', $task->item_count, 'stagify' ),
				$task->item_count
			)
		);
		if ( TaskStatus::Pushed === $task->status && null !== $task->pushed_at ) {
			/* translators: %s: relative time like "2 hours ago" */
			$meta_parts[] = esc_html( sprintf( __( 'Pushed %s', 'stagify' ), human_time_diff( $task->pushed_at->getTimestamp(), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'stagify' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		}
		echo '<p class="stagify-meta">' . implode( ' &middot; ', $meta_parts ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each part is pre-escaped via esc_html()
	}

	/**
	 * Render the push, activate, and discard action buttons.
	 *
	 * @param Task     $task            The task entity.
	 * @param int|null $active_task_id  Currently active task ID or null.
	 * @param string   $base_action_url Base URL for action links (nonce included).
	 * @param bool     $is_active       Whether this task is the active task.
	 * @return void
	 */
	private function render_action_buttons( Task $task, ?int $active_task_id, string $base_action_url, bool $is_active ): void {
		if ( $is_active && $task->item_count > 0 ) {
			$this->render_push_button( $task->id );
		}

		if ( TaskStatus::Pending === $task->status && ! $is_active ) {
			printf(
				'<a href="%s" class="button button-primary" data-cy="activate-task">%s</a> ',
				esc_url(
					add_query_arg(
						array(
							'stagify_action' => 'activate',
							'task_id'        => $task->id,
						),
						$base_action_url
					)
				),
				esc_html__( 'Work on this', 'stagify' )
			);
		}

		if ( TaskStatus::Failed === $task->status ) {
			echo $this->retry_form( $task->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
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
	 * Render footer actions (discard) — subtle, at the bottom.
	 *
	 * @param Task     $task            The task entity.
	 * @param int|null $active_task_id  Currently active task ID or null.
	 * @param string   $base_action_url Base URL for action links.
	 * @return void
	 */
	private function render_footer_actions( Task $task, ?int $active_task_id, string $base_action_url ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$is_active = TaskStatus::Pending === $task->status && $task->id === $active_task_id;

		if ( $is_active || ( TaskStatus::Pending !== $task->status && TaskStatus::Failed !== $task->status ) ) {
			return;
		}

		echo '<div class="stagify-detail-footer">';
		echo $this->discard_form( $task->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
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
			'<a href="%s" class="button stagify-link-danger stagify-confirm-link" data-cy="discard-task" data-confirm-title="%s" data-confirm-message="%s" data-confirm-label="%s" data-confirm-danger="1">%s</a>',
			esc_url( $url ),
			esc_attr__( 'Discard task?', 'stagify' ),
			esc_attr__( 'This will permanently delete this task and all its tracked changes.', 'stagify' ),
			esc_attr__( 'Discard', 'stagify' ),
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
			'<a href="%s" class="button stagify-link-warning stagify-confirm-link" data-cy="retry-task" data-confirm-title="%s" data-confirm-message="%s" data-confirm-label="%s">%s</a> ',
			esc_url( $url ),
			esc_attr__( 'Retry push?', 'stagify' ),
			esc_attr__( 'This will attempt to push all changes to production again. Make sure your server connection is working.', 'stagify' ),
			esc_attr__( 'Retry', 'stagify' ),
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
			echo '<div class="stagify-empty-state">';
			printf( '<p><strong>%s</strong></p>', esc_html__( 'No changes recorded yet', 'stagify' ) );
			printf( '<p>%s</p>', esc_html__( 'Just work on your site as usual — edit content, upload media, activate plugins, or switch themes. Every change is tracked automatically and will show up here.', 'stagify' ) );
			echo '</div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped" style="margin-top:16px;">';
		echo '<thead><tr>';
		foreach ( array( __( 'Type', 'stagify' ), __( 'Item', 'stagify' ), __( 'Action', 'stagify' ), '' ) as $col ) {
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
			return '<code>' . esc_html( basename( $item->object_id ) ) . '</code>';
		}

		if ( TaskItemType::Content === $item->type ) {
			$post_title = get_the_title( (int) $item->object_id );
			if ( '' !== $post_title ) {
				$edit_link = get_edit_post_link( (int) $item->object_id );
				if ( $edit_link ) {
					return '<a href="' . esc_url( $edit_link ) . '">' . esc_html( $post_title ) . '</a>';
				}
				return esc_html( $post_title );
			}
		}

		if ( TaskItemType::Environment === $item->type ) {
			$payload = json_decode( $item->payload, true );
			$name    = is_array( $payload ) && isset( $payload['name'] ) ? $payload['name'] : $item->object_id;
			return esc_html( $name );
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
			'<button type="button" class="button button-small stagify-payload-toggle" data-target="%s" data-label-show="%s" data-label-hide="%s">%s</button>'
			. '<pre id="%s" class="stagify-payload-pre">%s</pre>',
			esc_attr( $id ),
			esc_attr__( 'Details', 'stagify' ),
			esc_attr__( 'Hide', 'stagify' ),
			esc_html__( 'Details', 'stagify' ),
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
			TaskItemType::Content     => '<span class="stagify-badge stagify-badge--content">' . esc_html__( 'Content', 'stagify' ) . '</span>',
			TaskItemType::File        => '<span class="stagify-badge stagify-badge--file">' . esc_html__( 'File', 'stagify' ) . '</span>',
			TaskItemType::Database    => '<span class="stagify-badge stagify-badge--database">' . esc_html__( 'Database', 'stagify' ) . '</span>',
			TaskItemType::Environment => '<span class="stagify-badge stagify-badge--environment">' . esc_html__( 'Environment', 'stagify' ) . '</span>',
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
			TaskAction::Create => '<span class="stagify-action--create">' . esc_html__( 'Create', 'stagify' ) . '</span>',
			TaskAction::Update => '<span class="stagify-action--update">' . esc_html__( 'Update', 'stagify' ) . '</span>',
			TaskAction::Delete => '<span class="stagify-action--delete">' . esc_html__( 'Delete', 'stagify' ) . '</span>',
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
			TaskStatus::Pending => '<span class="stagify-badge stagify-badge--pending">' . esc_html__( 'Ready', 'stagify' ) . '</span>',
			TaskStatus::Pushing => '<span class="stagify-badge stagify-badge--pushing">' . esc_html__( 'Pushing…', 'stagify' ) . '</span>',
			TaskStatus::Pushed  => '<span class="stagify-badge stagify-badge--pushed">' . esc_html__( 'Done', 'stagify' ) . '</span>',
			TaskStatus::Failed  => '<span class="stagify-badge stagify-badge--failed">' . esc_html__( 'Failed', 'stagify' ) . '</span>',
		};
	}
}
