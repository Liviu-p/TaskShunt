<?php
/**
 * Server repository.
 *
 * @package TaskShunt\Repository
 */

declare(strict_types=1);

namespace TaskShunt\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaskShunt\Contracts\ServerRepositoryInterface;
use TaskShunt\Domain\ApiKey;
use TaskShunt\Domain\Server;
use TaskShunt\Domain\ServerUrl;
use TaskShunt\Services\Crypto;

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
		$this->table = $wpdb->prefix . 'taskshunt_servers';
	}

	/**
	 * Return the configured server, or null if none exists.
	 *
	 * @return Server|null
	 */
	public function find(): ?Server {
		$row = $this->wpdb->get_row( "SELECT * FROM `{$this->table}` ORDER BY id ASC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) ) {
			return null;
		}

		try {
			$row['api_key'] = Crypto::decrypt( (string) $row['api_key'] );
		} catch ( \Throwable $e ) {
			return null;
		}

		return Server::from_db_row( $row );
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
		$existing_id = $this->wpdb->get_var( "SELECT id FROM `{$this->table}` ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$payload = array(
			'name'    => $name,
			'url'     => $url->get_value(),
			'api_key' => Crypto::encrypt( $api_key->get_value() ),
		);

		if ( null !== $existing_id ) {
			$updated = $this->wpdb->update(
				$this->table,
				$payload,
				array( 'id' => (int) $existing_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			return false === $updated ? false : (int) $existing_id;
		}

		$payload['created_at'] = gmdate( 'Y-m-d H:i:s' );
		$this->wpdb->insert(
			$this->table,
			$payload,
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

	/**
	 * Whether any server row exists in the table.
	 *
	 * @return bool
	 */
	public function has_record(): bool {
		$count = $this->wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $count > 0;
	}

	/**
	 * Truncate every row in the servers table.
	 *
	 * @return void
	 */
	public function delete_all(): void {
		$this->wpdb->query( "DELETE FROM `{$this->table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
