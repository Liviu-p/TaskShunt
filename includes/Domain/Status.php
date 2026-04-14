<?php
/**
 * Status enum.
 *
 * @package Stagify\Domain
 */

declare(strict_types=1);

namespace Stagify\Domain;

enum Status: string {

	case Draft     = 'draft';
	case Published = 'published';
	case Archived  = 'archived';

	/**
	 * Get the human-readable label for the status.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			Status::Draft     => __( 'Draft', 'stagify' ),
			Status::Published => __( 'Published', 'stagify' ),
			Status::Archived  => __( 'Archived', 'stagify' ),
		};
	}

	/**
	 * Whether the status is publicly visible.
	 *
	 * @return bool
	 */
	public function is_public(): bool {
		return match ( $this ) {
			Status::Published => true,
			default           => false,
		};
	}
}
