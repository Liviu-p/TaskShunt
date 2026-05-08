<?php
/**
 * Server repository interface.
 *
 * @package TaskShunt\Contracts
 */

declare(strict_types=1);

namespace TaskShunt\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Domain\ApiKey;
use TaskShunt\Domain\Server;
use TaskShunt\Domain\ServerUrl;

interface ServerRepositoryInterface {

	/**
	 * Return the configured server, or null if none has been saved.
	 *
	 * @return Server|null
	 */
	public function find(): ?Server;

	/**
	 * Persist the server configuration. Acts as an upsert — if a record already
	 * exists it is overwritten, preserving the 1-server-only invariant while
	 * letting users reconfigure in place. Returns the row ID, or false on a
	 * database error.
	 *
	 * @param string    $name    Human-readable server name.
	 * @param ServerUrl $url     Validated server URL.
	 * @param ApiKey    $api_key Validated API key.
	 * @return int|false The server ID on success, false on database failure.
	 */
	public function save( string $name, ServerUrl $url, ApiKey $api_key ): int|false;

	/**
	 * Delete the server configuration by ID.
	 *
	 * @param int $id Server ID.
	 * @return void
	 */
	public function delete( int $id ): void;

	/**
	 * Whether any server row exists, regardless of whether find() can hydrate it.
	 * Used to detect orphaned rows (e.g. when the at-rest key cannot be decrypted).
	 *
	 * @return bool
	 */
	public function has_record(): bool;

	/**
	 * Wipe every server row. Used by the "Reset connection" admin path when the
	 * configured row exists but cannot be decrypted, so a user can recover
	 * without direct DB access.
	 *
	 * @return void
	 */
	public function delete_all(): void;
}
