<?php
/**
 * Plugin mode enum.
 *
 * @package Stagify\Domain
 */

declare(strict_types=1);

namespace Stagify\Domain;

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
			self::Sender   => __( 'Staging (Sender)', 'stagify' ),
			self::Receiver => __( 'Production (Receiver)', 'stagify' ),
		};
	}
}
