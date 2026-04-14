<?php
/**
 * Task action enum.
 *
 * @package Stagify\Domain
 */

declare(strict_types=1);

namespace Stagify\Domain;

/**
 * What happened to the tracked object:
 *  Create — a new post/file/plugin was added.
 *  Update — an existing post/file/plugin was modified.
 *  Delete — an existing post/file/plugin was removed.
 */
enum TaskAction: string {

	case Create = 'create';
	case Update = 'update';
	case Delete = 'delete';
}
