<?php

declare(strict_types=1);

use App\CallableEventDispatcherInterface;
use App\Enums\GlobalPermissions;
use App\Event;
use App\Middleware;
use Plugin\MusicDownloader\Controller;
use Plugin\MusicDownloader\Permissions;
use Slim\Routing\RouteCollectorProxy;

/*
 * Every listener is wrapped in guard(): an exception in plugin wiring must not take AzuraCast down
 * (a plugin fatal at boot stops nginx, the stations and now-playing). A failure is logged and skipped.
 */
$guard = static function (string $what, callable $fn): callable {
    return static function (...$args) use ($what, $fn): void {
        try {
            $fn(...$args);
        } catch (\Throwable $e) {
            error_log(sprintf('[music-downloader] %s failed: %s in %s:%d', $what, $e->getMessage(), $e->getFile(), $e->getLine()));
        }
    };
};

return static function (CallableEventDispatcherInterface $dispatcher) use ($guard) {
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
        Event\BuildRoutes::class,
        $guard('routes', static function (Event\BuildRoutes $event) {
            $app = $event->getApp();

            // Spotify sends the browser back here after login (HTTPS sites; see Service\SpotifyRedirect).
            $app->get('/music-downloader/oauth/callback', Controller\OAuthCallbackAction::class)
                ->setName('music-downloader:oauth-callback')
                ->add(Middleware\RequireLogin::class)
                ->add(Middleware\Auth\StandardAuth::class)
                ->add(Middleware\InjectSession::class);

            // The page. Not under /station/{id}/...: core has a catch-all there for its Vue app.
            $app->get('/music-downloader/{station_id}', Controller\PageAction::class)
                ->setName('music-downloader:page')
                ->add(new Middleware\Permissions(Permissions::DOWNLOAD, true))
                ->add(Middleware\RequireLogin::class)
                ->add(Middleware\RequireStation::class)
                ->add(Middleware\GetStation::class)
                ->add(Middleware\Auth\StandardAuth::class)
                ->add(Middleware\InjectSession::class);

            // Station API, same middleware stack as core's /api/station/{station_id} routes.
            // Route-group callbacks must NOT be static: Slim binds them to the container.
            $app->group(
                '/api/station/{station_id}/music-downloader',
                function (RouteCollectorProxy $group) {
                    $c = Controller\StationApiController::class;
                    $group->get('/access', $c . ':accessAction')->setName('music-downloader:access');
                    $group->get('/status', $c . ':statusAction')->setName('music-downloader:status');
                    $group->get('/folders', $c . ':foldersAction');
                    $group->post('/auth/start', $c . ':authStartAction');
                    $group->post('/auth/finish', $c . ':authFinishAction');
                    $group->post('/auth/logout', $c . ':authLogoutAction');
                    $group->get('/jobs', $c . ':listJobsAction')->setName('music-downloader:jobs');
                    $group->post('/jobs', $c . ':createJobAction');
                    $group->get('/jobs/{job_id:\d+}', $c . ':getJobAction');
                    $group->get('/jobs/{job_id:\d+}/log', $c . ':jobLogAction');
                    $group->post('/jobs/{job_id:\d+}/cancel', $c . ':cancelJobAction');
                    $group->post('/jobs/{job_id:\d+}/retry', $c . ':retryJobAction');
                    $group->delete('/jobs/{job_id:\d+}', $c . ':deleteJobAction');
                }
            )->add(new Middleware\Permissions(Permissions::DOWNLOAD, true))
                ->add(Middleware\RequireStation::class)
                ->add(Middleware\GetStation::class)
                ->add(Middleware\Module\Api::class)
                ->add(Middleware\Auth\ApiAuth::class)
                ->add(Middleware\InjectSession::class);

            // Server-wide settings: Spotify app credentials, YouTube cookies, proxy, defaults.
            $app->group(
                '/api/music-downloader/settings',
                function (RouteCollectorProxy $group) {
                    $s = Controller\SettingsController::class;
                    $group->get('', $s . ':getAction')->setName('music-downloader:settings');
                    $group->put('', $s . ':putAction');
                    $group->put('/cookies', $s . ':putCookiesAction');
                    $group->delete('/cookies', $s . ':deleteCookiesAction');
                    $group->post('/cookies/test', $s . ':testCookiesAction');
                }
            )->add(new Middleware\Permissions(GlobalPermissions::Settings))
                ->add(Middleware\Module\Api::class)
                ->add(Middleware\Auth\ApiAuth::class)
                ->add(Middleware\InjectSession::class);

            // "Music Downloader" button on AzuraCast's station pages, until AzuraCast Retail has a native menu item.
            if (!defined('App\Enums\StationFeatures::MusicDownloader')) {
                $app->get(\Plugin\MusicDownloader\Middleware\InjectNavButton::SCRIPT_PATH, Controller\NavScriptAction::class)
                    ->setName('music-downloader:nav-js');
                $app->add(new \Plugin\MusicDownloader\Middleware\InjectNavButton());
            }
        })
    );
};
