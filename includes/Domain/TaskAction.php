<?php
/**
 * Task action enum.
 *
 * @package Stagify\Domain
 */

declare(strict_types=1);

namespace Stagify\Domain;

enum TaskAction: string {

	case Create = 'create';
	case Update = 'update';
	case Delete = 'delete';
}
