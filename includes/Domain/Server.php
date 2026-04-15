<?php
/**
 * Server entity.
 *
 * @package Stagify\Domain
 */

declare(strict_types=1);

namespace Stagify\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The remote server that this sender pushes tasks to (i.e. the production site).
 *
 * Only one server can be configured at a time. Stores the URL and API key needed
 * to authenticate with the receiver's REST endpoint.
 *
 * Pure data object — no DB access. Hydrate via Server::from_db_row().
 */
final readonly class Server {

	/**
	 * MySQL datetime format used by from_db_row.
	 */
	private const DATETIME_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Create a Server entity.
	 *
	 * @param int                $id         Server ID.
	 * @param string             $name       Human-readable name.
	 * @param ServerUrl          $url        Validated server URL.
	 * @param ApiKey             $api_key    Validated API key.
	 * @param \DateTimeImmutable $created_at When the server was registered.
	 */
	public function __construct(
		public int $id,
		public string $name,
		public ServerUrl $url,
		public ApiKey $api_key,
		public \DateTimeImmutable $created_at,
	) {}

	/**
	 * Hydrate a Server from a raw database row.
	 *
	 * @param array<string, mixed> $row Associative row from wpdb.
	 * @return self
	 */
	public static function from_db_row( array $row ): self {
		return new self(
			id: (int) $row['id'],
			name: (string) $row['name'],
			url: new ServerUrl( (string) $row['url'] ),
			api_key: new ApiKey( (string) $row['api_key'] ),
			created_at: self::parse_datetime( (string) $row['created_at'] ),
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
