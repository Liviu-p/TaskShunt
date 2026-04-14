<?php
/**
 * Task status enum.
 *
 * @package Stagify\Domain
 */

declare(strict_types=1);

namespace Stagify\Domain;

enum TaskStatus: string {

	case Pending = 'pending';
	case Pushing = 'pushing';
	case Pushed  = 'pushed';
	case Failed  = 'failed';
}
