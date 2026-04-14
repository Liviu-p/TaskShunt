<?php
/**
 * Database migrator.
 *
 * @package Stagify\Database
 */

declare(strict_types=1);

namespace Stagify\Database;

use Stagify\Contracts\MigrationInterface;
use Stagify\Database\Migrations\Migration100;

/**
 * Runs pending database migrations in version order.
 */
final class Migrator {

	/**
	 * The target database version for this release.
	 *
	 * @var string
	 */
	private readonly string $current_version;

	/**
	 * Ordered map of version string => migration class.
	 *
	 * @var array<string, class-string<MigrationInterface>>
	 */
	private const MIGRATIONS = array(
		'1.0.0' => Migration100::class,
	);

	/**
	 * Option key used to persist the installed schema version.
	 */
	private const VERSION_OPTION = 'stagify_db_version';

	/**
	 * Create a Migrator.
	 *
	 * @param string $current_version Target schema version. Defaults to the current release.
	 */
	public function __construct( string $current_version = '1.0.0' ) {
		$this->current_version = $current_version;
	}

	/**
	 * Run all migrations newer than the stored version, then update the stored version.
	 *
	 * @return void
	 */
	public function run(): void {
		$stored_version = get_option( self::VERSION_OPTION, '0.0.0' );

		foreach ( self::MIGRATIONS as $version => $class ) {
			if ( version_compare( (string) $stored_version, $version, '<' ) ) {
				/**
				 * Resolved migration instance.
				 *
				 * @var MigrationInterface $migration
				 */
				$migration = new $class();
				$migration->up();
			}
		}

		update_option( self::VERSION_OPTION, $this->current_version );
	}
}
