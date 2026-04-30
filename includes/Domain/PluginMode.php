<?php
/**
 * Plugin mode enum.
 *
 * @package TaskShunt\Domain
 */

declare(strict_types=1);

namespace TaskShunt\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The two sides of a TaskShunt deployment:
 *  Sender   — the staging site that tracks changes and pushes them out.
 *  Receiver — the production site that accepts pushes and applies the changes.
 *
 * Each WordPress install runs in exactly one mode (chosen on first activation).
 */
enum PluginMode: string {

	case Sender   = 'sender';
	case Receiver = 'receiver';

	/**
	 * Return a human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Sender   => __( 'Staging (Sender)', 'taskshunt' ),
			self::Receiver => __( 'Production (Receiver)', 'taskshunt' ),
		};
	}
}
