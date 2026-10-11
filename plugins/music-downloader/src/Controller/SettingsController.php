<?php

declare(strict_types=1);

namespace Plugin\MusicDownloader\Controller;

use App\Http\Response;
use App\Http\ServerRequest;
use Plugin\MusicDownloader\Service\SpotifyRedirect;
use Plugin\MusicDownloader\Service\WorkerClient;
use Plugin\MusicDownloader\Service\WorkerUnavailable;
use Psr\Http\Message\ResponseInterface;

/**
 * /api/music-downloader/settings (global "administer settings"): the Spotify app's Client ID/Secret,
 * YouTube cookies, proxy, audio sources and default format. Shared by every station on the server.
 */
final class SettingsController
{
    use RelaysWorker;

    public function __construct(
        private readonly WorkerClient $worker,
    ) {
    }

    public function getAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        try {
            [$status, $data] = $this->worker->call('GET', '/settings');
        } catch (WorkerUnavailable $e) {
            return $response->withJson(['message' => $e->getMessage()], 503);
        }
        if (200 !== $status) {
            return $response->withJson(['message' => self::workerMessage($data, $status)], 502);
        }
        $data['redirect'] = SpotifyRedirect::forRequest($request);
        return $response->withJson($data);
    }

    public function putAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $allowed = ['client_id', 'client_secret', 'proxy', 'audio_providers', 'default_format', 'default_bitrate'];
        return $this->relay($response, $this->worker, 'PUT', '/settings', json: array_intersect_key($body, array_flip($allowed)));
    }

    public function putCookiesAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $text = (string)($body['cookies'] ?? '');
        if ('' === trim($text)) {
            return $response->withJson(['message' => 'The cookie file is empty.'], 422);
        }

        try {
            [$status, $data] = $this->worker->call('PUT', '/settings/cookies', rawBody: $text);
        } catch (WorkerUnavailable $e) {
            return $response->withJson(['message' => $e->getMessage()], 503);
        }
        return $status >= 400
            ? $response->withJson(['message' => self::workerMessage($data, $status)], $status)
            : $response->withJson($data);
    }

    public function deleteCookiesAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->relay($response, $this->worker, 'DELETE', '/settings/cookies');
    }
}
