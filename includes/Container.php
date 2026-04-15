<?php
/**
 * Global container accessor.
 *
 * @package Stagify
 */

declare(strict_types=1);

namespace Stagify;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DI\Container as DIContainer;

/**
 * Provides global read access to the DI container after bootstrap.
 */
final class Container {

	/**
	 * The underlying DI container instance.
	 *
	 * @var DIContainer|null
	 */
	private static ?DIContainer $instance = null;

	/**
	 * Return the configured DI container.
	 *
	 * @return DIContainer
	 *
	 * @throws \LogicException If called before the container is initialised.
	 */
	public static function get_instance(): DIContainer {
		if ( null === self::$instance ) {
			throw new \LogicException( 'Container has not been initialised. Call Container::set_instance() first.' );
		}

		return self::$instance;
	}

	/**
	 * Store the bootstrapped container. Called once from bootstrap.
	 *
	 * @param DIContainer $container The configured DI container.
	 * @return void
	 */
	public static function set_instance( DIContainer $container ): void {
		self::$instance = $container;
	}
}
