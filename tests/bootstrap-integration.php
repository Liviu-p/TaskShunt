<?php
/**
 * Integration test bootstrap.
 *
 * Loads the WordPress test library from the WP_TESTS_DIR environment variable.
 * Requires a local WordPress test installation (see wp-tests-config.php).
 *
 * @package Stagify\Tests
 */

declare(strict_types=1);

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $wp_tests_dir ) {
	$wp_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
	echo 'Could not find WordPress test library at: ' . $wp_tests_dir . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo 'Set WP_TESTS_DIR or run install-wp-tests.sh.' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once $wp_tests_dir . '/includes/functions.php'; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/stagify.php';
	}
);

require_once $wp_tests_dir . '/includes/bootstrap.php'; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
