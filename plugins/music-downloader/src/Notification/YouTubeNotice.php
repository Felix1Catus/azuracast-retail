<?php

declare(strict_types=1);

namespace Plugin\MusicDownloader\Notification;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Api\Notification;
use App\Enums\FlashLevels;
use App\Enums\GlobalPermissions;
use App\Event\GetNotifications;
use Plugin\MusicDownloader\Service\WorkerClient;

/**
 * Dashboard notice for settings admins when the Music Downloader's YouTube account needs a new sign-in
 * (or its browser is unreachable). Silent when the worker isn't set up or doesn't answer quickly.
 */
final class YouTubeNotice
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly WorkerClient $worker,
    ) {
    }

    public function __invoke(GetNotifications $event): void
    {
        try {
            $request = $event->getRequest();
            if (!WorkerClient::isConfigured() || !$request->getAcl()->isAllowed(GlobalPermissions::Settings)) {
                return;
            }

            [$status, $data] = $this->worker->call('GET', '/youtube', timeout: 3.0);
            if (200 !== $status || empty($data['connected_once'])) {
                return;
            }

            $title = match ($data['status'] ?? '') {
                'expired' => 'YouTube account signed out',
                'error' => 'YouTube account check failed',
                default => null,
            };
            if (null === $title) {
                return;
            }

            $stationId = $this->em->getConnection()->fetchOne('SELECT id FROM station ORDER BY id LIMIT 1');
            $event->addNotification(
                new Notification(
                    id: 'notification-music-downloader-youtube',
                    title: $title,
                    body: (string)($data['message'] ?? '')
                        . ' Until then, Music Downloader may not be able to download from YouTube.',
                    type: FlashLevels::Warning,
                    actionLabel: false !== $stationId ? 'Open Music Downloader settings' : null,
                    actionUrl: false !== $stationId
                        ? $request->getRouter()->named('music-downloader:page', ['station_id' => (int)$stationId])
                            . '#settings'
                        : null,
                )
            );
        } catch (\Throwable $e) {
            error_log('[music-downloader] YouTube notice failed: ' . $e->getMessage());
        }
    }
}
