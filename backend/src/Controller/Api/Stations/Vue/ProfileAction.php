<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Vue;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Stations\Vue\ProfileProps;
use App\Enums\StationPermissions;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Radio\Adapters;
use App\VueComponent\NowPlayingComponent;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class ProfileAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly NowPlayingComponent $nowPlayingComponent,
        private readonly Adapters $adapters,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();

        if (!$station->is_enabled) {
            throw new RuntimeException('The station profile is disabled.');
        }

        $frontend = $this->adapters->getFrontendAdapter($station);
        $frontendConfig = $station->frontend_config;

        $router = $request->getRouter();

        $canManageBroadcasting = $request->getAcl()->isAllowed(
            StationPermissions::Broadcasting,
            $station
        );

        return $response->withJson(
            new ProfileProps(
                nowPlayingProps: $this->nowPlayingComponent->getDataProps($request),
                publicPageEmbedUrl: $router->named(
                    routeName: 'public:index',
                    routeParams: ['station_id' => $station->short_name, 'embed' => 'embed'],
                    absolute: true
                ),
                // Retail edition: no on-demand, requests or podcast embeds.
                publicOnDemandEmbedUrl: '',
                publicRequestEmbedUrl: '',
                publicHistoryEmbedUrl: $router->named(
                    routeName: 'public:history',
                    routeParams: ['station_id' => $station->short_name],
                    absolute: true
                ),
                publicScheduleEmbedUrl: $router->named(
                    routeName: 'public:schedule',
                    routeParams: ['station_id' => $station->short_name, 'embed' => 'embed'],
                    absolute: true
                ),
                publicPodcastsEmbedUrl: '',
                frontendAdminUri: $canManageBroadcasting
                    ? (string)$frontend?->getAdminUrl($station, $router->getBaseUrl())
                    : '',
                frontendAdminPassword: $canManageBroadcasting
                    ? $frontendConfig->admin_pw
                    : '',
                frontendSourcePassword: $canManageBroadcasting
                    ? $frontendConfig->source_pw
                    : '',
                frontendRelayPassword: $canManageBroadcasting
                    ? $frontendConfig->relay_pw
                    : '',
                frontendPort: $canManageBroadcasting
                    ? $frontendConfig->port
                    : null
            )
        );
    }
}
