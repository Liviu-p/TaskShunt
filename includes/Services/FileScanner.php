<?php
/**
 * File scanner service.
 *
 * Scans wp-content directories for file changes and records them as task items.
 *
 * @package Stagify\Services
 */

declare(strict_types=1);

namespace Stagify\Services;

use Stagify\Contracts\FileSnapshotRepositoryInterface;
use Stagify\Contracts\TaskItemRepositoryInterface;
use Stagify\Contracts\TaskRepositoryInterface;
use Stagify\Domain\RelativePath;
use Stagify\Domain\TaskAction;
use Stagify\Domain\TaskItemType;

/**
 * Watches for file changes in your theme and mu-plugins folders.
 *
 * How it works:
 *  - When you activate a task, it takes a "photo" (SHA-256 hash) of every file.
 *  - On each admin page load (max once per 30 sec), it re-scans and compares hashes.
 *  - New files → recorded as "created". Changed hashes → "updated". Missing files → "deleted".
 *  - Skips node_modules, vendor, and .git folders automatically.
 *  - Only looks at code-related files: .php, .css, .js, .json, .html, .svg, .twig, etc.
 */
final class FileScanner {

	/**
	 * Only these file types are scanned — images, fonts, etc. are ignored.
	 *
	 * @var list<string>
	 */
	private const EXTENSIONS = array( 'php', 'css', 'js', 'json', 'html', 'htm', 'txt', 'svg', 'twig' );

	/**
	 * Transient key used to throttle scans.
	 */
	private const THROTTLE_KEY = 'stagify_file_scan_last';

	/**
	 * Minimum seconds between scans.
	 */
	private const THROTTLE_SECONDS = 30;

	/**
	 * Create the file scanner.
	 *
	 * @param FileSnapshotRepositoryInterface $snapshot_repository Snapshot repository.
	 * @param TaskItemRepositoryInterface     $task_item_repository Task item repository.
	 * @param TaskRepositoryInterface         $task_repository      Task repository.
	 */
	public function __construct(
		private readonly FileSnapshotRepositoryInterface $snapshot_repository,
		private readonly TaskItemRepositoryInterface $task_item_repository,
		private readonly TaskRepositoryInterface $task_repository,
	) {}

	/**
	 * Scan for file changes and record them against the active task.
	 *
	 * Bails early if no task is active, the task is at capacity, or a scan
	 * was performed recently (throttle).
	 *
	 * @return void
	 */
	public function scan(): void {
		$task_id = $this->task_repository->get_active_task_id();
		if ( null === $task_id ) {
			return;
		}

		if ( $this->is_throttled() ) {
			return;
		}

		$this->mark_scanned();

		// First scan for this task — take a baseline instead of reporting all files as new.
		if ( empty( $this->snapshot_repository->get_all_paths() ) ) {
			$this->snapshot_baseline();
			return;
		}

		$directories = $this->get_scan_directories();
		$seen_paths  = array();

		foreach ( $directories as $label => $abs_dir ) {
			if ( ! is_dir( $abs_dir ) ) {
				continue;
			}
			$this->scan_directory( $task_id, $abs_dir, $label, $seen_paths );
		}

		$this->detect_deletions( $task_id, $seen_paths );
	}

	/**
	 * Take a baseline snapshot of all scanned directories without recording changes.
	 *
	 * Called when a task is first activated so that only subsequent edits are tracked.
	 *
	 * @return void
	 */
	public function snapshot_baseline(): void {
		$this->snapshot_repository->delete_all();

		foreach ( $this->get_scan_directories() as $label => $abs_dir ) {
			if ( ! is_dir( $abs_dir ) ) {
				continue;
			}

			$iterator = $this->create_iterator( $abs_dir );
			foreach ( $iterator as $file ) {
				if ( ! $this->is_scannable( $file ) ) {
					continue;
				}

				$relative = $this->relative_path( $abs_dir, $file->getPathname(), $label );
				$hash     = $this->hash_file( $file->getPathname() );

				$this->snapshot_repository->upsert_hash( new RelativePath( $relative ), $hash );
			}
		}
	}

	/**
	 * Return the directories to scan, keyed by label used in relative paths.
	 *
	 * @return array<string, string>
	 */
	private function get_scan_directories(): array {
		$dirs = array(
			'theme' => get_stylesheet_directory(),
		);

		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			$dirs['mu-plugins'] = WPMU_PLUGIN_DIR;
		}

		return $dirs;
	}

	/**
	 * Scan a single directory tree for created and updated files.
	 *
	 * @param int                $task_id    Active task ID.
	 * @param string             $abs_dir    Absolute path to scan.
	 * @param string             $label      Label prefix for relative paths.
	 * @param array<string,bool> $seen_paths Collector of visited paths (by reference).
	 * @return void
	 */
	private function scan_directory( int $task_id, string $abs_dir, string $label, array &$seen_paths ): void {
		$iterator = $this->create_iterator( $abs_dir );

		foreach ( $iterator as $file ) {
			if ( ! $this->is_scannable( $file ) ) {
				continue;
			}

			$relative                = $this->relative_path( $abs_dir, $file->getPathname(), $label );
			$seen_paths[ $relative ] = true;

			$hash = $this->hash_file( $file->getPathname() );
			$path = new RelativePath( $relative );

			$stored_hash = $this->snapshot_repository->get_hash( $path );

			if ( null === $stored_hash ) {
				$this->record_change( $task_id, TaskAction::Create, $relative, $file->getPathname() );
				$this->snapshot_repository->upsert_hash( $path, $hash );
				continue;
			}

			if ( $stored_hash !== $hash ) {
				$this->record_change( $task_id, TaskAction::Update, $relative, $file->getPathname() );
				$this->snapshot_repository->upsert_hash( $path, $hash );
			}
		}
	}

	/**
	 * Detect files that were present in the snapshot but are now missing.
	 *
	 * @param int                $task_id    Active task ID.
	 * @param array<string,bool> $seen_paths Paths visited during the current scan.
	 * @return void
	 */
	private function detect_deletions( int $task_id, array $seen_paths ): void {
		$stored_paths = $this->snapshot_repository->get_all_paths();

		foreach ( $stored_paths as $stored ) {
			if ( isset( $seen_paths[ $stored ] ) ) {
				continue;
			}

			$this->record_change( $task_id, TaskAction::Delete, $stored, '' );
			$this->snapshot_repository->delete_hash( new RelativePath( $stored ) );
		}
	}

	/**
	 * Record a file change as a task item if not already tracked and the task has capacity.
	 *
	 * @param int        $task_id  Task ID.
	 * @param TaskAction $action   Create, Update, or Delete.
	 * @param string     $relative Relative path used as object_id.
	 * @param string     $abs_path Absolute path for payload (empty for deletes).
	 * @return void
	 */
	private function record_change( int $task_id, TaskAction $action, string $relative, string $abs_path ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( $this->task_item_repository->item_exists( $task_id, TaskItemType::File, 'file', $relative ) ) {
			return;
		}

		$payload = wp_json_encode(
			array(
				'path'   => $relative,
				'action' => $action->value,
			) 
		);

		$this->task_item_repository->add_item(
			$task_id,
			TaskItemType::File,
			$action,
			'file',
			$relative,
			(string) $payload
		);
	}

	/**
	 * Whether the scan is throttled (ran too recently).
	 *
	 * @return bool
	 */
	private function is_throttled(): bool {
		return false !== get_transient( self::THROTTLE_KEY );
	}

	/**
	 * Set the throttle transient.
	 *
	 * @return void
	 */
	private function mark_scanned(): void {
		set_transient( self::THROTTLE_KEY, time(), self::THROTTLE_SECONDS );
	}

	/**
	 * Build a relative path string like "theme/style.css" or "mu-plugins/custom.php".
	 *
	 * @param string $base_dir Absolute base directory.
	 * @param string $abs_path Absolute file path.
	 * @param string $label    Prefix label (e.g. "theme").
	 * @return string
	 */
	private function relative_path( string $base_dir, string $abs_path, string $label ): string {
		$suffix = ltrim( substr( $abs_path, strlen( $base_dir ) ), '/' );
		return $label . '/' . $suffix;
	}

	/**
	 * Hash a file's contents.
	 *
	 * @param string $path Absolute file path.
	 * @return string SHA-256 hex digest.
	 */
	private function hash_file( string $path ): string {
		$hash = hash_file( 'sha256', $path );
		return false !== $hash ? $hash : '';
	}

	/**
	 * Create a recursive directory iterator that skips node_modules and vendor.
	 *
	 * @param string $directory Absolute directory path.
	 * @return \RecursiveIteratorIterator<\RecursiveDirectoryIterator>
	 */
	private function create_iterator( string $directory ): \RecursiveIteratorIterator {
		$dir_iterator = new \RecursiveDirectoryIterator(
			$directory,
			\RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS
		);

		$filter = new \RecursiveCallbackFilterIterator(
			$dir_iterator,
			static function ( \SplFileInfo $current, string $key, \RecursiveDirectoryIterator $iterator ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
				if ( $current->isDir() ) {
					$name = $current->getFilename();
					return ! in_array( $name, array( 'node_modules', 'vendor', '.git' ), true );
				}
				return true;
			}
		);

		return new \RecursiveIteratorIterator( $filter );
	}

	/**
	 * Whether a file should be included in the scan.
	 *
	 * @param \SplFileInfo $file File info.
	 * @return bool
	 */
	private function is_scannable( \SplFileInfo $file ): bool {
		if ( ! $file->isFile() || ! $file->isReadable() ) {
			return false;
		}

		$ext = strtolower( $file->getExtension() );
		return in_array( $ext, self::EXTENSIONS, true );
	}
}
