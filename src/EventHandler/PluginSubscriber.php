<?php

declare(strict_types=1);

namespace Plugin\AzuracastTraitementFichiers\EventHandler;

use App\Enums\StationPermissions;
use App\Event\BuildConsoleCommands;
use App\Event\BuildRoutes;
use App\Event\GetSyncTasks;
use App\Middleware;
use Plugin\AzuracastTraitementFichiers\Console\AnalyseCommand;
use Plugin\AzuracastTraitementFichiers\Console\RunCommand;
use Plugin\AzuracastTraitementFichiers\Controller\PanelController;
use Plugin\AzuracastTraitementFichiers\Middleware\InjectTab;
use Plugin\AzuracastTraitementFichiers\Service\Plugin;
use Plugin\AzuracastTraitementFichiers\Sync\ProcessTask;
use Slim\Routing\RouteCollectorProxy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class PluginSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            BuildRoutes::class => 'routes',
            GetSyncTasks::class => 'syncTasks',
            BuildConsoleCommands::class => 'commands',
        ];
    }

    public function syncTasks(GetSyncTasks $event): void
    {
        $event->addTask(ProcessTask::class);
    }

    public function commands(BuildConsoleCommands $event): void
    {
        $event->addAliases([
            RunCommand::NAME => RunCommand::class,
            AnalyseCommand::NAME => AnalyseCommand::class,
        ]);
    }

    public function routes(BuildRoutes $event): void
    {
        $app = $event->getApp();

        // Adds the tab to AzuraCast's own station settings page.
        $app->add(new InjectTab($app->getBasePath()));

        $app->get(Plugin::ROUTE_PREFIX . '/assets/tab.js', PanelController::class . ':assetAction');

        // Same permission as the "AutoDJ" tab of the station settings.
        $app->group(
            Plugin::ROUTE_PREFIX . '/{station_id}',
            function (RouteCollectorProxy $group): void {
                $group->get('/state', PanelController::class . ':stateAction');
                $group->post('/settings', PanelController::class . ':saveAction');
                $group->post('/backfill', PanelController::class . ':backfillAction');
            }
        )
            ->add(new Middleware\Permissions(StationPermissions::Broadcasting, true))
            ->add(Middleware\RequireStation::class)
            ->add(Middleware\GetStation::class)
            ->add(Middleware\RequireLogin::class)
            ->add(Middleware\Auth\StandardAuth::class)
            ->add(Middleware\InjectSession::class);
    }
}
