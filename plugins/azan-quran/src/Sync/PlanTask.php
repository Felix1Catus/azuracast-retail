<?php

declare(strict_types=1);

namespace Plugin\AzanQuran\Sync;

use App\Entity\Station;
use App\Sync\Task\AbstractTask;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Plugin\AzanQuran\Service\Planner;
use Plugin\AzanQuran\Service\Store;

/**
 * Every hour: make sure every station that uses the plugin is ready (its playlists and silent file exist, and
 * the prayer times for today and tomorrow are cached). Hourly rather than nightly: each station's midnight is at
 * a different time, and a failed fetch is retried soon. Sync\CueTask does the playing.
 */
final class PlanTask extends AbstractTask
{
    public function __construct(
        private readonly Store $store,
        private readonly Planner $planner,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return '3 * * * *';
    }

    public function run(bool $force = false): void
    {
        try {
            $ids = $this->store->enabledStationIds();
        } catch (TableNotFoundException) {
            return; // plugin installed but migrations not run yet
        }
        foreach ($ids as $id) {
            $station = $this->em->find(Station::class, $id);
            if (!$station instanceof Station) {
                continue;
            }
            $error = $this->planner->plan($station);
            if (null !== $error) {
                $this->logger->warning('Azan & Quran: planning failed', ['station' => $station->short_name, 'error' => $error]);
            }
        }
    }
}
