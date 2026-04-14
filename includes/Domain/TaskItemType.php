<?php
/**
 * Task item type enum.
 *
 * @package Stagify\Domain
 */

declare(strict_types=1);

namespace Stagify\Domain;

enum TaskItemType: string {

	case Content     = 'content';
	case File        = 'file';
	case Database    = 'database';
	case Environment = 'environment';
}
