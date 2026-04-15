<?php
/**
 * Repository interface.
 *
 * @package Stagify\Contracts
 */

declare(strict_types=1);

namespace Stagify\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface RepositoryInterface {

	/**
	 * Find an entity by ID.
	 *
	 * @param int $id The entity ID.
	 * @return mixed
	 */
	public function find( int $id ): mixed;

	/**
	 * Find all entities.
	 *
	 * @return array<int, mixed>
	 */
	public function find_all(): array;

	/**
	 * Save an entity.
	 *
	 * @param mixed $entity The entity to save.
	 * @return void
	 */
	public function save( mixed $entity ): void;

	/**
	 * Delete an entity by ID.
	 *
	 * @param int $id The entity ID.
	 * @return void
	 */
	public function delete( int $id ): void;
}
