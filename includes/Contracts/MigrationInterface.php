<?php
/**
 * Migration interface.
 *
 * @package TaskShunt\Contracts
 */

declare(strict_types=1);

namespace TaskShunt\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface MigrationInterface {

	/**
	 * Run the migration.
	 *
	 * @return void
	 */
	public function up(): void;
}
