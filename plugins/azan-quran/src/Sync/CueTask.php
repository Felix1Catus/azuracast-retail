<?php

declare(strict_types=1);

namespace Plugin\AzanQuran\Sync;

use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\Adapters;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use App\Sync\Task\AbstractTask;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Plugin\AzanQuran\Service\Planner;
use Plugin\AzanQuran\Service\Store;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Every minute, for each station that uses the plugin (station time):
 *   quran  at (opening - Quran length): push the Quran recording into the interrupting queue
 *   pause  at each prayer time outside the Quran window: push the silent file the same way
 *   fade   at opening time, if the Quran is still playing: Liquidsoap "azan_quran.fade_skip"
 *          (Radio\LiquidsoapFade; a plain skip if the station hasn't been restarted to load it)
 *
 * The interrupting queue (core's LiquidsoapQueues::Interrupting) cuts the current song at once. A cue is
 * also sent in the following minute if the sync ran late; Store::claimCue makes sure it goes out only once.
 */
final class CueTask extends AbstractTask
{
    public function __construct(
        private readonly Store $store,
        private readonly Planner $planner,
        private readonly Adapters $adapters,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return '* * * * *';
    }

    public function run(bool $force = false): void
    {
        try {
            $ids = $this->store->enabledStationIds();
        } catch (TableNotFoundException) {
            return;
        }

        foreach ($ids as $id) {
            $station = $this->em->find(Station::class, $id);
            if (!$station instanceof Station || !$station->supportsAutoDjQueue()) {
                continue;
            }
            try {
                $this->runFor($station);
            } catch (\Throwable $e) {
                $this->logger->error('Azan & Quran: cue failed', ['station' => $station->short_name, 'error' => $e->getMessage()]);
            }
        }
    }

    private function runFor(Station $station): void
    {
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $today = $now->setTime(0, 0);
        $nowCode = (int)$now->format('Hi');
        $late = Planner::addMinutes($nowCode, -1);

        $due = [];
        foreach ($this->planner->eventsFor($station, $today) as $event) {
            if ($event['at'] === $nowCode || $event['at'] === $late) {
                $due[$event['kind']] = $event;
            }
        }
        if ([] === $due) {
            return;
        }
        // A prayer at opening time: the pause interrupts the Quran itself, so no fade.
        if (isset($due['pause'])) {
            unset($due['fade']);
        }

        $backend = $this->adapters->getBackendAdapter($station);
        if (!$backend instanceof Liquidsoap) {
            return;
        }

        foreach ($due as $kind => $event) {
            if (!$this->store->claimCue($station->id, sprintf('%s %04d %s', $today->format('Y-m-d'), $event['at'], $kind))) {
                continue;
            }
            match ($kind) {
                'quran' => $this->push($station, $backend, Planner::MARK_QURAN, 'Quran'),
                'pause' => $this->push($station, $backend, Planner::MARK_AZAN, 'Azan pause (' . $event['prayer'] . ')'),
                'fade' => $this->fade($station, $backend),
                default => null,
            };
        }
    }

    private function push(Station $station, Liquidsoap $backend, string $mark, string $label): void
    {
        $playlist = $this->planner->findPlaylist($station, $mark);
        $media = $playlist instanceof StationPlaylist ? $this->firstMedia($playlist) : null;
        if (null === $media) {
            $this->logger->warning('Azan & Quran: nothing to play', ['station' => $station->short_name, 'cue' => $label]);
            return;
        }

        $sq = StationQueue::fromMedia($station, $media);
        $sq->playlist = $playlist;
        $sq->is_played = true;
        $sq->updateVisibility();
        $this->em->persist($sq);
        $this->em->flush();

        $event = AnnotateNextSong::fromStationQueue($sq, true);
        $this->dispatcher->dispatch($event);
        $backend->enqueue($station, LiquidsoapQueues::Interrupting, $event->buildAnnotations());
        $this->logger->info('Azan & Quran: ' . $label, ['station' => $station->short_name]);
    }

    private function firstMedia(StationPlaylist $playlist): ?StationMedia
    {
        foreach ($playlist->media_items as $item) {
            return $item->media;
        }
        return null;
    }

    private function fade(Station $station, Liquidsoap $backend): void
    {
        $quran = $this->planner->findPlaylist($station, Planner::MARK_QURAN);
        $current = $this->em->getConnection()->fetchOne(
            'SELECT playlist_id FROM song_history WHERE station_id = ? ORDER BY timestamp_start DESC LIMIT 1',
            [$station->id]
        );
        if (null === $quran || (int)$current !== $quran->id) {
            return; // the Quran already ended (shorter recording) or was never played
        }

        try {
            $answer = implode(' ', $backend->command($station, 'azan_quran.fade_skip'));
            if (!str_contains($answer, 'OK')) {
                throw new \RuntimeException($answer);
            }
            $this->logger->info('Azan & Quran: faded the Quran into music', ['station' => $station->short_name]);
        } catch (\Throwable $e) {
            $this->logger->warning('Azan & Quran: fade command failed (restart the station to load it); skipping instead', ['error' => $e->getMessage()]);
            $backend->skip($station);
        }
    }
}
