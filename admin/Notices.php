<?php
/**
 * Admin notices queue.
 *
 * @package Stagify\Admin
 */

declare(strict_types=1);

namespace Stagify\Admin;

/**
 * Transient-based admin notice queue.
 *
 * Notices are stored in a short-lived transient so they survive a redirect,
 * then rendered and cleared on the next admin_notices hook.
 */
final class Notices {

	/**
	 * Transient key for the notice queue.
	 */
	private const TRANSIENT = 'stagify_admin_notices';

	/**
	 * Transient TTL in seconds (60 s — enough for a redirect).
	 */
	private const TTL = 60;

	/**
	 * Queue a notice for display after the next redirect.
	 *
	 * @param string $type    Notice type: success, error, warning, info.
	 * @param string $message Human-readable notice text.
	 * @return void
	 */
	public static function add( string $type, string $message ): void {
		$notices   = self::get_queue();
		$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
		set_transient( self::TRANSIENT, $notices, self::TTL );
	}

	/**
	 * Register the admin_notices hook to render and clear queued notices.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action(
			'admin_notices',
			array( self::class, 'render' )
		);
	}

	/**
	 * Render all queued notices and clear the transient.
	 *
	 * @return void
	 */
	public static function render(): void {
		$notices = self::get_queue();

		if ( empty( $notices ) ) {
			return;
		}

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}

		delete_transient( self::TRANSIENT );
	}

	/**
	 * Retrieve the current notice queue from the transient.
	 *
	 * @return array<int, array{type: string, message: string}>
	 */
	private static function get_queue(): array {
		$notices = get_transient( self::TRANSIENT );
		return is_array( $notices ) ? $notices : array();
	}
}
