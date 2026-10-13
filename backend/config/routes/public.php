<?php

declare(strict_types=1);

use App\Controller;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Middleware;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $app) {
    $app->get(
        '/public/sw.js',
        function (ServerRequest $request, Response $response, ...$params) {
            return $response
                ->withHeader('Content-Type', 'text/javascript')
                ->write(
                    <<<'JS'
                    self.addEventListener('install', event => {
                      // Kick out the old service worker
                      self.skipWaiting();
                    });
                    JS
                );
        }
    )->setName('public:sw');

    // Source code of the running version (AGPL-3.0 section 13): redirects to the public repository.
    $app->get('/source', Controller\Frontend\SourceCodeAction::class)
        ->setName('public:source');

    $app->group(
        '/public/{station_id}',
        function (RouteCollectorProxy $group) {
            $group->get('[/{embed:embed|social}]', Controller\Frontend\PublicPages\PlayerAction::class)
                ->setName('public:index');

            $group->get('/oembed/{format:json|xml}', Controller\Frontend\PublicPages\OEmbedAction::class)
                ->setName('public:oembed');

            $group->get('/app.webmanifest', Controller\Frontend\PWA\AppManifestAction::class)
                ->setName('public:manifest');

            $group->get('/playlist[.{format}]', Controller\Frontend\PublicPages\PlaylistAction::class)
                ->setName('public:playlist');

            $group->get('/history', Controller\Frontend\PublicPages\HistoryAction::class)
                ->setName('public:history');

            $group->get('/schedule[/{embed:embed}]', Controller\Frontend\PublicPages\ScheduleAction::class)
                ->setName('public:schedule');
        }
    )
        ->add(Middleware\StationPublicPage::class)
        ->add(Middleware\EnableView::class)
        ->add(Middleware\GetStation::class);
};
