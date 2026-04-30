<?php
/**
 * ItemAdded domain event.
 *
 * @package TaskShunt\Events
 */

declare(strict_types=1);

namespace TaskShunt\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Domain\TaskAction;

/**
 * Fired after a task item is successfully added.
 */
final readonly class ItemAdded extends DomainEvent {

	/**
	 * Create the event.
	 *
	 * @param int        $task_id Task ID the item was added to.
	 * @param int        $item_id Newly created task item ID.
	 * @param int        $post_id WordPress post ID that triggered the item.
	 * @param TaskAction $action  Whether the post was created or updated.
	 */
	public function __construct(
		public int $task_id,
		public int $item_id,
		public int $post_id,
		public TaskAction $action,
	) {
		parent::__construct();
	}
}
