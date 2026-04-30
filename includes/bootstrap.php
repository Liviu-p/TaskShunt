<?php
/**
 * DI container bootstrap.
 *
 * Builds and returns a configured PHP-DI container with all application
 * services bound to their interfaces. This is the central wiring point:
 * every class the plugin needs is registered here so that constructor
 * injection resolves dependencies automatically.
 *
 * @package TaskShunt
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DI\ContainerBuilder;
use TaskShunt\Api\Handlers\ContentHandler;
use TaskShunt\Api\Handlers\EnvironmentHandler;
use TaskShunt\Api\Handlers\FileHandler;
use TaskShunt\Api\ReceiverApi;
use TaskShunt\Admin\Actions\DeleteServerAction;
use TaskShunt\Admin\Actions\DiscardTaskAction;
use TaskShunt\Admin\Actions\PushTaskAction;
use TaskShunt\Admin\Actions\RetryTaskAction;
use TaskShunt\Admin\Actions\SaveModeAction;
use TaskShunt\Admin\Actions\SaveServerAction;
use TaskShunt\Admin\Actions\SaveCleanupAction;
use TaskShunt\Admin\Actions\SaveTrackingAction;
use TaskShunt\Admin\Ajax\ActivateTaskAction;
use TaskShunt\Admin\Ajax\CreateTaskAction as AjaxCreateTaskAction;
use TaskShunt\Admin\Ajax\DiscardTaskAction as AjaxDiscardTaskAction;
use TaskShunt\Admin\Ajax\PushTaskAction as AjaxPushTaskAction;
use TaskShunt\Admin\Ajax\TestConnectionAction;
use TaskShunt\Admin\Pages\ReceiverSettingsPage;
use TaskShunt\Admin\Pages\SetupPage;
use TaskShunt\Admin\AdminMenu;
use TaskShunt\Admin\Pages\SettingsPage;
use TaskShunt\Admin\Pages\TasksPage;
use TaskShunt\Admin\TaskDetailPage;
use TaskShunt\Admin\TasksListTable;
use TaskShunt\Contracts\EventDispatcherInterface;
use TaskShunt\Contracts\FileSnapshotRepositoryInterface;
use TaskShunt\Contracts\ServerRepositoryInterface;
use TaskShunt\Contracts\TaskItemRepositoryInterface;
use TaskShunt\Contracts\TaskRepositoryInterface;
use TaskShunt\Events\EventDispatcher;
use TaskShunt\HookManager;
use TaskShunt\Repository\FileSnapshotRepository;
use TaskShunt\Repository\ServerRepository;
use TaskShunt\Repository\TaskItemRepository;
use TaskShunt\Repository\TaskRepository;
use TaskShunt\Serializers\PostSerializer;
use TaskShunt\Serializers\SerializerRegistry;
use TaskShunt\Services\PostTypeRegistry;
use TaskShunt\Services\FileScanner;
use TaskShunt\Services\PushService;

$taskshunt_builder = new ContainerBuilder();

$taskshunt_builder->addDefinitions(
	array(
		// WordPress global — provides wpdb to any class that type-hints \wpdb.
		\wpdb::class                           => \DI\factory(
			static function (): \wpdb {
				global $wpdb;
				return $wpdb;
			}
		),

		// Repositories.
		TaskRepositoryInterface::class         => \DI\autowire( TaskRepository::class ),
		TaskItemRepositoryInterface::class     => \DI\autowire( TaskItemRepository::class ),
		ServerRepositoryInterface::class       => \DI\autowire( ServerRepository::class ),
		FileSnapshotRepositoryInterface::class => \DI\autowire( FileSnapshotRepository::class ),

		// Serialization — PostSerializer acts as the universal fallback.
		SerializerRegistry::class              => \DI\factory(
			static function (): SerializerRegistry {
				return new SerializerRegistry( array( new PostSerializer() ) );
			}
		),

		// Admin.
		AdminMenu::class                       => \DI\autowire(),
		DeleteServerAction::class              => \DI\autowire(),
		DiscardTaskAction::class               => \DI\autowire(),
		PushTaskAction::class                  => \DI\autowire(),
		RetryTaskAction::class                 => \DI\autowire(),
		SaveModeAction::class                  => \DI\autowire(),
		SaveServerAction::class                => \DI\autowire(),
		SaveCleanupAction::class               => \DI\autowire(),
		SaveTrackingAction::class              => \DI\autowire(),
		ReceiverSettingsPage::class            => \DI\autowire(),
		SetupPage::class                       => \DI\autowire(),
		ActivateTaskAction::class              => \DI\autowire(),
		AjaxCreateTaskAction::class            => \DI\autowire(),
		AjaxDiscardTaskAction::class           => \DI\autowire(),
		AjaxPushTaskAction::class              => \DI\autowire(),
		TestConnectionAction::class            => \DI\autowire(),
		TasksPage::class                       => \DI\autowire(),
		TaskDetailPage::class                  => \DI\autowire(),
		SettingsPage::class                    => \DI\autowire(),
		TasksListTable::class                  => \DI\factory(
			static function ( TaskRepositoryInterface $task_repository ): TasksListTable {
				return new TasksListTable( $task_repository );
			}
		),

		// API.
		ContentHandler::class                  => \DI\autowire(),
		EnvironmentHandler::class              => \DI\autowire(),
		FileHandler::class                     => \DI\autowire(),
		ReceiverApi::class                     => \DI\autowire(),

		// Application services.
		PostTypeRegistry::class                => \DI\autowire(),
		FileScanner::class                     => \DI\autowire(),
		EventDispatcherInterface::class        => \DI\autowire( EventDispatcher::class ),
		HookManager::class                     => \DI\autowire(),
		PushService::class                     => \DI\autowire(),
	)
);

return $taskshunt_builder->build();
