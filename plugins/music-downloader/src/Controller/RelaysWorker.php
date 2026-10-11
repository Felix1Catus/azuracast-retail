<?php

declare(strict_types=1);

namespace Plugin\MusicDownloader\Controller;

use App\Http\Response;
use Plugin\MusicDownloader\Service\WorkerClient;
use Plugin\MusicDownloader\Service\WorkerUnavailable;
use Psr\Http\Message\ResponseInterface;

/** Shared by the API controllers: call the worker and pass its answer on, errors as {"message": "..."}. */
trait RelaysWorker
{
    /**
     * @param array<string, scalar> $query
     * @param array<string, mixed>|null $json
     */
    private function relay(
        Response $response,
        WorkerClient $worker,
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        int $successStatus = 200,
    ): ResponseInterface {
        try {
            [$status, $data] = $worker->call($method, $path, $query, $json);
        } catch (WorkerUnavailable $e) {
            return $response->withJson(['message' => $e->getMessage()], 503);
        }

        if ($status >= 400) {
            return $response->withJson(['message' => self::workerMessage($data, $status)], $status >= 500 ? 502 : $status);
        }
        return $response->withJson($data, 201 === $status ? 201 : $successStatus);
    }

    /** @param array<string, mixed> $data */
    private static function workerMessage(array $data, int $status): string
    {
        $detail = $data['detail'] ?? null;
        if (is_string($detail)) {
            return $detail;
        }
        if (is_array($detail) && isset($detail[0]['msg'])) {
            // FastAPI validation error: [{"loc": [...], "msg": "..."}]
            $field = is_array($detail[0]['loc'] ?? null) ? (string)end($detail[0]['loc']) : '';
            return trim($field . ': ' . $detail[0]['msg'], ': ');
        }
        return 'The downloader answered with an error (HTTP ' . $status . ').';
    }
}
