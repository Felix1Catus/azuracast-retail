<?php

declare(strict_types=1);

namespace Plugin\MusicDownloader\Controller;

use App\Enums\GlobalPermissions;
use App\Http\Response;
use App\Http\ServerRequest;
use Plugin\MusicDownloader\Service\MediaFolders;
use Plugin\MusicDownloader\Service\SpotifyRedirect;
use Plugin\MusicDownloader\Service\WorkerClient;
use Plugin\MusicDownloader\Service\WorkerUnavailable;
use Psr\Http\Message\ResponseInterface;

/**
 * /api/station/{station_id}/music-downloader/... (Acacia Radio login or API key, permission "download music").
 * The station and user come from Acacia Radio's request; the browser never chooses them.
 */
final class StationApiController
{
    use RelaysWorker;

    public function __construct(
        private readonly WorkerClient $worker,
        private readonly MediaFolders $folders,
    ) {
    }

    /** Lightweight probe for the nav button: the user has the permission and the worker is set up. */
    public function accessAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        if (!WorkerClient::isConfigured()) {
            return $response->withJson(['ok' => false, 'message' => 'The Music Downloader worker is not installed.'], 503);
        }
        return $response->withJson(['ok' => true]);
    }

    public function statusAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $user = $request->getUser();

        $result = [
            'worker' => ['ok' => false, 'message' => null, 'versions' => null],
            'settings' => null,
            'account' => ['connected' => false],
            'storage' => ['supported' => true, 'message' => $this->folders->unsupportedReason($station)],
            'redirect' => SpotifyRedirect::forRequest($request),
            'isAdmin' => $request->getAcl()->isAllowed(GlobalPermissions::Settings),
        ];
        $result['storage']['supported'] = null === $result['storage']['message'];

        try {
            [$hs, $health] = $this->worker->call('GET', '/health', timeout: 5);
            [$ss, $settings] = $this->worker->call('GET', '/settings', timeout: 5);
            [$as, $account] = $this->worker->call('GET', '/accounts/' . $user->id, timeout: 5);
            $result['worker'] = [
                'ok' => 200 === $hs && 200 === $ss,
                'message' => 200 === $ss ? null : self::workerMessage($settings, $ss),
                'versions' => $health['versions'] ?? null,
            ];
            if (200 === $ss) {
                unset($settings['proxy']);  // admin-only detail
                $result['settings'] = $settings;
            }
            if (200 === $as) {
                $result['account'] = $account;
            }
        } catch (WorkerUnavailable $e) {
            $result['worker']['message'] = $e->getMessage();
        }

        return $response->withJson($result);
    }

    public function foldersAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        if (null === $this->folders->localRoot($station)) {
            return $response->withJson(['message' => $this->folders->unsupportedReason($station)], 409);
        }
        return $response->withJson(['folders' => $this->folders->listFolders($station)]);
    }

    // ------------------------------------------------------------ Spotify login

    public function authStartAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $redirect = SpotifyRedirect::forRequest($request);

        return $this->relay($response, $this->worker, 'POST', '/auth/start', json: [
            'user_id' => $request->getUser()->id,
            'redirect_uri' => $redirect['uri'],
            'return_to' => '/music-downloader/' . $station->id,
        ]);
    }

    /** Manual mode: the user pastes the address Spotify sent them to (it contains ?code=...&state=...). */
    public function authFinishAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $pasted = trim((string)($body['url'] ?? ''));
        parse_str((string)parse_url($pasted, PHP_URL_QUERY), $query);

        if (isset($query['error'])) {
            return $response->withJson(['message' => 'Spotify reported: ' . (string)$query['error']], 400);
        }
        if (!isset($query['code'], $query['state']) || !is_string($query['code']) || !is_string($query['state'])) {
            return $response->withJson([
                'message' => 'Paste the whole address from the browser\'s address bar after approving on Spotify. '
                    . 'It starts with ' . SpotifyRedirect::LOOPBACK_URI . '?code=',
            ], 422);
        }

        return $this->relay($response, $this->worker, 'POST', '/auth/finish', json: [
            'user_id' => $request->getUser()->id,
            'code' => $query['code'],
            'state' => $query['state'],
        ]);
    }

    public function authLogoutAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->relay($response, $this->worker, 'DELETE', '/accounts/' . $request->getUser()->id);
    }

    // ------------------------------------------------------------ jobs

    public function listJobsAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->relay($response, $this->worker, 'GET', '/jobs', ['station_id' => $request->getStation()->id]);
    }

    public function createJobAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $user = $request->getUser();
        $body = (array)$request->getParsedBody();

        $root = $this->folders->localRoot($station);
        if (null === $root) {
            return $response->withJson(['message' => $this->folders->unsupportedReason($station)], 409);
        }

        $folder = MediaFolders::normalize((string)($body['folder'] ?? ''));
        $newFolder = MediaFolders::normalize((string)($body['new_folder'] ?? ''));
        if (null === $folder || null === $newFolder || str_contains($newFolder, '/')) {
            return $response->withJson(['message' => 'That folder name isn\'t allowed (no "..", leading dots or "/" in a new folder name).'], 422);
        }
        $relative = trim($folder . '/' . $newFolder, '/');

        return $this->relay($response, $this->worker, 'POST', '/jobs', json: [
            'station_id' => $station->id,
            'url' => (string)($body['url'] ?? ''),
            'dest_dir' => $root . ('' === $relative ? '' : '/' . $relative),
            'dest_label' => '/' . $relative,
            'format' => (string)($body['format'] ?? 'mp3'),
            'bitrate' => (string)($body['bitrate'] ?? 'auto'),
            'user_id' => $user->id,
            'user_name' => $user->name ?? $user->email,
            'use_login' => (bool)($body['use_login'] ?? true),
        ], successStatus: 201);
    }

    public function getJobAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->relay($response, $this->worker, 'GET', '/jobs/' . (int)$params['job_id'], ['station_id' => $request->getStation()->id]);
    }

    public function jobLogAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->relay($response, $this->worker, 'GET', '/jobs/' . (int)$params['job_id'] . '/log', ['station_id' => $request->getStation()->id]);
    }

    public function cancelJobAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->relay($response, $this->worker, 'POST', '/jobs/' . (int)$params['job_id'] . '/cancel', ['station_id' => $request->getStation()->id]);
    }

    public function retryJobAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->relay($response, $this->worker, 'POST', '/jobs/' . (int)$params['job_id'] . '/retry', ['station_id' => $request->getStation()->id]);
    }

    public function deleteJobAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->relay($response, $this->worker, 'DELETE', '/jobs/' . (int)$params['job_id'], ['station_id' => $request->getStation()->id]);
    }
}
