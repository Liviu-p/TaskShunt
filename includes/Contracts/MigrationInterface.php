<?php
/**
 * Migration interface.
 *
 * @package Stagify\Contracts
 */

declare(strict_types=1);

namespace Stagify\Contracts;

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
