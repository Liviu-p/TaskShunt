<?php
/**
 * Server repository interface.
 *
 * @package Stagify\Contracts
 */

declare(strict_types=1);

namespace Stagify\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stagify\Domain\ApiKey;
use Stagify\Domain\Server;
use Stagify\Domain\ServerUrl;

interface ServerRepositoryInterface {

	/**
	 * Return the configured server, or null if none has been saved.
	 *
	 * @return Server|null
	 */
	public function find(): ?Server;

	/**
	 * Persist the server configuration and return its ID, or false on failure.
	 *
	 * @param string    $name    Human-readable server name.
	 * @param ServerUrl $url     Validated server URL.
	 * @param ApiKey    $api_key Validated API key.
	 * @return int|false The server ID on success, false on failure.
	 */
	public function save( string $name, ServerUrl $url, ApiKey $api_key ): int|false;

	/**
	 * Delete the server configuration by ID.
	 *
	 * @param int $id Server ID.
	 * @return void
	 */
	public function delete( int $id ): void;
}
