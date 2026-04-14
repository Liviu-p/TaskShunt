<?php
/**
 * Server repository.
 *
 * @package Stagify\Repository
 */

declare(strict_types=1);

namespace Stagify\Repository;

use Stagify\Contracts\ServerRepositoryInterface;
use Stagify\Domain\ApiKey;
use Stagify\Domain\Server;
use Stagify\Domain\ServerUrl;

/**
 * Persists and retrieves the single server configuration (1-server limit).
 */
final class ServerRepository implements ServerRepositoryInterface {

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
		$this->table = $wpdb->prefix . 'stagify_servers';
	}

	/**
	 * Return the configured server, or null if none exists.
	 *
	 * @return Server|null
	 */
	public function find(): ?Server {
		$row = $this->wpdb->get_row( "SELECT * FROM `{$this->table}` ORDER BY id ASC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? Server::from_db_row( $row ) : null;
	}

	/**
	 * Persist a server configuration, enforcing a 1-server limit.
	 *
	 * Returns false if a server record already exists.
	 *
	 * @param string    $name    Human-readable server name.
	 * @param ServerUrl $url     Validated server URL.
	 * @param ApiKey    $api_key Validated API key.
	 * @return int|false Inserted server ID, or false if limit reached.
	 */
	public function save( string $name, ServerUrl $url, ApiKey $api_key ): int|false {
		$exists = $this->wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( (int) $exists > 0 ) {
			return false;
		}

		$this->wpdb->insert(
			$this->table,
			array(
				'name'       => $name,
				'url'        => $url->get_value(),
				'api_key'    => $api_key->get_value(),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		$inserted_id = (int) $this->wpdb->insert_id;
		return $inserted_id > 0 ? $inserted_id : false;
	}

	/**
	 * Delete a server record by ID.
	 *
	 * @param int $id Server ID.
	 * @return void
	 */
	public function delete( int $id ): void {
		$this->wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
	}
}
