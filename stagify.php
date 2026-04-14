<?php
/**
 * Plugin Name:       Stagify
 * Description:       Stagify WordPress Plugin.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Stagify
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stagify
 * Domain Path:       /languages
 *
 * @package Stagify
 */

declare(strict_types=1);

namespace Stagify;

use Stagify\Contracts\TaskRepositoryInterface;
use Stagify\Database\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STAGIFY_VERSION', '1.0.0' );
define( 'STAGIFY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'STAGIFY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'STAGIFY_PLUGIN_FILE', __FILE__ );

require_once STAGIFY_PLUGIN_DIR . 'vendor/autoload.php';

/**
 * On activation: run pending migrations and schedule the daily purge cron.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		( new Migrator() )->run();

		if ( ! wp_next_scheduled( 'stagify_purge_old_tasks' ) ) {
			wp_schedule_event( time(), 'daily', 'stagify_purge_old_tasks' );
		}
	}
);

/**
 * Bootstrap the plugin and register runtime hooks.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		$di_container = require STAGIFY_PLUGIN_DIR . 'includes/bootstrap.php';

		Container::set_instance( $di_container );

		/**
		 * WP-Cron: purge stale tasks once per day.
		 */
		add_action(
			'stagify_purge_old_tasks',
			static function () use ( $di_container ): void {
				$di_container->get( TaskRepositoryInterface::class )->purge_old();
			}
		);

		Plugin::get_instance( container: $di_container )->boot();
	}
);
