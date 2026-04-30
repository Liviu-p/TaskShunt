<?php
/**
 * Task item entity.
 *
 * @package TaskShunt\Domain
 */

declare(strict_types=1);

namespace TaskShunt\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A TaskItem is one individual change inside a task.
 *
 * Examples:
 *  - "Post 'About Us' was updated"   → type=Content, action=Update, object_type=page
 *  - "Plugin WooCommerce activated"   → type=Environment, action=Update, object_type=plugin
 *  - "theme/style.css was modified"   → type=File, action=Update, object_type=file
 *
 * The payload field holds the serialized data needed to replay this change on the receiver
 * (e.g. full post content + meta for a content item, or the plugin slug for an environment item).
 *
 * Pure data object — no DB access. Hydrate via TaskItem::from_db_row().
 */
final readonly class TaskItem {

	/**
	 * MySQL datetime format used by from_db_row.
	 */
	private const DATETIME_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Create a TaskItem entity.
	 *
	 * @param int                     $id           Item ID.
	 * @param int                     $task_id      ID of the owning task.
	 * @param TaskItemType            $type         Category of the item.
	 * @param TaskAction              $action       The action to perform.
	 * @param string                  $object_type  WordPress object type (e.g. post, option).
	 * @param string                  $object_id    Identifier within the object type.
	 * @param string                  $payload      Serialised data required to replay the action.
	 * @param TaskStatus              $status       Current processing status.
	 * @param \DateTimeImmutable|null $pushed_at   When this item was pushed, or null if pending.
	 * @param string|null             $response_log Response body from the remote server, if any.
	 */
	public function __construct(
		public int $id,
		public int $task_id,
		public TaskItemType $type,
		public TaskAction $action,
		public string $object_type,
		public string $object_id,
		public string $payload,
		public TaskStatus $status,
		public ?\DateTimeImmutable $pushed_at,
		public ?string $response_log,
	) {}

	/**
	 * Hydrate a TaskItem from a raw database row.
	 *
	 * @param array<string, mixed> $row Associative row from wpdb.
	 * @return self
	 */
	public static function from_db_row( array $row ): self {
		return new self(
			id: (int) $row['id'],
			task_id: (int) $row['task_id'],
			type: TaskItemType::from( (string) $row['type'] ),
			action: TaskAction::from( (string) $row['action'] ),
			object_type: (string) $row['object_type'],
			object_id: (string) $row['object_id'],
			payload: (string) $row['payload'],
			status: TaskStatus::from( (string) $row['status'] ),
			pushed_at: match ( true ) {
				! empty( $row['pushed_at'] ) => self::parse_datetime( (string) $row['pushed_at'] ),
				default                      => null,
			},
			response_log: isset( $row['response_log'] ) ? (string) $row['response_log'] : null,
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
}
