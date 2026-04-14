<?php
/**
 * DI container bootstrap.
 *
 * Builds and returns a configured PHP-DI container with all application
 * services bound to their interfaces. This is the central wiring point:
 * every class the plugin needs is registered here so that constructor
 * injection resolves dependencies automatically.
 *
 * @package Stagify
 */

declare(strict_types=1);

use DI\ContainerBuilder;
use Stagify\Api\Handlers\ContentHandler;
use Stagify\Api\Handlers\EnvironmentHandler;
use Stagify\Api\Handlers\FileHandler;
use Stagify\Api\ReceiverApi;
use Stagify\Admin\Actions\DeleteServerAction;
use Stagify\Admin\Actions\DiscardTaskAction;
use Stagify\Admin\Actions\PushTaskAction;
use Stagify\Admin\Actions\RetryTaskAction;
use Stagify\Admin\Actions\SaveModeAction;
use Stagify\Admin\Actions\SaveServerAction;
use Stagify\Admin\Actions\SaveTrackingAction;
use Stagify\Admin\Ajax\ActivateTaskAction;
use Stagify\Admin\Ajax\CreateTaskAction as AjaxCreateTaskAction;
use Stagify\Admin\Ajax\DiscardTaskAction as AjaxDiscardTaskAction;
use Stagify\Admin\Ajax\PushTaskAction as AjaxPushTaskAction;
use Stagify\Admin\Ajax\TestConnectionAction;
use Stagify\Admin\Pages\ReceiverSettingsPage;
use Stagify\Admin\Pages\SetupPage;
use Stagify\Admin\AdminMenu;
use Stagify\Admin\Pages\SettingsPage;
use Stagify\Admin\Pages\TasksPage;
use Stagify\Admin\TaskDetailPage;
use Stagify\Admin\TasksListTable;
use Stagify\Contracts\EventDispatcherInterface;
use Stagify\Contracts\FileSnapshotRepositoryInterface;
use Stagify\Contracts\ServerRepositoryInterface;
use Stagify\Contracts\TaskItemRepositoryInterface;
use Stagify\Contracts\TaskRepositoryInterface;
use Stagify\Events\EventDispatcher;
use Stagify\HookManager;
use Stagify\Repository\FileSnapshotRepository;
use Stagify\Repository\ServerRepository;
use Stagify\Repository\TaskItemRepository;
use Stagify\Repository\TaskRepository;
use Stagify\Serializers\PostSerializer;
use Stagify\Serializers\SerializerRegistry;
use Stagify\Services\PostTypeRegistry;
use Stagify\Services\FileScanner;
use Stagify\Services\PushService;

$builder = new ContainerBuilder();

$builder->addDefinitions(
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

return $builder->build();
