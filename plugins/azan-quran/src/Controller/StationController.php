<?php

declare(strict_types=1);

namespace Plugin\AzanQuran\Controller;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Repository\StationMediaRepository;
use App\Entity\Station;
use App\Enums\GlobalPermissions;
use App\Http\Response;
use App\Http\ServerRequest;
use Plugin\AzanQuran\Service\Planner;
use Plugin\AzanQuran\Service\PrayerTimes;
use Plugin\AzanQuran\Service\Store;
use Psr\Http\Message\ResponseInterface;

/**
 * /api/station/{station_id}/azan-quran/... (login + "manage azan quran"). The opening/closing times and pause
 * settings are shared by all stations; changing them needs the global "administer settings" permission.
 */
final class StationController
{
    use EntityManagerAwareTrait;

    public const array COUNTRIES = [
        'SA' => 'Saudi Arabia', 'AE' => 'United Arab Emirates', 'KW' => 'Kuwait', 'QA' => 'Qatar', 'BH' => 'Bahrain',
        'OM' => 'Oman', 'YE' => 'Yemen', 'EG' => 'Egypt', 'JO' => 'Jordan', 'PS' => 'Palestine', 'LB' => 'Lebanon',
        'SY' => 'Syria', 'IQ' => 'Iraq', 'LY' => 'Libya', 'TN' => 'Tunisia', 'DZ' => 'Algeria', 'MA' => 'Morocco',
        'SD' => 'Sudan', 'TR' => 'Turkey',
    ];

    public function __construct(
        private readonly Store $store,
        private readonly Planner $planner,
        private readonly StationMediaRepository $mediaRepo,
    ) {
    }

    public function accessAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $response->withJson(['ok' => true]);
    }

    public function stateAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $response->withJson($this->state($request));
    }

    public function saveStationAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $body = (array)$request->getParsedBody();
        $before = $this->store->getStation($station->id);

        $city = trim((string)($body['city'] ?? ''));
        $country = strtoupper(trim((string)($body['country'] ?? '')));
        $method = (int)($body['method'] ?? 0);
        $prayers = array_values(array_intersect(Store::PRAYERS, (array)($body['prayers'] ?? [])));
        $enabled = (bool)($body['is_enabled'] ?? false);

        if ($enabled && ('' === $city || '' === $country)) {
            return $response->withJson(['message' => 'Enter the city and country before turning it on.'], 422);
        }
        if (mb_strlen($city) > 100 || mb_strlen($country) > 100) {
            return $response->withJson(['message' => 'City or country is too long.'], 422);
        }
        if (!isset(PrayerTimes::METHODS[$method])) {
            $method = PrayerTimes::methodForCountry($country);
        }

        $this->store->saveStation($station->id, [
            'is_enabled' => $enabled,
            'city' => $city,
            'country' => $country,
            'method' => $method,
            'prayers' => $prayers,
        ]);

        // The fade command lives in the station's Liquidsoap configuration.
        if ($enabled !== $before['is_enabled']) {
            $this->markRestart($station);
        }

        $this->planner->plan($station);
        return $response->withJson($this->state($request));
    }

    public function saveGlobalAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        if (!$request->getAcl()->isAllowed(GlobalPermissions::Settings)) {
            return $response->withJson(['message' => 'Only administrators can change the opening hours.'], 403);
        }
        $body = (array)$request->getParsedBody();
        $before = $this->store->getGlobal();

        foreach (['opening_time', 'closing_time'] as $k) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($body[$k] ?? ''))) {
                return $response->withJson(['message' => 'Times must look like 09:00.'], 422);
            }
        }
        $values = [
            'opening_time' => (string)$body['opening_time'],
            'closing_time' => (string)$body['closing_time'],
            'quran_minutes' => max(5, min(240, (int)($body['quran_minutes'] ?? 60))),
            'fade_seconds' => max(1, min(60, (int)($body['fade_seconds'] ?? 15))),
            'pause_seconds' => max(30, min(900, (int)($body['pause_seconds'] ?? 180))),
            'pause_scope' => 'business' === ($body['pause_scope'] ?? '') ? 'business' : 'all',
        ];
        $this->store->setGlobal($values);

        foreach ($this->store->enabledStationIds() as $id) {
            $station = $this->em->find(Station::class, $id);
            if (!$station instanceof Station) {
                continue;
            }
            if ($values['fade_seconds'] !== $before['fade_seconds']) {
                $this->markRestart($station);
            }
            $this->planner->plan($station);
        }
        return $response->withJson($this->state($request));
    }

    /** Search the station's media for the Quran recording. */
    public function mediaAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, title, artist, path, length FROM station_media
             WHERE storage_location_id = ? AND (path LIKE ? OR title LIKE ? OR artist LIKE ?)
             ORDER BY length DESC, path LIMIT 40',
            [$station->media_storage_location->id, $like, $like, $like]
        );
        return $response->withJson(['media' => array_map(static fn(array $r) => [
            'id' => (int)$r['id'],
            'title' => (string)($r['title'] ?? ''),
            'artist' => (string)($r['artist'] ?? ''),
            'path' => (string)$r['path'],
            'length' => (float)$r['length'],
        ], $rows)]);
    }

    public function quranAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $id = (int)(((array)$request->getParsedBody())['media_id'] ?? 0);
        $media = $this->mediaRepo->findForStation($id, $station);
        if (null === $media) {
            return $response->withJson(['message' => 'That file is not in this station\'s media.'], 404);
        }
        $this->planner->setQuranMedia($station, $media);
        $this->planner->plan($station);
        return $response->withJson($this->state($request));
    }

    /** Fetch the times again now. */
    public function planAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $this->store->clearTimes($station->id);
        $this->planner->plan($station);
        return $response->withJson($this->state($request));
    }

    // ---------------------------------------------------------------- helpers

    private function markRestart(Station $station): void
    {
        $station->needs_restart = true;
        $this->em->persist($station);
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    private function state(ServerRequest $request): array
    {
        $station = $request->getStation();
        $this->em->refresh($station);
        $settings = $this->store->getStation($station->id);
        $quran = $this->planner->findPlaylist($station, Planner::MARK_QURAN);
        $azan = $this->planner->findPlaylist($station, Planner::MARK_AZAN);

        $quranMedia = null;
        if (null !== $quran) {
            foreach ($quran->media_items as $item) {
                $m = $item->media;
                $quranMedia = ['id' => $m->id, 'title' => (string)$m->title, 'artist' => (string)$m->artist,
                    'path' => $m->path, 'length' => $m->length];
                break;
            }
        }

        return [
            'station' => [
                'is_enabled' => $settings['is_enabled'],
                'city' => $settings['city'],
                'country' => $settings['country'],
                'method' => $settings['method'],
                'prayers' => $settings['prayers'],
                'last_planned_at' => $settings['last_planned_at'],
                'last_error' => $settings['last_error'],
                'timezone' => $station->timezone,
                'needs_restart' => $station->needs_restart,
            ],
            'global' => $global = $this->store->getGlobal(),
            'quran_window' => array_map([Planner::class, 'fmt'], Planner::quranWindow($global)),
            'can_edit_global' => $request->getAcl()->isAllowed(GlobalPermissions::Settings),
            'methods' => array_map(static fn($id, $name) => ['id' => $id, 'name' => $name], array_keys(PrayerTimes::METHODS), PrayerTimes::METHODS),
            'country_methods' => PrayerTimes::COUNTRY_METHODS,
            'countries' => self::COUNTRIES,
            'preview' => $settings['is_enabled'] || '' !== $settings['city'] ? $this->planner->preview($station) : null,
            'quran_media' => $quranMedia,
            'playlists' => ['quran' => $quran?->id, 'azan' => $azan?->id],
        ];
    }
}
