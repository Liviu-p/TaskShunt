<?php
/**
 * File snapshot repository interface.
 *
 * @package Stagify\Contracts
 */

declare(strict_types=1);

namespace Stagify\Contracts;

use Stagify\Domain\RelativePath;

interface FileSnapshotRepositoryInterface {

	/**
	 * Return the stored hash for a file path, or null if not yet snapshotted.
	 *
	 * @param RelativePath $path The relative path of the file.
	 * @return string|null
	 */
	public function get_hash( RelativePath $path ): ?string;

	/**
	 * Insert or update the hash for a file path.
	 *
	 * @param RelativePath $path The relative path of the file.
	 * @param string       $hash The content hash (e.g. sha256).
	 * @return void
	 */
	public function upsert_hash( RelativePath $path, string $hash ): void;

	/**
	 * Remove the snapshot for a single file path.
	 *
	 * @param RelativePath $path The relative path to remove.
	 * @return void
	 */
	public function delete_hash( RelativePath $path ): void;

	/**
	 * Return all stored relative path strings.
	 *
	 * @return list<string>
	 */
	public function get_all_paths(): array;

	/**
	 * Delete all stored file snapshots.
	 *
	 * @return void
	 */
	public function delete_all(): void;
}
