<?php

declare(strict_types=1);

namespace Plugin\AzanQuran\Service;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;

/**
 * Daily prayer times from the Aladhan API (api.aladhan.com: free, no key). A whole month is fetched at once
 * and cached (Store), so a few days without internet don't skip any prayer.
 */
final class PrayerTimes
{
    public const int DEFAULT_METHOD = 4;

    /** Aladhan calculation methods, the ones used in the region first. */
    public const array METHODS = [
        4 => 'Umm Al-Qura University, Makkah',
        16 => 'Dubai (UAE)',
        8 => 'Gulf Region',
        9 => 'Kuwait',
        10 => 'Qatar',
        5 => 'Egyptian General Authority of Survey',
        23 => 'Ministry of Awqaf, Jordan',
        21 => 'Morocco',
        18 => 'Tunisia',
        19 => 'Algeria',
        13 => 'Diyanet, Turkey',
        3 => 'Muslim World League',
        1 => 'University of Islamic Sciences, Karachi',
        2 => 'Islamic Society of North America',
        15 => 'Moonsighting Committee Worldwide',
    ];

    /** The usual method per country (ISO code or English name), used when a station is first set up. */
    public const array COUNTRY_METHODS = [
        'SA' => 4, 'AE' => 16, 'KW' => 9, 'QA' => 10, 'BH' => 8, 'OM' => 8, 'YE' => 4,
        'EG' => 5, 'JO' => 23, 'PS' => 23, 'MA' => 21, 'TN' => 18, 'DZ' => 19, 'TR' => 13,
        'IQ' => 3, 'SY' => 3, 'LB' => 3, 'LY' => 3, 'SD' => 5,
    ];

    public function __construct(
        private readonly Client $http,
        private readonly Store $store,
    ) {
    }

    public static function methodForCountry(string $country): int
    {
        return self::COUNTRY_METHODS[strtoupper(trim($country))] ?? 3;
    }

    /**
     * Times for one day in the station's own time zone, fetched (whole month) when not cached yet.
     *
     * @return array{fajr: string, dhuhr: string, asr: string, maghrib: string, isha: string, timezone: string}
     */
    public function forDay(array $settings, DateTimeImmutable $day): array
    {
        $key = $day->format('Y-m-d');
        $cached = $this->store->getTimes($settings['station_id'], $key);
        if (null !== $cached) {
            return $cached;
        }

        $this->fetchMonth($settings, (int)$day->format('Y'), (int)$day->format('n'));
        $cached = $this->store->getTimes($settings['station_id'], $key);
        if (null === $cached) {
            throw new \RuntimeException(sprintf('The prayer times service returned nothing for %s.', $key));
        }
        return $cached;
    }

    public function fetchMonth(array $settings, int $year, int $month): void
    {
        if ('' === $settings['city'] || '' === $settings['country']) {
            throw new \RuntimeException('Set the station\'s city and country first.');
        }

        try {
            $res = $this->http->request('GET', sprintf('https://api.aladhan.com/v1/calendarByCity/%d/%d', $year, $month), [
                'query' => ['city' => $settings['city'], 'country' => $settings['country'], 'method' => $settings['method']],
                'timeout' => 20,
                'connect_timeout' => 10,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('The prayer times service (api.aladhan.com) is not reachable: ' . $e->getMessage(), 0, $e);
        }

        $body = json_decode((string)$res->getBody(), true);
        if (200 !== $res->getStatusCode() || !is_array($body['data'] ?? null)) {
            $detail = is_string($body['data'] ?? null) ? $body['data'] : 'HTTP ' . $res->getStatusCode();
            throw new \RuntimeException('The prayer times service refused the request: ' . $detail
                . '. Check the city and country spelling (English names, e.g. Riyadh / Saudi Arabia).');
        }

        $days = [];
        $timezone = '';
        foreach ($body['data'] as $entry) {
            $date = DateTimeImmutable::createFromFormat('!d-m-Y', (string)($entry['date']['gregorian']['date'] ?? ''));
            if (false === $date) {
                continue;
            }
            $timezone = (string)($entry['meta']['timezone'] ?? $timezone);
            $t = [];
            foreach (['fajr' => 'Fajr', 'dhuhr' => 'Dhuhr', 'asr' => 'Asr', 'maghrib' => 'Maghrib', 'isha' => 'Isha'] as $k => $name) {
                // "04:32 (+03)" -> "04:32"
                if (!preg_match('/^(\d{2}):(\d{2})/', (string)($entry['timings'][$name] ?? ''), $m)) {
                    continue 2;
                }
                $t[$k] = $m[1] . ':' . $m[2];
            }
            $days[$date->format('Y-m-d')] = $t;
        }

        if ([] === $days) {
            throw new \RuntimeException('The prayer times service returned no usable times.');
        }
        $this->store->storeTimes($settings['station_id'], $days, $timezone);
    }

    /** True when the city's time zone (from the API) differs from the station's: the times would be off. */
    public static function timezoneMismatch(string $apiTimezone, DateTimeZone $stationTz): bool
    {
        if ('' === $apiTimezone) {
            return false;
        }
        try {
            $now = new DateTimeImmutable('now');
            return (new DateTimeZone($apiTimezone))->getOffset($now) !== $stationTz->getOffset($now);
        } catch (\Throwable) {
            return false;
        }
    }
}
