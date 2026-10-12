<?php

declare(strict_types=1);

namespace Plugin\MusicDownloader\Controller;

use App\Controller\SingleActionInterface;
use App\Enums\GlobalPermissions;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Middleware\Auth\ApiAuth;
use Plugin\MusicDownloader\Service\PageAssets;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /music-downloader/{station_id} (login + "download music"). A self-contained page (templates/page.html)
 * that calls the plugin's API with the user's session; write calls carry AzuraCast's API CSRF token, like the
 * core Vue app.
 */
final class PageAction implements SingleActionInterface
{
    public const string VERSION = '0.1.3';

    public function __construct(
        private readonly PageAssets $assets,
    ) {
    }

    public function __invoke(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $query = $request->getQueryParams();

        $config = [
            'stationId' => $station->id,
            'stationName' => $station->name,
            'csrf' => $request->getCsrf()->generate(ApiAuth::API_CSRF_NAMESPACE),
            'isAdmin' => $request->getAcl()->isAllowed(GlobalPermissions::Settings),
            'flash' => isset($query['spotify']) && 'connected' === $query['spotify'] ? 'Spotify account connected.' : null,
            'flashError' => isset($query['spotify_error']) ? mb_substr((string)$query['spotify_error'], 0, 300) : null,
            'version' => self::VERSION,
        ];

        $html = strtr((string)file_get_contents(dirname(__DIR__, 2) . '/templates/page.html'), [
            '{{STATION_NAME}}' => htmlspecialchars($station->name, ENT_QUOTES),
            '{{FAVICON}}' => $this->assets->faviconTags(),
            '{{SOURCE_LINK}}' => $this->assets->sourceLink(),
            '{{CONFIG_JSON}}' => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
        ]);

        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'same-origin');
    }
}
