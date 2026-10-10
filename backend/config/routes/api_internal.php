<?php

declare(strict_types=1);

use App\Controller;
use App\Middleware;
use Slim\Routing\RouteCollectorProxy;

// Internal API endpoints (called by other programs hosted on the same machine).
return static function (RouteCollectorProxy $group) {
    $group->group(
        '/internal',
        function (RouteCollectorProxy $group) {
            $group->group(
                '/{station_id}',
                function (RouteCollectorProxy $group) {
                    $group->map(
                        ['GET', 'POST'],
                        '/liquidsoap/{action}',
                        Controller\Api\Internal\LiquidsoapAction::class
                    )->setName('api:internal:liquidsoap')
                        ->add(Middleware\RequireInternalConnection::class);

                    // Icecast internal auth functions
                    $group->map(
                        ['GET', 'POST'],
                        '/listener-auth[/{api_auth}]',
                        Controller\Api\Internal\ListenerAuthAction::class
                    )->setName('api:internal:listener-auth');

                    // NGINX auth_request handler for HLS
                    $group->map(
                        ['GET', 'POST'],
                        '/hls-listener-auth[/{api_auth}]',
                        Controller\Api\Internal\HlsListenerAuthAction::class
                    )->setName('api:internal:hls-listener-auth');
                }
            )->add(Middleware\GetStation::class);
        }
    );
};
