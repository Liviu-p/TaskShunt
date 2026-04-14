<?php
/**
 * Task entity.
 *
 * @package Stagify\Domain
 */

declare(strict_types=1);

namespace Stagify\Domain;

/**
 * Represents a staging task.
 *
 * Pure data object — no DB access. Hydrate via Task::from_db_row().
 */
final readonly class Task {

	/**
	 * MySQL datetime format used by fromDbRow.
	 */
	private const DATETIME_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Create a Task entity.
	 *
	 * @param int                     $id         Task ID.
	 * @param string                  $title      Human-readable title.
	 * @param TaskStatus              $status     Current processing status.
	 * @param int                     $item_count Number of items attached to this task.
	 * @param \DateTimeImmutable      $created_at When the task was created.
	 * @param \DateTimeImmutable|null $pushed_at When the task was pushed, or null if not yet pushed.
	 */
	public function __construct(
		public int $id,
		public string $title,
		public TaskStatus $status,
		public int $item_count,
		public \DateTimeImmutable $created_at,
		public ?\DateTimeImmutable $pushed_at,
	) {}

	/**
	 * Hydrate a Task from a raw database row.
	 *
	 * @param array<string, mixed> $row Associative row from wpdb.
	 * @return self
	 */
	public static function from_db_row( array $row ): self {
		return new self(
			id: (int) $row['id'],
			title: (string) $row['title'],
			status: TaskStatus::from( (string) $row['status'] ),
			item_count: (int) $row['item_count'],
			created_at: self::parse_datetime( (string) $row['created_at'] ),
			pushed_at: match ( true ) {
				! empty( $row['pushed_at'] ) => self::parse_datetime( (string) $row['pushed_at'] ),
				default                      => null,
			},
		);
	}

	/**
	 * Parse a MySQL datetime string into a DateTimeImmutable, falling back to now on failure.
	 *
	 * @param string $value MySQL datetime string.
	 * @return \DateTimeImmutable
	 */
	private static function parse_datetime( string $value ): \DateTimeImmutable {
		$dt = \DateTimeImmutable::createFromFormat( self::DATETIME_FORMAT, $value );
		return false !== $dt ? $dt : new \DateTimeImmutable();
	}

	/**
	 * Whether the task is in an editable, pre-push state.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return TaskStatus::Pending === $this->status;
	}

	/**
	 * Whether the task is currently being pushed to the remote server.
	 *
	 * @return bool
	 */
	public function is_pushing(): bool {
		return TaskStatus::Pushing === $this->status;
	}
}
