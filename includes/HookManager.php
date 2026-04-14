<?php
/**
 * Hook manager.
 *
 * @package Stagify
 */

declare(strict_types=1);

namespace Stagify;

use Stagify\Contracts\EventDispatcherInterface;
use Stagify\Contracts\TaskItemRepositoryInterface;
use Stagify\Contracts\TaskRepositoryInterface;
use Stagify\Domain\TaskAction;
use Stagify\Domain\TaskItemType;
use Stagify\Events\ItemAdded;
use Stagify\Events\TaskActivated;
use Stagify\Serializers\SerializerRegistry;
use Stagify\Services\FileScanner;
use Stagify\Services\PostTypeRegistry;

/**
 * Registers all WordPress action and filter hooks for the plugin.
 */
final class HookManager {

	/**
	 * Create the hook manager.
	 *
	 * @param TaskRepositoryInterface     $task_repository      Task repository.
	 * @param TaskItemRepositoryInterface $task_item_repository Task item repository.
	 * @param EventDispatcherInterface    $event_dispatcher     Event dispatcher.
	 * @param PostTypeRegistry            $post_type_registry   Post type registry.
	 * @param SerializerRegistry          $serializer_registry  Serializer registry.
	 * @param FileScanner                 $file_scanner         File scanner service.
	 */
	public function __construct(
		private readonly TaskRepositoryInterface $task_repository,
		private readonly TaskItemRepositoryInterface $task_item_repository,
		private readonly EventDispatcherInterface $event_dispatcher,
		private readonly PostTypeRegistry $post_type_registry,
		private readonly SerializerRegistry $serializer_registry,
		private readonly FileScanner $file_scanner,
	) {}

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'save_post',
			function ( int $post_id, \WP_Post $post ): void {
				$this->on_save_post( $post_id, $post );
			},
			20,
			2
		);
		add_action(
			'transition_post_status',
			function ( string $new_status, string $old_status, \WP_Post $post ): void {
				$this->on_transition_post_status( $new_status, $old_status, $post );
			},
			10,
			3
		);
		add_action(
			'before_delete_post',
			function ( int $post_id, \WP_Post $post ): void {
				$this->on_delete_post( $post_id, $post );
			},
			10,
			2
		);
		add_action(
			'delete_attachment',
			function ( int $post_id, \WP_Post $post ): void {
				$this->on_delete_post( $post_id, $post );
			},
			10,
			2
		);
		add_action(
			'add_attachment',
			function ( int $post_id ): void {
				$this->on_add_attachment( $post_id );
			}
		);
		add_action(
			'edit_attachment',
			function ( int $post_id ): void {
				$this->on_edit_attachment( $post_id );
			}
		);
		add_filter(
			'wp_generate_attachment_metadata',
			function ( array $metadata, int $post_id ): array {
				$this->on_attachment_metadata_generated( $post_id );
				return $metadata;
			},
			999,
			2
		);

		// Plugin lifecycle hooks.
		add_action(
			'activated_plugin',
			function ( string $plugin ): void {
				$this->on_activate_plugin( $plugin );
			}
		);
		add_action(
			'deactivated_plugin',
			function ( string $plugin ): void {
				$this->on_deactivate_plugin( $plugin );
			}
		);
		add_action(
			'deleted_plugin',
			function ( string $plugin ): void {
				$this->on_delete_plugin( $plugin );
			},
			10,
			1
		);
		add_action(
			'upgrader_process_complete',
			function ( object $upgrader, array $options ): void {
				$this->on_upgrader_complete( $upgrader, $options );
			},
			10,
			2
		);

		// Theme lifecycle hooks.
		add_action(
			'switch_theme',
			function ( string $new_name, \WP_Theme $new_theme ): void {
				$this->on_switch_theme( $new_name, $new_theme );
			},
			10,
			2
		);

		// Snapshot all files when a task is activated so only subsequent changes are tracked.
		$this->event_dispatcher->add_listener(
			TaskActivated::class,
			function (): void {
				$this->file_scanner->snapshot_baseline();
			}
		);

		// Scan for file changes on admin page loads (throttled internally).
		if ( is_admin() ) {
			add_action(
				'admin_init',
				function (): void {
					$this->file_scanner->scan();
				}
			);
		}
	}

	/**
	 * Handle the before_delete_post action.
	 *
	 * Records a Delete action for posts and attachments before they are removed from the database.
	 *
	 * @param int      $post_id WordPress post ID.
	 * @param \WP_Post $post    WordPress post object.
	 * @return void
	 */
	private function on_delete_post( int $post_id, \WP_Post $post ): void {
		$task_id = $this->get_active_task_id();
		if ( null === $task_id ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $this->is_post_type_tracked( $post->post_type ) ) {
			return;
		}

		$existing = $this->task_item_repository->find_item( $task_id, TaskItemType::Content, $post->post_type, (string) $post_id );

		if ( null !== $existing ) {
			if ( TaskAction::Create === $existing->action ) {
				// Created and deleted in the same task — net effect is nothing.
				$this->task_item_repository->delete_item( $existing->id, $task_id );
				return;
			}

			// Updated then deleted — remove the update, record a delete instead.
			$this->task_item_repository->delete_item( $existing->id, $task_id );
		}



		$payload = $this->serializer_registry->resolve( $post->post_type )->serialize( $post_id, $post );
		$item_id = $this->task_item_repository->add_item(
			$task_id,
			TaskItemType::Content,
			TaskAction::Delete,
			$post->post_type,
			(string) $post_id,
			$payload
		);
		$this->event_dispatcher->dispatch( new ItemAdded( $task_id, $item_id, $post_id, TaskAction::Delete ) );
	}

	/**
	 * Handle the save_post action.
	 *
	 * Bails early for autosaves, revisions, and untracked post types.
	 *
	 * @param int      $post_id WordPress post ID.
	 * @param \WP_Post $post    WordPress post object.
	 * @return void
	 */
	private function on_save_post( int $post_id, \WP_Post $post ): void {
		$task_id = $this->get_active_task_id();
		if ( null === $task_id ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $this->is_post_type_tracked( $post->post_type ) ) {
			return;
		}
		$this->maybe_track_post( $task_id, $post_id, $post );
	}

	/**
	 * Add the post to the active task if not already tracked and the task has capacity.
	 *
	 * @param int      $task_id Task ID.
	 * @param int      $post_id WordPress post ID.
	 * @param \WP_Post $post    WordPress post object.
	 * @return void
	 */
	private function maybe_track_post( int $task_id, int $post_id, \WP_Post $post ): void {
		$payload = $this->serializer_registry->resolve( $post->post_type )->serialize( $post_id, $post );

		$existing = $this->task_item_repository->find_item( $task_id, TaskItemType::Content, $post->post_type, (string) $post_id );
		if ( null !== $existing ) {
			// Already tracked — refresh the payload with the latest content.
			$this->task_item_repository->update_payload( $existing->id, $payload );
			return;
		}


		$action  = $post->post_date === $post->post_modified ? TaskAction::Create : TaskAction::Update;
		$item_id = $this->task_item_repository->add_item(
			$task_id,
			TaskItemType::Content,
			$action,
			$post->post_type,
			(string) $post_id,
			$payload
		);
		$this->event_dispatcher->dispatch( new ItemAdded( $task_id, $item_id, $post_id, $action ) );
	}

	/**
	 * Handle the transition_post_status action.
	 *
	 * Only tracks transitions where at least one status is 'publish' and the statuses differ.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       WordPress post object.
	 * @return void
	 */
	private function on_transition_post_status( string $new_status, string $old_status, \WP_Post $post ): void {
		$task_id = $this->get_active_task_id();
		if ( null === $task_id ) {
			return;
		}
		if ( $new_status === $old_status ) {
			return;
		}
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		if ( ! $this->is_post_type_tracked( $post->post_type ) ) {
			return;
		}
		$this->maybe_track_status_transition( $task_id, $post );
	}

	/**
	 * Add the post status change to the active task if there is capacity.
	 *
	 * Always records TaskAction::Update because a status transition modifies an existing post.
	 *
	 * @param int      $task_id Task ID.
	 * @param \WP_Post $post    WordPress post object.
	 * @return void
	 */
	private function maybe_track_status_transition( int $task_id, \WP_Post $post ): void {
		$post_id = $post->ID;
		if ( $this->task_item_repository->item_exists( $task_id, TaskItemType::Content, $post->post_type, (string) $post_id ) ) {
			return;
		}

		$payload = $this->serializer_registry->resolve( $post->post_type )->serialize( $post_id, $post );
		$item_id = $this->task_item_repository->add_item(
			$task_id,
			TaskItemType::Content,
			TaskAction::Update,
			$post->post_type,
			(string) $post_id,
			$payload
		);
		$this->event_dispatcher->dispatch( new ItemAdded( $task_id, $item_id, $post_id, TaskAction::Update ) );
	}

	/**
	 * Handle the add_attachment action (new media upload).
	 *
	 * @param int $post_id Attachment post ID.
	 * @return void
	 */
	private function on_add_attachment( int $post_id ): void {
		$task_id = $this->get_active_task_id();
		if ( null === $task_id ) {
			return;
		}
		if ( ! $this->is_post_type_tracked( 'attachment' ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( null === $post ) {
			return;
		}
		$this->maybe_track_post( $task_id, $post_id, $post );
	}

	/**
	 * Handle the edit_attachment action (media metadata update).
	 *
	 * @param int $post_id Attachment post ID.
	 * @return void
	 */
	private function on_edit_attachment( int $post_id ): void {
		$task_id = $this->get_active_task_id();
		if ( null === $task_id ) {
			return;
		}
		if ( ! $this->is_post_type_tracked( 'attachment' ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( null === $post ) {
			return;
		}
		$this->maybe_track_post( $task_id, $post_id, $post );
	}

	/**
	 * Refresh an attachment's payload after WordPress finishes generating metadata.
	 *
	 * At add_attachment time, wp_get_attachment_url may not work because the
	 * file meta hasn't been saved yet. This hook fires after the upload is
	 * fully processed, so the payload now contains the correct attachment_url.
	 *
	 * @param int $post_id Attachment post ID.
	 * @return void
	 */
	private function on_attachment_metadata_generated( int $post_id ): void {
		$task_id = $this->get_active_task_id();
		if ( null === $task_id ) {
			return;
		}

		$existing = $this->task_item_repository->find_item( $task_id, TaskItemType::Content, 'attachment', (string) $post_id );
		if ( null === $existing ) {
			return;
		}

		$post = get_post( $post_id );
		if ( null === $post ) {
			return;
		}

		$payload = $this->serializer_registry->resolve( 'attachment' )->serialize( $post_id, $post );
		$this->task_item_repository->update_payload( $existing->id, $payload );
	}

	/**
	 * Handle the activated_plugin action.
	 *
	 * @param string $plugin Plugin basename (e.g. "woocommerce/woocommerce.php").
	 * @return void
	 */
	private function on_activate_plugin( string $plugin ): void {
		$this->record_environment_change( $plugin, 'plugin', 'activate' );
	}

	/**
	 * Handle the deactivated_plugin action.
	 *
	 * @param string $plugin Plugin basename.
	 * @return void
	 */
	private function on_deactivate_plugin( string $plugin ): void {
		$this->record_environment_change( $plugin, 'plugin', 'deactivate' );
	}

	/**
	 * Handle the deleted_plugin action.
	 *
	 * @param string $plugin Plugin basename.
	 * @return void
	 */
	private function on_delete_plugin( string $plugin ): void {
		$task_id = $this->get_active_task_id();
		if ( null === $task_id ) {
			return;
		}

		$existing = $this->task_item_repository->find_item( $task_id, TaskItemType::Environment, 'plugin', $plugin );

		if ( null !== $existing ) {
			$this->task_item_repository->delete_item( $existing->id, $task_id );
		}



		$payload = wp_json_encode( array(
			'slug'   => $plugin,
			'action' => 'delete',
			'name'   => $plugin,
		) );

		$this->task_item_repository->add_item(
			$task_id,
			TaskItemType::Environment,
			TaskAction::Delete,
			'plugin',
			$plugin,
			(string) $payload
		);
	}

	/**
	 * Handle the switch_theme action.
	 *
	 * @param string    $new_name  New theme name.
	 * @param \WP_Theme $new_theme New theme object.
	 * @return void
	 */
	private function on_switch_theme( string $new_name, \WP_Theme $new_theme ): void {
		$slug = $new_theme->get_stylesheet();

		$this->record_environment_change( $slug, 'theme', 'switch', array(
			'name'    => $new_name,
			'version' => $new_theme->get( 'Version' ),
		) );
	}

	/**
	 * Handle the upgrader_process_complete action.
	 *
	 * Records plugin/theme installations and updates performed via the WP upgrader.
	 *
	 * @param object               $upgrader WP_Upgrader instance.
	 * @param array<string, mixed> $options  Upgrade context with 'type' and 'action' keys.
	 * @return void
	 */
	private function on_upgrader_complete( object $upgrader, array $options ): void {
		$task_id = $this->get_active_task_id();
		if ( null === $task_id ) {
			return;
		}

		$type = $options['type'] ?? '';
		$wp_action = $options['action'] ?? '';

		if ( 'plugin' === $type ) {
			$this->handle_upgrader_plugins( $task_id, $wp_action, $options );
		} elseif ( 'theme' === $type ) {
			$this->handle_upgrader_themes( $task_id, $wp_action, $options );
		}
	}

	/**
	 * Process plugin install/update events from the upgrader.
	 *
	 * @param int                  $task_id   Active task ID.
	 * @param string               $wp_action WordPress upgrader action (install or update).
	 * @param array<string, mixed> $options   Upgrade context.
	 * @return void
	 */
	private function handle_upgrader_plugins( int $task_id, string $wp_action, array $options ): void {
		$slugs = array();

		if ( 'install' === $wp_action && ! empty( $options['destination_name'] ) ) {
			// Single install — find the main plugin file.
			$plugin_dir  = $options['destination_name'];
			$all_plugins = get_plugins();
			foreach ( $all_plugins as $basename => $data ) {
				if ( str_starts_with( $basename, $plugin_dir . '/' ) ) {
					$slugs[] = $basename;
					break;
				}
			}
		} elseif ( 'update' === $wp_action && ! empty( $options['plugins'] ) ) {
			$slugs = (array) $options['plugins'];
		}

		$action = 'install' === $wp_action ? TaskAction::Create : TaskAction::Update;

		foreach ( $slugs as $plugin ) {
			if ( ! $this->has_task_capacity( $task_id ) ) {
				return;
			}
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
			$wp_slug     = strstr( $plugin, '/', true ) ?: $plugin;
			$payload     = wp_json_encode( array(
				'slug'    => $plugin,
				'wp_slug' => $wp_slug,
				'action'  => $wp_action,
				'name'    => $plugin_data['Name'] ?? $plugin,
				'version' => $plugin_data['Version'] ?? '',
			) );

			$existing = $this->task_item_repository->find_item( $task_id, TaskItemType::Environment, 'plugin', $plugin );
			if ( null !== $existing ) {
				$this->task_item_repository->update_payload( $existing->id, (string) $payload );
				continue;
			}

			$this->task_item_repository->add_item(
				$task_id,
				TaskItemType::Environment,
				$action,
				'plugin',
				$plugin,
				(string) $payload
			);
		}
	}

	/**
	 * Process theme install/update events from the upgrader.
	 *
	 * @param int                  $task_id   Active task ID.
	 * @param string               $wp_action WordPress upgrader action (install or update).
	 * @param array<string, mixed> $options   Upgrade context.
	 * @return void
	 */
	private function handle_upgrader_themes( int $task_id, string $wp_action, array $options ): void {
		$slugs = array();

		if ( 'install' === $wp_action && ! empty( $options['destination_name'] ) ) {
			$slugs[] = $options['destination_name'];
		} elseif ( 'update' === $wp_action && ! empty( $options['themes'] ) ) {
			$slugs = (array) $options['themes'];
		}

		$action = 'install' === $wp_action ? TaskAction::Create : TaskAction::Update;

		foreach ( $slugs as $slug ) {
			if ( ! $this->has_task_capacity( $task_id ) ) {
				return;
			}
			$theme   = wp_get_theme( $slug );
			$payload = wp_json_encode( array(
				'slug'    => $slug,
				'action'  => $wp_action,
				'name'    => $theme->exists() ? $theme->get( 'Name' ) : $slug,
				'version' => $theme->exists() ? $theme->get( 'Version' ) : '',
			) );

			$existing = $this->task_item_repository->find_item( $task_id, TaskItemType::Environment, 'theme', $slug );
			if ( null !== $existing ) {
				$this->task_item_repository->update_payload( $existing->id, (string) $payload );
				continue;
			}

			$this->task_item_repository->add_item(
				$task_id,
				TaskItemType::Environment,
				$action,
				'theme',
				$slug,
				(string) $payload
			);
		}
	}

	/**
	 * Record a plugin or theme state change (activate, deactivate, switch).
	 *
	 * Handles deduplication: if the same object was already tracked with the
	 * opposite state change (e.g. activate then deactivate), they cancel out.
	 *
	 * @param string               $slug        Object identifier (plugin basename or theme slug).
	 * @param string               $object_type "plugin" or "theme".
	 * @param string               $env_action  The environment action (activate, deactivate, switch).
	 * @param array<string, mixed> $extra       Additional payload fields.
	 * @return void
	 */
	private function record_environment_change( string $slug, string $object_type, string $env_action, array $extra = array() ): void {
		$task_id = $this->get_active_task_id();
		if ( null === $task_id ) {
			return;
		}

		$existing = $this->task_item_repository->find_item( $task_id, TaskItemType::Environment, $object_type, $slug );

		if ( null !== $existing ) {
			$existing_payload = json_decode( $existing->payload, true );
			$existing_action  = $existing_payload['action'] ?? '';

			// Activate then deactivate (or vice versa) in the same task — cancel out.
			$cancels = array(
				'activate'   => 'deactivate',
				'deactivate' => 'activate',
			);

			if ( isset( $cancels[ $env_action ] ) && $cancels[ $env_action ] === $existing_action ) {
				$this->task_item_repository->delete_item( $existing->id, $task_id );
				return;
			}

			// Install then activate/switch — keep install as the primary action
			// and flag the post-install step so the receiver does both.
			if ( 'install' === $existing_action && in_array( $env_action, array( 'activate', 'switch' ), true ) ) {
				$existing_payload['activate_after'] = true;
				$this->task_item_repository->update_payload( $existing->id, (string) wp_json_encode( $existing_payload ) );
				return;
			}

			// Same type of change again — update the payload.
			$payload = wp_json_encode( array_merge(
				array(
					'slug'   => $slug,
					'action' => $env_action,
					'name'   => $slug,
				),
				$extra
			) );
			$this->task_item_repository->update_payload( $existing->id, (string) $payload );
			return;
		}



		$payload_data = array_merge(
			array(
				'slug'   => $slug,
				'action' => $env_action,
				'name'   => $slug,
			),
			$extra
		);

		// For plugins, try to get the human-readable name.
		if ( 'plugin' === $object_type && empty( $extra['name'] ) ) {
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $slug, false, false );
			if ( ! empty( $plugin_data['Name'] ) ) {
				$payload_data['name']    = $plugin_data['Name'];
				$payload_data['version'] = $plugin_data['Version'] ?? '';
			}
		}

		$payload = wp_json_encode( $payload_data );

		$this->task_item_repository->add_item(
			$task_id,
			TaskItemType::Environment,
			TaskAction::Update,
			$object_type,
			$slug,
			(string) $payload
		);
	}

	/**
	 * Whether the given post type appears in the tracked list.
	 *
	 * @param string $post_type WordPress post type slug.
	 * @return bool
	 */
	private function is_post_type_tracked( string $post_type ): bool {
		return in_array( $post_type, $this->post_type_registry->get_tracked(), true );
	}

	/**
	 * Return the active task ID, or null if no task is active.
	 *
	 * Hook callbacks call this first and bail early when null is returned.
	 *
	 * @return int|null
	 */
	private function get_active_task_id(): ?int {
		return $this->task_repository->get_active_task_id();
	}
}
