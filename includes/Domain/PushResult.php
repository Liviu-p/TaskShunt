<?php
/**
 * Push result value object.
 *
 * @package TaskShunt\Domain
 */

declare(strict_types=1);

namespace TaskShunt\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable result of a push operation.
 */
final readonly class PushResult {

	/**
	 * Create a push result.
	 *
	 * @param bool   $success   Whether the push succeeded.
	 * @param int    $http_code HTTP response code (0 if no response).
	 * @param string $message   Human-readable result message.
	 */
	public function __construct(
		public bool $success,
		public int $http_code,
		public string $message,
	) {}
}
