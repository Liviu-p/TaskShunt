<?php
/**
 * WordPress Dashboard widget for TaskShunt.
 *
 * @package TaskShunt\Admin
 */

declare(strict_types=1);

namespace TaskShunt\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Contracts\ServerRepositoryInterface;
use TaskShunt\Contracts\TaskRepositoryInterface;

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
					'taskshunt_dashboard_widget',
					__( 'TaskShunt', 'taskshunt' ),
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
					'taskshunt-admin',
					TASKSHUNT_PLUGIN_URL . 'assets/css/taskshunt-admin.css',
					array(),
					TASKSHUNT_VERSION
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

		echo '<div class="taskshunt-widget">';

		if ( null === $server ) {
			printf(
				'<div class="taskshunt-widget-empty">'
				. '<p>%s</p>'
				. '<a href="%s" class="button">%s</a>'
				. '</div>',
				esc_html__( 'Connect a production server to start pushing changes.', 'taskshunt' ),
				esc_url( admin_url( 'admin.php?page=taskshunt-settings' ) ),
				esc_html__( 'Set up server', 'taskshunt' )
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
	 * @param \TaskShunt\Domain\Task $task Active task.
	 * @return void
	 */
	private function render_active_task( \TaskShunt\Domain\Task $task ): void {
		$detail_url = admin_url( 'admin.php?page=taskshunt&action=view&task_id=' . $task->id );

		printf(
			'<div class="taskshunt-widget-active">'
			. '<div class="taskshunt-widget-row">'
			. '<span class="taskshunt-pulse-dot"></span>'
			. '<div>'
			. '<strong><a href="%s">%s</a></strong>'
			. '<span class="taskshunt-widget-meta">%s</span>'
			. '</div>'
			. '</div>',
			esc_url( $detail_url ),
			esc_html( $task->title ),
			esc_html(
				sprintf(
					/* translators: %d: number of changes */
					_n( '%d change tracked', '%d changes tracked', $task->item_count, 'taskshunt' ),
					$task->item_count
				) 
			)
		);

		if ( $task->item_count > 0 ) {
			printf(
				'<a href="#" class="button taskshunt-push-btn taskshunt-widget-push" data-task-id="%d">%s</a>',
				(int) $task->id,
				esc_html__( 'Push to production', 'taskshunt' )
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
			'<div class="taskshunt-widget-empty">'
			. '<p>%s</p>'
			. '<a href="%s" class="button">%s</a>'
			. '</div>',
			esc_html__( 'No active task. Create one to start tracking changes.', 'taskshunt' ),
			esc_url( admin_url( 'admin.php?page=taskshunt' ) ),
			esc_html__( 'Go to Tasks', 'taskshunt' )
		);
	}

	/**
	 * Render quick stats — pushed/pending counts.
	 *
	 * @param array<int, \TaskShunt\Domain\Task> $tasks All tasks.
	 * @return void
	 */
	private function render_quick_stats( array $tasks ): void {
		$pushed  = 0;
		$pending = 0;
		foreach ( $tasks as $task ) {
			if ( \TaskShunt\Domain\TaskStatus::Pushed === $task->status ) {
				++$pushed;
			}
			if ( \TaskShunt\Domain\TaskStatus::Pending === $task->status ) {
				++$pending;
			}
		}

		printf(
			'<div class="taskshunt-widget-stats">'
			. '<div class="taskshunt-widget-stat"><span>%d</span> %s</div>'
			. '<div class="taskshunt-widget-stat"><span>%d</span> %s</div>'
			. '</div>',
			(int) $pushed,
			esc_html__( 'pushed', 'taskshunt' ),
			(int) $pending,
			esc_html__( 'pending', 'taskshunt' )
		);
	}
}
