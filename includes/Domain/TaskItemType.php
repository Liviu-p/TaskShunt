<?php
/**
 * Task item type enum.
 *
 * @package TaskShunt\Domain
 */

declare(strict_types=1);

namespace TaskShunt\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category of a tracked change:
 *  Content     — a WordPress post, page, attachment, or custom post type.
 *  File        — a theme or mu-plugin file that was created, modified, or deleted.
 *  Database    — reserved for future use (direct DB changes).
 *  Environment — a plugin or theme lifecycle event (install, activate, update, delete, switch).
 */
enum TaskItemType: string {

	case Content     = 'content';
	case File        = 'file';
	case Database    = 'database';
	case Environment = 'environment';
}
