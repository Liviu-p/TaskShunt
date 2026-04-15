<?php
/**
 * Task status enum.
 *
 * @package Stagify\Domain
 */

declare(strict_types=1);

namespace Stagify\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lifecycle of a task:
 *  Pending → the task is open and collecting changes (only pending tasks can be active).
 *  Pushing → the task is currently being sent to the receiver (in-flight HTTP request).
 *  Pushed  → all items were delivered successfully — task is done.
 *  Failed  → the push failed (network error, server error, etc.) — can be retried.
 */
enum TaskStatus: string {

	case Pending = 'pending';
	case Pushing = 'pushing';
	case Pushed  = 'pushed';
	case Failed  = 'failed';
}
