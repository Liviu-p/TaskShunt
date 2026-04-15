<?php
/**
 * Plugin Name:       Stagify
 * Description:       Staging-to-production content deployment for WordPress. Track changes on a staging site and push them to production via REST API.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Stagify
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stagify
 *
 * @package Stagify
 */

declare(strict_types=1);

namespace Stagify;

use Stagify\Container;
use Stagify\Contracts\TaskRepositoryInterface;
use Stagify\Database\Migrator;

// Prevent direct access outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin-wide constants used by all components.
define( 'STAGIFY_VERSION', '1.0.0' );
define( 'STAGIFY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );  // Absolute filesystem path to this plugin's directory.
define( 'STAGIFY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );   // Public URL to this plugin's directory (for enqueueing assets).
define( 'STAGIFY_PLUGIN_FILE', __FILE__ );                     // Full path to this file (needed by register_activation_hook).

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
 * Bootstrap the plugin on every page load.
 *
 * Plugins_loaded is the earliest hook where all plugins are available,
 * making it safe to build the DI container and wire everything together.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		// Build the PHP-DI container (see includes/bootstrap.php for all service definitions).
		$di_container = require STAGIFY_PLUGIN_DIR . 'includes/bootstrap.php';

		// Store the container globally so any component can resolve services.
		Container::set_instance( $di_container );

		/**
		 * WP-Cron callback: delete pushed tasks older than the configured retention period.
		 * The cron event itself is scheduled in the activation hook above.
		 */
		add_action(
			'stagify_purge_old_tasks',
			static function () use ( $di_container ): void {
				$di_container->get( TaskRepositoryInterface::class )->purge_old();
			}
		);

		// Boot the plugin — this decides whether to run in Sender, Receiver, or Setup mode.
		Plugin::get_instance( container: $di_container )->boot();
	}
);
