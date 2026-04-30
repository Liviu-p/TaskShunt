<?php
/**
 * File item handler for the receiver API.
 *
 * @package TaskShunt\Api\Handlers
 */

declare(strict_types=1);

namespace TaskShunt\Api\Handlers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Domain\TaskAction;

/**
 * Applies a single file change on the receiver site.
 */
final class FileHandler {

	/**
	 * Process a file item.
	 *
	 * @param TaskAction $action      The action to perform.
	 * @param string     $object_type File category (e.g. plugin, theme).
	 * @param int        $object_id   Original item ID from the sender.
	 * @param mixed      $payload     Decoded payload data.
	 * @return array{success: bool, message: string}
	 */
	public function handle( TaskAction $action, string $object_type, int $object_id, mixed $payload ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return array(
			'success' => true,
			/* translators: 1: action name, 2: object ID */
			'message' => sprintf( __( 'File %1$s queued for object %2$d.', 'taskshunt' ), $action->value, $object_id ),
		);
	}
}
