<?php
/**
 * WordPress Dashboard widget for Stagify.
 *
 * @package Stagify\Admin
 */

declare(strict_types=1);

namespace Stagify\Admin;

use Stagify\Contracts\ServerRepositoryInterface;
use Stagify\Contracts\TaskRepositoryInterface;

/**
 * Registers and renders a dashboard widget showing the active task status.
 */
final class DashboardWidget {

	/**
	 * Create the widget.
	 *
	 * @param TaskRepositoryInterface   $task_repository   Task repository.
	 * @param ServerRepositoryInterface $server_repository Server repository.
	 */
	public function __construct(
		private readonly TaskRepositoryInterface $task_repository,
		private readonly ServerRepositoryInterface $server_repository,
	) {}

	/**
	 * Register the dashboard widget.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'wp_dashboard_setup',
			function (): void {
				wp_add_dashboard_widget(
					'stagify_dashboard_widget',
					__( 'Stagify', 'stagify' ),
					function (): void {
						$this->render();
					}
				);
			}
		);

		add_action(
			'admin_enqueue_scripts',
			static function ( string $hook ): void {
				if ( 'index.php' !== $hook ) {
					return;
				}
				wp_enqueue_style(
					'stagify-admin',
					STAGIFY_PLUGIN_URL . 'assets/css/stagify-admin.css',
					array(),
					STAGIFY_VERSION
				);
			}
		);
	}

	/**
	 * Render the widget content.
	 *
	 * @return void
	 */
	private function render(): void {
		$active_task = $this->task_repository->find_active();
		$server      = $this->server_repository->find();
		$all_tasks   = $this->task_repository->find_all();

		echo '<div class="stagify-widget">';

		if ( null === $server ) {
			printf(
				'<div class="stagify-widget-empty">'
				. '<p>%s</p>'
				. '<a href="%s" class="button">%s</a>'
				. '</div>',
				esc_html__( 'Connect a production server to start pushing changes.', 'stagify' ),
				esc_url( admin_url( 'admin.php?page=stagify-settings' ) ),
				esc_html__( 'Set up server', 'stagify' )
			);
			echo '</div>';
			return;
		}

		if ( null !== $active_task ) {
			$this->render_active_task( $active_task );
		} else {
			$this->render_no_active();
		}

		$this->render_quick_stats( $all_tasks );

		echo '</div>';
	}

	/**
	 * Render the active task section.
	 *
	 * @param \Stagify\Domain\Task $task Active task.
	 * @return void
	 */
	private function render_active_task( \Stagify\Domain\Task $task ): void {
		$detail_url = admin_url( 'admin.php?page=stagify&action=view&task_id=' . $task->id );

		printf(
			'<div class="stagify-widget-active">'
			. '<div class="stagify-widget-row">'
			. '<span class="stagify-pulse-dot"></span>'
			. '<div>'
			. '<strong><a href="%s">%s</a></strong>'
			. '<span class="stagify-widget-meta">%s</span>'
			. '</div>'
			. '</div>',
			esc_url( $detail_url ),
			esc_html( $task->title ),
			esc_html(
				sprintf(
					/* translators: %d: number of changes */
					_n( '%d change tracked', '%d changes tracked', $task->item_count, 'stagify' ),
					$task->item_count
				) 
			)
		);

		if ( $task->item_count > 0 ) {
			printf(
				'<a href="#" class="button stagify-push-btn stagify-widget-push" data-task-id="%d">%s</a>',
				(int) $task->id,
				esc_html__( 'Push to production', 'stagify' )
			);
		}

		echo '</div>';
	}

	/**
	 * Render the "no active task" section.
	 *
	 * @return void
	 */
	private function render_no_active(): void {
		printf(
			'<div class="stagify-widget-empty">'
			. '<p>%s</p>'
			. '<a href="%s" class="button">%s</a>'
			. '</div>',
			esc_html__( 'No active task. Create one to start tracking changes.', 'stagify' ),
			esc_url( admin_url( 'admin.php?page=stagify' ) ),
			esc_html__( 'Go to Tasks', 'stagify' )
		);
	}

	/**
	 * Render quick stats — pushed/pending counts.
	 *
	 * @param array<int, \Stagify\Domain\Task> $tasks All tasks.
	 * @return void
	 */
	private function render_quick_stats( array $tasks ): void {
		$pushed  = 0;
		$pending = 0;
		foreach ( $tasks as $task ) {
			if ( \Stagify\Domain\TaskStatus::Pushed === $task->status ) {
				++$pushed;
			}
			if ( \Stagify\Domain\TaskStatus::Pending === $task->status ) {
				++$pending;
			}
		}

		printf(
			'<div class="stagify-widget-stats">'
			. '<div class="stagify-widget-stat"><span>%d</span> %s</div>'
			. '<div class="stagify-widget-stat"><span>%d</span> %s</div>'
			. '</div>',
			(int) $pushed,
			esc_html__( 'pushed', 'stagify' ),
			(int) $pending,
			esc_html__( 'pending', 'stagify' )
		);
	}
}
