<?php
/**
 * Unit tests for the Task entity.
 *
 * @package Stagify\Tests\Unit
 */

declare(strict_types=1);

namespace Stagify\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Stagify\Domain\Task;
use Stagify\Domain\TaskStatus;

/**
 * Tests Task entity construction, hydration, and status helpers.
 */
final class TaskTest extends TestCase {

	/**
	 * Set up Brain\Monkey before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tear down Brain\Monkey after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Constructor should set all readonly properties correctly.
	 *
	 * @return void
	 */
	public function test_constructor_sets_readonly_properties(): void {
		$now  = new \DateTimeImmutable();
		$task = new Task(
			id: 1,
			title: 'Deploy homepage',
			status: TaskStatus::Pending,
			item_count: 5,
			created_at: $now,
			pushed_at: null,
		);

		$this->assertSame( expected: 1, actual: $task->id );
		$this->assertSame( expected: 'Deploy homepage', actual: $task->title );
		$this->assertSame( expected: TaskStatus::Pending, actual: $task->status );
		$this->assertSame( expected: 5, actual: $task->item_count );
		$this->assertSame( expected: $now, actual: $task->created_at );
		$this->assertNull( actual: $task->pushed_at );
	}

	/**
	 * From_db_row should hydrate all fields including pushed_at.
	 *
	 * @return void
	 */
	public function test_from_db_row_hydrates_all_fields(): void {
		$row = array(
			'id'         => '42',
			'title'      => 'Content sync',
			'status'     => 'pushing',
			'item_count' => '10',
			'created_at' => '2025-06-15 14:30:00',
			'pushed_at'  => '2025-06-15 15:00:00',
		);

		$task = Task::from_db_row( $row );

		$this->assertSame( expected: 42, actual: $task->id );
		$this->assertSame( expected: 'Content sync', actual: $task->title );
		$this->assertSame( expected: TaskStatus::Pushing, actual: $task->status );
		$this->assertSame( expected: 10, actual: $task->item_count );
		$this->assertSame( expected: '2025-06-15 14:30:00', actual: $task->created_at->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( expected: '2025-06-15 15:00:00', actual: $task->pushed_at->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * From_db_row should leave pushed_at null when the DB value is null.
	 *
	 * @return void
	 */
	public function test_from_db_row_handles_null_pushed_at(): void {
		$row = array(
			'id'         => '1',
			'title'      => 'Test',
			'status'     => 'pending',
			'item_count' => '0',
			'created_at' => '2025-01-01 00:00:00',
			'pushed_at'  => null,
		);

		$task = Task::from_db_row( $row );

		$this->assertNull( actual: $task->pushed_at );
	}

	/**
	 * From_db_row should leave pushed_at null when the DB value is empty string.
	 *
	 * @return void
	 */
	public function test_from_db_row_handles_empty_pushed_at(): void {
		$row = array(
			'id'         => '1',
			'title'      => 'Test',
			'status'     => 'pending',
			'item_count' => '0',
			'created_at' => '2025-01-01 00:00:00',
			'pushed_at'  => '',
		);

		$task = Task::from_db_row( $row );

		$this->assertNull( actual: $task->pushed_at );
	}

	/**
	 * Is_active should return true for Pending status.
	 *
	 * @return void
	 */
	public function test_is_active_returns_true_for_pending(): void {
		$task = $this->make_task( status: TaskStatus::Pending );

		$this->assertTrue( condition: $task->is_active() );
	}

	/**
	 * Is_active should return false for non-Pending statuses.
	 *
	 * @return void
	 */
	public function test_is_active_returns_false_for_non_pending(): void {
		$this->assertFalse( condition: $this->make_task( status: TaskStatus::Pushed )->is_active() );
		$this->assertFalse( condition: $this->make_task( status: TaskStatus::Failed )->is_active() );
		$this->assertFalse( condition: $this->make_task( status: TaskStatus::Pushing )->is_active() );
	}

	/**
	 * Is_pushing should return true only for Pushing status.
	 *
	 * @return void
	 */
	public function test_is_pushing_returns_true_only_for_pushing(): void {
		$this->assertTrue( condition: $this->make_task( status: TaskStatus::Pushing )->is_pushing() );
		$this->assertFalse( condition: $this->make_task( status: TaskStatus::Pending )->is_pushing() );
	}

	/**
	 * Helper to create a Task with a given status and sensible defaults.
	 *
	 * @param TaskStatus $status Task status to use.
	 * @return Task
	 */
	private function make_task( TaskStatus $status ): Task {
		return new Task(
			id: 1,
			title: 'Test task',
			status: $status,
			item_count: 0,
			created_at: new \DateTimeImmutable(),
			pushed_at: null,
		);
	}
}
