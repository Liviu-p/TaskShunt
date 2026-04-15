<?php
/**
 * Tasks list table.
 *
 * @package Stagify\Admin
 */

declare(strict_types=1);

namespace Stagify\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stagify\Contracts\TaskRepositoryInterface;
use Stagify\Domain\Task;
use Stagify\Domain\TaskStatus;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the task queue as a WP_List_Table.
 */
final class TasksListTable extends \WP_List_Table {

	/**
	 * ID of the currently active task, or null.
	 *
	 * @var int|null
	 */
	private ?int $active_task_id = null;

	/**
	 * Create the list table.
	 *
	 * @param TaskRepositoryInterface $task_repository Task repository.
	 */
	public function __construct( private readonly TaskRepositoryInterface $task_repository ) {
		parent::__construct(
			array(
				'singular' => 'task',
				'plural'   => 'tasks',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Return all column definitions.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox">',
			'title'      => __( 'Title', 'stagify' ),
			'status'     => __( 'Status', 'stagify' ),
			'item_count' => __( 'Changes', 'stagify' ),
			'created_at' => __( 'Activity', 'stagify' ),
		);
	}

	/**
	 * Return the available bulk actions.
	 *
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		return array(
			'bulk_discard' => __( 'Discard selected', 'stagify' ),
		);
	}

	/**
	 * Populate $this->items with tasks from the repository.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->active_task_id  = $this->task_repository->get_active_task_id();
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $this->task_repository->find_all();
	}

	/**
	 * Render the checkbox column.
	 *
	 * @param Task $item Current row task.
	 * @return string
	 */
	public function column_cb( $item ): string { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
		return sprintf( '<input type="checkbox" name="task[]" value="%d">', (int) $item->id );
	}

	/**
	 * Render the title column with row actions.
	 *
	 * @param Task $item Current row task.
	 * @return string
	 */
	public function column_title( $item ): string { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
		$detail_url = admin_url( 'admin.php?page=stagify&action=view&task_id=' . (int) $item->id );
		$title      = '<a href="' . esc_url( $detail_url ) . '"><strong>' . esc_html( $item->title ) . '</strong></a>';

		return $title . $this->row_actions( $this->build_row_actions( $item ) );
	}

	/**
	 * Render the status column as a coloured badge.
	 *
	 * @param Task $item Current row task.
	 * @return string
	 */
	public function column_status( $item ): string { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
		return $this->render_status_badge( $item );
	}

	/**
	 * Render the item count as a pill badge.
	 *
	 * @param Task $item Current row task.
	 * @return string
	 */
	public function column_item_count( $item ): string { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
		return sprintf(
			'<span class="stagify-pill">%d</span>',
			(int) $item->item_count
		);
	}

	/**
	 * Render the activity column as relative time.
	 *
	 * Shows pushed_at if pushed, otherwise created_at.
	 *
	 * @param Task $item Current row task.
	 * @return string
	 */
	public function column_created_at( $item ): string { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
		$date      = null !== $item->pushed_at ? $item->pushed_at : $item->created_at;
		$timestamp = $date->getTimestamp();
		$diff      = human_time_diff( $timestamp, current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		$label = null !== $item->pushed_at
			/* translators: %s: relative time */
			? sprintf( __( 'Pushed %s ago', 'stagify' ), $diff )
			/* translators: %s: relative time */
			: sprintf( __( '%s ago', 'stagify' ), $diff );

		return sprintf(
			'<span title="%s">%s</span>',
			esc_attr( $date->format( 'Y-m-d H:i:s' ) ),
			esc_html( $label )
		);
	}

	/**
	 * Build the row action links for a task.
	 *
	 * @param Task $item Current row task.
	 * @return array<string, string>
	 */
	private function build_row_actions( Task $item ): array {
		$actions    = array();
		$detail_url = admin_url( 'admin.php?page=stagify&action=view&task_id=' . (int) $item->id );

		$is_active = TaskStatus::Pending === $item->status && $item->id === $this->active_task_id;

		$actions['view'] = '<a href="' . esc_url( $detail_url ) . '">' . esc_html__( 'View', 'stagify' ) . '</a>';

		if ( TaskStatus::Pending === $item->status ) {
			$actions['rename'] = sprintf(
				'<a href="#" class="stagify-rename-trigger" data-task-id="%d" data-title="%s">%s</a>',
				(int) $item->id,
				esc_attr( $item->title ),
				esc_html__( 'Rename', 'stagify' )
			);
		}

		if ( $is_active ) {
			$actions['push'] = $this->push_form( $item->id );
		}

		if ( TaskStatus::Pending === $item->status && ! $is_active ) {
			$actions['activate'] = '<a href="' . esc_url( $this->activate_url( $item->id ) ) . '">' . esc_html__( 'Work on this', 'stagify' ) . '</a>';
		}

		if ( TaskStatus::Failed === $item->status ) {
			$actions['retry'] = $this->retry_form( $item->id );
		}

		if ( TaskStatus::Pushing !== $item->status ) {
			$actions['discard'] = $this->discard_form( $item->id, esc_html__( 'Delete', 'stagify' ) );
		}

		return $actions;
	}

	/**
	 * Build a nonce-protected link for the push row action.
	 *
	 * @param int $task_id Task ID to push.
	 * @return string HTML link.
	 */
	private function push_form( int $task_id ): string {
		return sprintf(
			'<a href="#" class="stagify-push-btn" data-task-id="%d">%s</a>',
			$task_id,
			esc_html__( 'Push', 'stagify' )
		);
	}

	/**
	 * Build a nonce-protected link for the retry row action.
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
			'<a href="%s" class="stagify-link-warning stagify-confirm-link" data-confirm-title="%s" data-confirm-message="%s" data-confirm-label="%s">%s</a>',
			esc_url( $url ),
			esc_attr__( 'Retry push?', 'stagify' ),
			esc_attr__( 'This will attempt to push all changes to production again. Make sure your server connection is working.', 'stagify' ),
			esc_attr__( 'Retry', 'stagify' ),
			esc_html__( 'Retry', 'stagify' )
		);
	}

	/**
	 * Build a nonce-protected link for the discard row action.
	 *
	 * @param int    $task_id Task ID to discard.
	 * @param string $label   Optional button label override.
	 * @return string HTML link.
	 */
	private function discard_form( int $task_id, string $label = '' ): string {
		if ( '' === $label ) {
			$label = esc_html__( 'Discard', 'stagify' );
		}

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
			'<a href="%s" class="stagify-link-danger stagify-confirm-link" data-confirm-title="%s" data-confirm-message="%s" data-confirm-label="%s" data-confirm-danger="1">%s</a>',
			esc_url( $url ),
			esc_attr__( 'Remove this task?', 'stagify' ),
			esc_attr__( 'This task and its history will be permanently deleted.', 'stagify' ),
			esc_attr__( 'Remove', 'stagify' ),
			$label
		);
	}

	/**
	 * Build the activate URL for a task.
	 *
	 * @param int $task_id Task ID.
	 * @return string URL string.
	 */
	private function activate_url( int $task_id ): string {
		return add_query_arg(
			array(
				'stagify_action' => 'activate',
				'task_id'        => $task_id,
				'_wpnonce'       => wp_create_nonce( 'stagify_task_action' ),
			),
			admin_url( 'admin.php?page=stagify' )
		);
	}

	/**
	 * Render the coloured status badge HTML.
	 *
	 * @param Task $item Current row task.
	 * @return string
	 */
	private function render_status_badge( Task $item ): string {
		$is_active = TaskStatus::Pending === $item->status && $item->id === $this->active_task_id;

		if ( $is_active ) {
			return '<span class="stagify-badge stagify-badge--active"><span class="stagify-pulse-dot"></span>' . esc_html__( 'Tracking', 'stagify' ) . '</span>';
		}

		return match ( $item->status ) {
			TaskStatus::Pending => '',
			TaskStatus::Pushing => '<span class="stagify-badge stagify-badge--pushing">' . esc_html__( 'Pushing…', 'stagify' ) . '</span>',
			TaskStatus::Pushed  => '<span class="stagify-badge stagify-badge--pushed">' . esc_html__( 'Pushed', 'stagify' ) . '</span>',
			TaskStatus::Failed  => '<span class="stagify-badge stagify-badge--failed">' . esc_html__( 'Failed', 'stagify' ) . '</span>',
		};
	}

	/**
	 * Render a status badge span.
	 *
	 * @param string $label     Badge label (already translated, will be escaped).
	 * @param string $color     CSS colour value.
	 * @param bool   $pulse     Whether to show a pulsing dot before the label.
	 * @return string
	 */
	private function badge( string $label, string $color, bool $pulse = false ): string {
		$dot = '';
		if ( $pulse ) {
			$dot = '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:currentColor;margin-right:5px;animation:stagify-pulse 1.5s infinite;"></span>';
		}

		return sprintf(
			'<span style="color:%s;font-weight:600;">%s%s</span>',
			esc_attr( $color ),
			$dot,
			esc_html( $label )
		);
	}
}
