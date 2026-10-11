<?php

declare(strict_types=1);

namespace Plugin\MusicDownloader\Controller;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use Plugin\MusicDownloader\Service\WorkerClient;
use Plugin\MusicDownloader\Service\WorkerUnavailable;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /music-downloader/oauth/callback?code=...&state=... (AzuraCast login required).
 * Spotify sends the browser here after the user approves (HTTPS sites only; see SpotifyRedirect). The worker
 * checks that the state belongs to this AzuraCast user, swaps the code for a token and stores it.
 */
final class OAuthCallbackAction implements SingleActionInterface
{
    public function __construct(
        private readonly WorkerClient $worker,
    ) {
    }

    public function __invoke(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $query = $request->getQueryParams();
        $back = '/dashboard';

        if (isset($query['error'])) {
            $reason = 'access_denied' === $query['error'] ? 'You cancelled the Spotify login.' : 'Spotify reported: ' . (string)$query['error'];
            return $this->redirect($response, $back, ['spotify_error' => $reason]);
        }
        if (!isset($query['code'], $query['state']) || !is_string($query['code']) || !is_string($query['state'])) {
            return $this->redirect($response, $back, ['spotify_error' => 'The Spotify login didn\'t return a code.']);
        }

        try {
            [$status, $data] = $this->worker->call('POST', '/auth/finish', json: [
                'user_id' => $request->getUser()->id,
                'code' => $query['code'],
                'state' => $query['state'],
            ]);
        } catch (WorkerUnavailable $e) {
            return $this->redirect($response, $back, ['spotify_error' => $e->getMessage()]);
        }

        $returnTo = is_string($data['return_to'] ?? null) && preg_match('#^/music-downloader/\d+$#', $data['return_to'])
            ? $data['return_to']
            : $back;

        if ($status >= 400) {
            $message = is_string($data['detail'] ?? null) ? $data['detail'] : 'The Spotify login failed (HTTP ' . $status . ').';
            return $this->redirect($response, $returnTo, ['spotify_error' => $message]);
        }
        return $this->redirect($response, $returnTo, ['spotify' => 'connected']);
    }

    /** @param array<string, string> $query */
    private function redirect(Response $response, string $path, array $query): ResponseInterface
    {
        return $response->withStatus(302)->withHeader('Location', $path . '?' . http_build_query($query));
    }
}
