<?php

declare(strict_types=1);

use App\CallableEventDispatcherInterface;
use App\Event;
use App\Middleware;
use Plugin\AzanQuran\Controller;
use Plugin\AzanQuran\Permissions;
use Slim\Routing\RouteCollectorProxy;

/*
 * Every listener is wrapped in guard(): an exception in plugin wiring must not take Acacia Radio down
 * (a plugin fatal at boot stops nginx, the stations and now-playing). A failure is logged and skipped.
 */
$guard = static function (string $what, callable $fn): callable {
    return static function (...$args) use ($what, $fn): void {
        try {
            $fn(...$args);
        } catch (\Throwable $e) {
            error_log(sprintf('[azan-quran] %s failed: %s in %s:%d', $what, $e->getMessage(), $e->getFile(), $e->getLine()));
        }
    };
};

return static function (CallableEventDispatcherInterface $dispatcher) use ($guard) {
    $dispatcher->addListener(
        Event\BuildMigrationConfigurationArray::class,
        $guard('migrations', static function (Event\BuildMigrationConfigurationArray $event) {
            $config = $event->getMigrationConfigurations();
            $config['migrations_paths']['Plugin\AzanQuran\Migration'] = __DIR__ . '/src/Migration';
            $event->setMigrationConfigurations($config);
        })
    );

    $dispatcher->addListener(
        Event\BuildPermissions::class,
        $guard('permissions', static function (Event\BuildPermissions $event) {
            $permissions = $event->getPermissions();
            foreach (Permissions::NAMES as $key => $label) {
                $permissions['station'][$key] = $label;
            }
            $event->setPermissions($permissions);
        })
    );

    $dispatcher->addListener(
        Event\GetSyncTasks::class,
        $guard('sync tasks', static function (Event\GetSyncTasks $event) {
            $event->addTasks([
                \Plugin\AzanQuran\Sync\PlanTask::class,
                \Plugin\AzanQuran\Sync\CueTask::class,
            ]);
        })
    );

    // The fade command, after the live-DJ fallback (20) and before the pre-broadcast section (10).
    // Resolved from the DI container; it catches its own errors.
    $dispatcher->addCallableListener(
        Event\Radio\WriteLiquidsoapConfiguration::class,
        \Plugin\AzanQuran\Radio\LiquidsoapFade::class,
        '__invoke',
        15
    );

    $dispatcher->addListener(
        Event\BuildRoutes::class,
        $guard('routes', static function (Event\BuildRoutes $event) {
            $app = $event->getApp();

            // The page. Not under /station/{id}/...: core has a catch-all there for its Vue app.
            $app->get('/azan-quran/{station_id}', Controller\PageAction::class)
                ->setName('azan-quran:page')
                ->add(new Middleware\Permissions(Permissions::MANAGE, true))
                ->add(Middleware\RequireLogin::class)
                ->add(Middleware\RequireStation::class)
                ->add(Middleware\GetStation::class)
                ->add(Middleware\Auth\StandardAuth::class)
                ->add(Middleware\InjectSession::class);

            // Station API, same middleware stack as core's /api/station/{station_id} routes.
            // Route-group callbacks must NOT be static: Slim binds them to the container.
            $app->group(
                '/api/station/{station_id}/azan-quran',
                function (RouteCollectorProxy $group) {
                    $c = Controller\StationController::class;
                    $group->get('/access', $c . ':accessAction')->setName('azan-quran:access');
                    $group->get('/state', $c . ':stateAction')->setName('azan-quran:state');
                    $group->put('/station', $c . ':saveStationAction');
                    $group->put('/global', $c . ':saveGlobalAction');
                    $group->get('/media', $c . ':mediaAction');
                    $group->put('/quran', $c . ':quranAction');
                    $group->post('/plan', $c . ':planAction');
                }
            )->add(new Middleware\Permissions(Permissions::MANAGE, true))
                ->add(Middleware\RequireStation::class)
                ->add(Middleware\GetStation::class)
                ->add(Middleware\Module\Api::class)
                ->add(Middleware\Auth\ApiAuth::class)
                ->add(Middleware\InjectSession::class);

            // "Azan & Quran" button on station pages, until Acacia Radio Retail has a native menu item.
            if (!defined('App\Enums\StationFeatures::AzanQuran')) {
                $app->get(\Plugin\AzanQuran\Middleware\InjectNavButton::SCRIPT_PATH, Controller\NavScriptAction::class)
                    ->setName('azan-quran:nav-js');
                $app->add(new \Plugin\AzanQuran\Middleware\InjectNavButton());
            }
        })
    );
};
