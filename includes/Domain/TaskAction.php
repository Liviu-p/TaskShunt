<?php
/**
 * Task action enum.
 *
 * @package TaskShunt\Domain
 */

declare(strict_types=1);

namespace TaskShunt\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
