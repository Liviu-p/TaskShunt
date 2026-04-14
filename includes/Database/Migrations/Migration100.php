<?php
/**
 * Migration 1.0.0 — initial schema.
 *
 * @package Stagify\Database\Migrations
 */

declare(strict_types=1);

namespace Stagify\Database\Migrations;

use Stagify\Contracts\MigrationInterface;

/**
 * Creates the initial stagify database tables.
 */
final class Migration100 implements MigrationInterface {

	/**
	 * Create all required tables using dbDelta.
	 *
	 * @return void
	 */
	public function up(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$this->create_tasks_table();
		$this->create_task_items_table();
		$this->create_servers_table();
		$this->create_file_snapshots_table();
		$this->create_push_log_table();
	}

	/**
	 * Create the stagify_tasks table.
	 *
	 * @return void
	 */
	private function create_tasks_table(): void {
		global $wpdb;
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}stagify_tasks (
			  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			  title varchar(255) NOT NULL DEFAULT '',
			  status varchar(20) NOT NULL DEFAULT 'pending',
			  item_count bigint(20) unsigned NOT NULL DEFAULT 0,
			  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  pushed_at datetime DEFAULT NULL,
			  response_log text DEFAULT NULL,
			  PRIMARY KEY  (id),
			  KEY status (status)
			) {$wpdb->get_charset_collate()};"
		);
	}

	/**
	 * Create the stagify_task_items table.
	 *
	 * @return void
	 */
	private function create_task_items_table(): void {
		global $wpdb;
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}stagify_task_items (
			  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			  task_id bigint(20) unsigned NOT NULL,
			  type varchar(20) NOT NULL DEFAULT '',
			  action varchar(20) NOT NULL DEFAULT '',
			  object_type varchar(100) NOT NULL DEFAULT '',
			  object_id varchar(255) NOT NULL DEFAULT '',
			  payload longtext NOT NULL,
			  status varchar(20) NOT NULL DEFAULT 'pending',
			  pushed_at datetime DEFAULT NULL,
			  response_log longtext DEFAULT NULL,
			  PRIMARY KEY  (id),
			  KEY task_id (task_id),
			  KEY type_object (type, object_type, object_id)
			) {$wpdb->get_charset_collate()};"
		);
	}

	/**
	 * Create the stagify_servers table.
	 *
	 * @return void
	 */
	private function create_servers_table(): void {
		global $wpdb;
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}stagify_servers (
			  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			  name varchar(255) NOT NULL DEFAULT '',
			  url varchar(2048) NOT NULL DEFAULT '',
			  api_key varchar(512) NOT NULL DEFAULT '',
			  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY  (id)
			) {$wpdb->get_charset_collate()};"
		);
	}

	/**
	 * Create the stagify_file_snapshots table.
	 *
	 * @return void
	 */
	private function create_file_snapshots_table(): void {
		global $wpdb;
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}stagify_file_snapshots (
			  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			  path varchar(1000) NOT NULL DEFAULT '',
			  hash varchar(64) NOT NULL DEFAULT '',
			  file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			  scanned_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			  PRIMARY KEY  (id),
			  UNIQUE KEY path (path(191))
			) {$wpdb->get_charset_collate()};"
		);
	}

	/**
	 * Create the stagify_push_log table.
	 *
	 * @return void
	 */
	private function create_push_log_table(): void {
		global $wpdb;
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}stagify_push_log (
			  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			  task_id bigint(20) unsigned NOT NULL,
			  pushed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  http_code smallint unsigned NOT NULL DEFAULT 0,
			  response_message text DEFAULT NULL,
			  PRIMARY KEY  (id),
			  KEY task_id (task_id)
			) {$wpdb->get_charset_collate()};"
		);
	}
}
