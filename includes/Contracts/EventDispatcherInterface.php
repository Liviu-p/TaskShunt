<?php
/**
 * Event dispatcher interface.
 *
 * @package Stagify\Contracts
 */

declare(strict_types=1);

namespace Stagify\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface EventDispatcherInterface {

	/**
	 * Register a listener for an event class.
	 *
	 * @param string   $event_class Fully-qualified event class name.
	 * @param callable $listener    Listener callback invoked with the event object.
	 * @return void
	 */
	public function add_listener( string $event_class, callable $listener ): void;

	/**
	 * Dispatch an event to all registered listeners.
	 *
	 * @param object $event The event object to dispatch.
	 * @return void
	 */
	public function dispatch( object $event ): void;
}
