<?php

declare(strict_types=1);

namespace Plugin\AzanQuran\Controller;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Middleware\Auth\ApiAuth;
use Plugin\AzanQuran\Service\PageAssets;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /azan-quran/{station_id} (login + "manage azan quran"). A self-contained page (templates/page.html) that
 * calls the plugin's API with the user's session; write calls carry Acacia Radio's API CSRF token.
 */
final class PageAction implements SingleActionInterface
{
    public function __construct(
        private readonly PageAssets $assets,
    ) {
    }

    public function __invoke(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $config = [
            'stationId' => $station->id,
            'stationName' => $station->name,
            'csrf' => $request->getCsrf()->generate(ApiAuth::API_CSRF_NAMESPACE),
            'version' => trim((string)@file_get_contents(dirname(__DIR__, 2) . '/VERSION')),
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
