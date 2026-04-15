<?php
/**
 * File snapshot entity.
 *
 * @package Stagify\Domain
 */

declare(strict_types=1);

namespace Stagify\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents a point-in-time snapshot of a single tracked file.
 *
 * Pure data object — no DB access. Hydrate via FileSnapshot::from_db_row().
 */
final readonly class FileSnapshot {

	/**
	 * MySQL datetime format used by from_db_row.
	 */
	private const DATETIME_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Create a FileSnapshot entity.
	 *
	 * @param int                $id            Snapshot ID.
	 * @param RelativePath       $relative_path Relative path of the tracked file.
	 * @param string             $file_hash     Content hash (e.g. sha256) of the file.
	 * @param int                $file_size     File size in bytes.
	 * @param \DateTimeImmutable $scanned_at    When this snapshot was recorded.
	 */
	public function __construct(
		public int $id,
		public RelativePath $relative_path,
		public string $file_hash,
		public int $file_size,
		public \DateTimeImmutable $scanned_at,
	) {}

	/**
	 * Hydrate a FileSnapshot from a raw database row.
	 *
	 * @param array<string, mixed> $row Associative row from wpdb.
	 * @return self
	 */
	public static function from_db_row( array $row ): self {
		return new self(
			id: (int) $row['id'],
			relative_path: new RelativePath( (string) $row['path'] ),
			file_hash: (string) $row['hash'],
			file_size: (int) $row['file_size'],
			scanned_at: self::parse_datetime( (string) $row['scanned_at'] ),
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
