<?php
/**
 * File snapshot repository.
 *
 * @package Stagify\Repository
 */

declare(strict_types=1);

namespace Stagify\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stagify\Contracts\FileSnapshotRepositoryInterface;
use Stagify\Domain\RelativePath;

/**
 * Persists and retrieves file hash snapshots using a custom DB table.
 */
final class FileSnapshotRepository implements FileSnapshotRepositoryInterface {

	/**
	 * Fully-qualified table name (with prefix).
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Create the repository.
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 */
	public function __construct( private readonly \wpdb $wpdb ) {
		$this->table = $wpdb->prefix . 'stagify_file_snapshots';
	}

	/**
	 * Return the stored hash for a file path, or null if not yet snapshotted.
	 *
	 * @param RelativePath $path The relative file path.
	 * @return string|null
	 */
	public function get_hash( RelativePath $path ): ?string {
		$hash = $this->wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is safe, built from $wpdb->prefix.
			$this->wpdb->prepare( 'SELECT hash FROM `' . $this->table . '` WHERE path = %s LIMIT 1', $path->get_value() )
		);
		return is_string( $hash ) ? $hash : null;
	}

	/**
	 * Insert or update the hash for a file path via the path UNIQUE index.
	 *
	 * @param RelativePath $path The relative file path.
	 * @param string       $hash Content hash (e.g. sha256).
	 * @return void
	 */
	public function upsert_hash( RelativePath $path, string $hash ): void {
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table name is safe, built from $wpdb->prefix.
		$this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT INTO `' . $this->table . '` (path, hash, file_size, scanned_at) VALUES (%s, %s, 0, %s) ON DUPLICATE KEY UPDATE hash = %s, scanned_at = %s',
				$path->get_value(),
				$hash,
				$now,
				$hash,
				$now
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Remove the snapshot for a single file path.
	 *
	 * @param RelativePath $path The relative path to remove.
	 * @return void
	 */
	public function delete_hash( RelativePath $path ): void {
		$this->wpdb->delete( $this->table, array( 'path' => $path->get_value() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Return all stored relative path strings.
	 *
	 * @return list<string>
	 */
	public function get_all_paths(): array {
		$results = $this->wpdb->get_col( 'SELECT path FROM `' . $this->table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is safe, built from $wpdb->prefix.
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Delete all stored file snapshots.
	 *
	 * @return void
	 */
	public function delete_all(): void {
		$this->wpdb->query( 'DELETE FROM `' . $this->table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is safe, built from $wpdb->prefix.
	}
}
