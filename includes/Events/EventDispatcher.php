<?php
/**
 * Event dispatcher implementation.
 *
 * @package Stagify\Events
 */

declare(strict_types=1);

namespace Stagify\Events;

use Stagify\Contracts\EventDispatcherInterface;

/**
 * Simple in-process event dispatcher.
 */
final class EventDispatcher implements EventDispatcherInterface {

	/**
	 * Registered event listeners keyed by event class name.
	 *
	 * @var array<string, list<callable>>
	 */
	private array $listeners = array();

	/**
	 * Register a listener for an event class.
	 *
	 * @param string   $event_class Fully-qualified event class name.
	 * @param callable $listener    Listener callback invoked with the event object.
	 * @return void
	 */
	public function add_listener( string $event_class, callable $listener ): void {
		$this->listeners[ $event_class ][] = $listener;
	}

	/**
	 * Dispatch an event to all registered listeners.
	 *
	 * @param object $event The event object to dispatch.
	 * @return void
	 */
	public function dispatch( object $event ): void {
		foreach ( $this->listeners[ $event::class ] ?? array() as $listener ) {
			$listener( $event );
		}
	}
}
