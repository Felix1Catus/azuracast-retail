<?php

declare(strict_types=1);

namespace Plugin\MusicDownloader\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Talks to the spotDL worker container over the internal Docker network.
 *   MUSIC_DOWNLOADER_WORKER_URL    default http://music-downloader:8000
 *   MUSIC_DOWNLOADER_WORKER_TOKEN  shared secret, set on both containers by install.sh
 */
final class WorkerClient
{
    public function __construct(
        private readonly Client $http,
    ) {
    }

    public static function baseUrl(): string
    {
        $url = getenv('MUSIC_DOWNLOADER_WORKER_URL');
        return rtrim(is_string($url) && '' !== $url ? $url : 'http://music-downloader:8000', '/');
    }

    private static function token(): string
    {
        $token = getenv('MUSIC_DOWNLOADER_WORKER_TOKEN');
        return is_string($token) ? $token : '';
    }

    /** False on servers where install.sh hasn't set up the worker (the plugin code alone ships in the fork image). */
    public static function isConfigured(): bool
    {
        return '' !== self::token();
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, mixed>|null $json
     * @return array{0: int, 1: array<string, mixed>} HTTP status and decoded body
     */
    public function call(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        ?string $rawBody = null,
        float $timeout = 20.0,
    ): array {
        if (!self::isConfigured()) {
            throw new WorkerUnavailable(
                'The Music Downloader worker is not set up on this server (MUSIC_DOWNLOADER_WORKER_TOKEN is missing). '
                . 'Run the plugin installer again.'
            );
        }

        $options = [
            'http_errors' => false,
            'timeout' => $timeout,
            'connect_timeout' => 3,
            'headers' => [
                'Authorization' => 'Bearer ' . self::token(),
                'Accept' => 'application/json',
            ],
        ];
        if ([] !== $query) {
            $options['query'] = $query;
        }
        if (null !== $json) {
            $options['json'] = $json;
        }
        if (null !== $rawBody) {
            $options['body'] = $rawBody;
            $options['headers']['Content-Type'] = 'text/plain; charset=utf-8';
        }

        try {
            $res = $this->http->request($method, self::baseUrl() . $path, $options);
        } catch (GuzzleException $e) {
            throw new WorkerUnavailable(
                'The Music Downloader worker is not reachable. Check that its container is running '
                . '(docker compose ps music-downloader).',
                0,
                $e
            );
        }

        $data = json_decode((string)$res->getBody(), true);
        return [$res->getStatusCode(), is_array($data) ? $data : []];
    }
}
