<?php

declare(strict_types=1);

namespace App\Controller\Frontend\PublicPages;

use App\Controller\Frontend\PublicPages\Traits\IsEmbeddable;
use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\VueComponent\NowPlayingComponent;
use Psr\Http\Message\ResponseInterface;

final class PlayerAction implements SingleActionInterface
{
    use IsEmbeddable;

    public function __construct(
        private readonly NowPlayingComponent $nowPlayingComponent
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $embed = $this->isEmbedded($request, $params);

        $response = $response
            ->withHeader('X-Frame-Options', '*')
            ->withHeader('X-Robots-Tag', 'index, nofollow');

        $station = $request->getStation();

        // Build Vue props.
        $router = $request->getRouter();

        $playerProps = $this->nowPlayingComponent->getProps($request);

        // Render embedded player.
        if ($embed) {
            $pageClasses = [];
            $pageClasses[] = 'page-station-public-player-embed station-' . $station->short_name;
            $pageClasses[] = ('social' === ($params['embed'] ?? null)) ? 'embed-social' : 'embed';

            $view = $request->getView();

            // Add station public code.
            $view->fetch(
                'frontend/public/partials/station-custom',
                ['station' => $station]
            );

            return $view->renderVuePage(
                response: $response,
                component: 'Public/Player',
                id: 'station-nowplaying',
                layout: 'minimal',
                title: $station->name,
                layoutParams: [
                    'page_class' => implode(' ', $pageClasses),
                    'hide_footer' => true,
                ],
                props: $playerProps,
            );
        }

        $props = [
            'stationName' => $station->name,
            'downloadPlaylistUri' => $router->named(
                routeName: 'public:playlist',
                routeParams: ['station_id' => $station->short_name, 'format' => 'pls']
            ),
            'player' => $playerProps,
        ];

        // Auto-redirect requests from players to the playlist (PLS) download.
        $userAgent = strtolower($request->getHeaderLine('User-Agent'));
        $players = ['mpv', 'player', 'vlc', 'applecoremedia'];

        if (array_any($players, fn($player) => str_contains($userAgent, $player))) {
            return $response->withRedirect($props['downloadPlaylistUri']);
        }

        // Render full page player.
        return $request->getView()->renderToResponse(
            $response,
            'frontend/public/index',
            [
                'station' => $station,
                'props' => $props,
                'nowPlayingArtUri' => $router->named(
                    routeName: 'api:nowplaying:art',
                    routeParams: ['station_id' => $station->short_name, 'timestamp' => time()],
                    absolute: true
                ),
            ]
        );
    }
}
