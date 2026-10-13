<?php

declare(strict_types=1);

namespace Plugin\AzanQuran\Service;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Repository\StationMediaRepository;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Media\MediaProcessor;
use DateTimeImmutable;

/**
 * Keeps two playlists per station that hold the plugin's audio, and works out what happens when:
 *
 *   "Azan pause"  a silent MP3 of the pause length (generated with ffmpeg). Jingle mode keeps the previous
 *                 song's title on the players while it plays.
 *   "Quran"       the Quran recording chosen on the plugin's page.
 *
 * Both playlists stay disabled and unscheduled: Sync\CueTask pushes their file into Liquidsoap's interrupting
 * queue at the prayer minute and at the Quran's start, which cuts in at once, and fades the Quran into the music
 * at opening time. (Scheduled interrupting playlists would also be written into Liquidsoap's configuration, which
 * is only reloaded on a restart, and could play twice; daily-changing prayer times don't fit that.)
 */
final class Planner
{
    use EntityManagerAwareTrait;

    public const string MARK_AZAN = '[azan-quran:azan]';
    public const string MARK_QURAN = '[azan-quran:quran]';
    public const string FOLDER = 'Azan & Quran';

    public function __construct(
        private readonly Store $store,
        private readonly PrayerTimes $times,
        private readonly MediaProcessor $mediaProcessor,
        private readonly StationMediaRepository $mediaRepo,
        private readonly StationPlaylistMediaRepository $playlistMediaRepo,
    ) {
    }

    /** Checks one station is ready (playlists, silence, prayer times cached); returns the error shown in the page. */
    public function plan(Station $station): ?string
    {
        $settings = $this->store->getStation($station->id);
        $global = $this->store->getGlobal();

        if (!$settings['is_enabled']) {
            return null;
        }

        $azan = $this->findPlaylist($station, self::MARK_AZAN)
            ?? $this->createPlaylist($station, 'Azan pause', self::MARK_AZAN . ' Silence at prayer times. Managed by the Azan & Quran plugin: change it there.', true);
        $quran = $this->findPlaylist($station, self::MARK_QURAN)
            ?? $this->createPlaylist($station, 'Quran', self::MARK_QURAN . ' Plays before opening time. Managed by the Azan & Quran plugin: put the recording in it from there.', false);

        $error = null;
        try {
            $this->keepOffSchedule($azan);
            $this->keepOffSchedule($quran);
            $this->ensureSilence($station, $azan, (int)$global['pause_seconds']);
            // Warm the cache for today and tomorrow, so a short outage doesn't matter.
            $tz = $station->getTimezoneObject();
            $today = new DateTimeImmutable('today', $tz);
            $this->times->forDay($settings, $today);
            $this->times->forDay($settings, $today->modify('+1 day'));
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->em->flush();
        $this->store->setStatus($station->id, $error);
        return $error;
    }

    /**
     * Today's events for a station, in its own time zone, as time codes (HHMM):
     * the Quran start, each prayer pause (not inside the Quran window), and opening time (the fade).
     *
     * @return array<int, array{at: int, kind: string, prayer: ?string}>
     */
    public function eventsFor(Station $station, DateTimeImmutable $day): array
    {
        $settings = $this->store->getStation($station->id);
        $global = $this->store->getGlobal();
        [$qStart, $qEnd] = self::quranWindow($global);

        $events = [
            ['at' => $qStart, 'kind' => 'quran', 'prayer' => null],
            ['at' => $qEnd, 'kind' => 'fade', 'prayer' => null],
        ];
        foreach ($this->pausesFor($this->times->forDay($settings, $day), $settings, $global) as $p) {
            if (null === $p['reason']) {
                $events[] = ['at' => $p['start'], 'kind' => 'pause', 'prayer' => $p['prayer']];
            }
        }
        return $events;
    }

    /**
     * What today looks like (for the page).
     *
     * @return array{date: string, times: ?array, pauses: array<int, array{prayer: string, start: string, end: string}>, skipped: array<int, array{prayer: string, time: string, reason: string}>, quran: array{start: string, end: string}, timezone_warning: ?string, error: ?string}
     */
    public function preview(Station $station): array
    {
        $settings = $this->store->getStation($station->id);
        $global = $this->store->getGlobal();
        $tz = $station->getTimezoneObject();
        $today = new DateTimeImmutable('today', $tz);

        [$qStart, $qEnd] = self::quranWindow($global);
        $result = [
            'date' => $today->format('Y-m-d'),
            'times' => null,
            'pauses' => [],
            'skipped' => [],
            'quran' => ['start' => self::fmt($qStart), 'end' => self::fmt($qEnd)],
            'timezone_warning' => null,
            'error' => null,
        ];

        try {
            $times = $this->times->forDay($settings, $today);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            return $result;
        }
        $result['times'] = $times;
        if (PrayerTimes::timezoneMismatch($times['timezone'], $tz)) {
            $result['timezone_warning'] = sprintf(
                'The city is in the %s time zone but this station uses %s, so the pauses would be at the wrong time. '
                . 'Set the station\'s time zone in its profile.',
                $times['timezone'],
                $tz->getName()
            );
        }
        foreach ($this->pausesFor($times, $settings, $global) as $p) {
            if (null === $p['reason']) {
                $result['pauses'][] = ['prayer' => $p['prayer'], 'start' => self::fmt($p['start']), 'end' => self::fmt($p['end'])];
            } else {
                $result['skipped'][] = ['prayer' => $p['prayer'], 'time' => self::fmt($p['start']), 'reason' => $p['reason']];
            }
        }
        return $result;
    }

    public function findPlaylist(Station $station, string $mark): ?StationPlaylist
    {
        foreach ($station->playlists as $playlist) {
            if (str_starts_with((string)$playlist->description, $mark)) {
                return $playlist;
            }
        }
        return null;
    }

    /** Make $media the Quran playlist's only recording. */
    public function setQuranMedia(Station $station, StationMedia $media): void
    {
        $quran = $this->findPlaylist($station, self::MARK_QURAN)
            ?? $this->createPlaylist($station, 'Quran', self::MARK_QURAN . ' Plays before opening time. Managed by the Azan & Quran plugin: put the recording in it from there.', false);
        $this->em->flush();
        $this->playlistMediaRepo->emptyPlaylist($quran);
        $this->playlistMediaRepo->addMediaToPlaylist($media, $quran);
        $this->em->flush();
    }

    // ---------------------------------------------------------------- internals

    private function createPlaylist(Station $station, string $name, string $description, bool $jingle): StationPlaylist
    {
        $playlist = new StationPlaylist($station);
        $playlist->name = $name;
        $playlist->description = $description;
        $playlist->type = PlaylistTypes::Standard;
        $playlist->source = PlaylistSources::Songs;
        $playlist->order = PlaylistOrders::Sequential;
        $playlist->is_jingle = $jingle;
        $playlist->include_in_requests = false;
        $playlist->include_in_on_demand = false;
        $playlist->avoid_duplicates = false;
        $playlist->is_enabled = false;
        $this->em->persist($playlist);
        $station->playlists->add($playlist);
        return $playlist;
    }

    private function ensureSilence(Station $station, StationPlaylist $azan, int $seconds): void
    {
        $path = sprintf('%s/azan-pause-%ds.mp3', self::FOLDER, $seconds);
        $storage = $station->media_storage_location;
        $media = $this->mediaRepo->findByPath($path, $storage);

        if (!$media instanceof StationMedia) {
            $tmp = tempnam(sys_get_temp_dir(), 'azan') . '.mp3';
            $cmd = sprintf(
                'ffmpeg -nostdin -loglevel error -y -f lavfi -i anullsrc=r=44100:cl=stereo -t %d -c:a libmp3lame -b:a 64k '
                . '-metadata title=%s -metadata artist=%s %s 2>&1',
                $seconds,
                escapeshellarg('Azan pause'),
                escapeshellarg('Acacia Radio'),
                escapeshellarg($tmp)
            );
            exec($cmd, $out, $code);
            if (0 !== $code || !is_file($tmp)) {
                throw new \RuntimeException('Could not create the silent pause file: ' . implode(' ', $out));
            }
            $media = $this->mediaProcessor->processAndUpload($storage, $path, $tmp);
            if (!$media instanceof StationMedia) {
                throw new \RuntimeException('Could not add the silent pause file to the media library.');
            }
        }

        $current = [];
        foreach ($azan->media_items as $item) {
            $current[] = $item->media->id;
        }
        if ($current !== [$media->id]) {
            if ([] !== $current) {
                $this->playlistMediaRepo->emptyPlaylist($azan);
            }
            $this->playlistMediaRepo->addMediaToPlaylist($media, $azan);
        }
    }

    /** The AutoDJ and Liquidsoap must never play these on their own (see the class comment). */
    private function keepOffSchedule(StationPlaylist $playlist): void
    {
        $playlist->is_enabled = false;
        $playlist->backend_options = [];
        foreach ($playlist->schedule_items->toArray() as $item) {
            $playlist->schedule_items->removeElement($item);
            $this->em->remove($item);
        }
        $this->em->persist($playlist);
    }

    /**
     * Each enabled prayer of one day as [start, end] time codes (HHMM), with the reason it's skipped, if any.
     *
     * @return array<int, array{prayer: string, start: int, end: int, reason: ?string}>
     */
    private function pausesFor(array $times, array $settings, array $global): array
    {
        [$qStart, $qEnd] = self::quranWindow($global);
        $closing = self::toCode((string)$global['closing_time']);
        $minutes = (int)ceil((int)$global['pause_seconds'] / 60);

        $result = [];
        foreach (Store::PRAYERS as $prayer) {
            $start = self::toCode($times[$prayer]);
            $reason = null;
            if (!in_array($prayer, $settings['prayers'], true)) {
                $reason = 'turned off';
            } elseif (self::inWindow($start, $qStart, $qEnd)) {
                $reason = 'during the Quran';
            } elseif ('business' === $global['pause_scope'] && !self::inWindow($start, $qStart, $closing)) {
                $reason = 'outside business hours';
            }
            $result[] = ['prayer' => $prayer, 'start' => $start, 'end' => self::addMinutes($start, $minutes), 'reason' => $reason];
        }
        return $result;
    }

    /** @return array{0: int, 1: int} the Quran's [start, end] time codes */
    public static function quranWindow(array $global): array
    {
        $opening = self::toCode((string)$global['opening_time']);
        return [self::addMinutes($opening, -(int)$global['quran_minutes']), $opening];
    }

    /** "09:30" -> 930 */
    public static function toCode(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [0, 0]);
        return $h * 100 + $m;
    }

    public static function fmt(int $code): string
    {
        return sprintf('%02d:%02d', intdiv($code, 100), $code % 100);
    }

    public static function addMinutes(int $code, int $minutes): int
    {
        $total = ((intdiv($code, 100) * 60 + $code % 100 + $minutes) % 1440 + 1440) % 1440;
        return intdiv($total, 60) * 100 + $total % 60;
    }

    /** Is $t in [$start, $end)? Windows may wrap past midnight. */
    private static function inWindow(int $t, int $start, int $end): bool
    {
        return $start <= $end ? ($t >= $start && $t < $end) : ($t >= $start || $t < $end);
    }
}
