<?php

declare(strict_types=1);

namespace Plugin\AzanQuran\Service;

use App\Container\EntityManagerAwareTrait;
use Doctrine\DBAL\Connection;

/**
 * The plugin's tables (src/Migration), read and written with DBAL:
 *   azan_quran_settings  shared settings (opening/closing time, pause length...), one row per key
 *   azan_quran_station   per-station settings: on/off, city, calculation method, which prayers
 *   azan_quran_times     cached daily prayer times per station (from the Aladhan API)
 */
final class Store
{
    use EntityManagerAwareTrait;

    public const array PRAYERS = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'];

    public const array GLOBAL_DEFAULTS = [
        'opening_time' => '09:00',
        'closing_time' => '23:00',
        'quran_minutes' => 60,    // the Quran plays this long before opening
        'fade_seconds' => 15,     // fade from the Quran to music at opening time
        'pause_seconds' => 180,   // silence at each prayer time
        'pause_scope' => 'all',   // all: every prayer | business: only from the Quran's start until closing
    ];

    private function db(): Connection
    {
        return $this->em->getConnection();
    }

    /** @return array<string, mixed> */
    public function getGlobal(): array
    {
        $values = self::GLOBAL_DEFAULTS;
        foreach ($this->db()->fetchAllKeyValue('SELECT setting_key, setting_value FROM azan_quran_settings') as $k => $v) {
            if (array_key_exists($k, $values)) {
                $values[$k] = is_int(self::GLOBAL_DEFAULTS[$k]) ? (int)$v : (string)$v;
            }
        }
        return $values;
    }

    /** @param array<string, mixed> $values */
    public function setGlobal(array $values): void
    {
        foreach (array_intersect_key($values, self::GLOBAL_DEFAULTS) as $k => $v) {
            $this->db()->executeStatement(
                'INSERT INTO azan_quran_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                [$k, (string)$v]
            );
        }
    }

    /** @return array{station_id: int, is_enabled: bool, city: string, country: string, method: int, prayers: string[], last_planned_at: ?int, last_error: ?string} */
    public function getStation(int $stationId): array
    {
        $row = $this->db()->fetchAssociative('SELECT * FROM azan_quran_station WHERE station_id = ?', [$stationId]);
        if (false === $row) {
            return [
                'station_id' => $stationId,
                'is_enabled' => false,
                'city' => '',
                'country' => '',
                'method' => PrayerTimes::DEFAULT_METHOD,
                'prayers' => self::PRAYERS,
                'last_planned_at' => null,
                'last_error' => null,
            ];
        }
        return [
            'station_id' => $stationId,
            'is_enabled' => (bool)$row['is_enabled'],
            'city' => (string)$row['city'],
            'country' => (string)$row['country'],
            'method' => (int)$row['method'],
            'prayers' => array_values(array_intersect(self::PRAYERS, explode(',', (string)$row['prayers']))),
            'last_planned_at' => null !== $row['last_planned_at'] ? (int)$row['last_planned_at'] : null,
            'last_error' => $row['last_error'],
        ];
    }

    /** @param array{is_enabled: bool, city: string, country: string, method: int, prayers: string[]} $s */
    public function saveStation(int $stationId, array $s): void
    {
        $before = $this->getStation($stationId);
        $this->db()->executeStatement(
            'INSERT INTO azan_quran_station (station_id, is_enabled, city, country, method, prayers, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), city = VALUES(city), country = VALUES(country),
                method = VALUES(method), prayers = VALUES(prayers), updated_at = VALUES(updated_at)',
            [$stationId, (int)$s['is_enabled'], $s['city'], $s['country'], $s['method'], implode(',', $s['prayers']), time()]
        );
        // Cached times belong to a place and a method.
        if ($before['city'] !== $s['city'] || $before['country'] !== $s['country'] || $before['method'] !== $s['method']) {
            $this->clearTimes($stationId);
        }
    }

    public function clearTimes(int $stationId): void
    {
        $this->db()->executeStatement('DELETE FROM azan_quran_times WHERE station_id = ?', [$stationId]);
    }

    public function setStatus(int $stationId, ?string $error): void
    {
        $this->db()->executeStatement(
            'UPDATE azan_quran_station SET last_planned_at = ?, last_error = ? WHERE station_id = ?',
            [time(), $error, $stationId]
        );
    }

    /**
     * Remember that a cue ("2026-10-13 1241 pause") was sent; false if it already was. Keeps the last 20.
     * Sync\CueTask runs every minute and also accepts the previous minute, so this stops a cue going out twice.
     */
    public function claimCue(int $stationId, string $key): bool
    {
        $raw = $this->db()->fetchOne('SELECT recent_cues FROM azan_quran_station WHERE station_id = ?', [$stationId]);
        $cues = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        if (in_array($key, $cues, true)) {
            return false;
        }
        $cues = array_slice([...$cues, $key], -20);
        $this->db()->executeStatement(
            'UPDATE azan_quran_station SET recent_cues = ? WHERE station_id = ?',
            [json_encode($cues), $stationId]
        );
        return true;
    }

    /** @return int[] */
    public function enabledStationIds(): array
    {
        return array_map('intval', $this->db()->fetchFirstColumn('SELECT station_id FROM azan_quran_station WHERE is_enabled = 1'));
    }

    /** @return array{fajr: string, dhuhr: string, asr: string, maghrib: string, isha: string, timezone: string}|null */
    public function getTimes(int $stationId, string $day): ?array
    {
        $row = $this->db()->fetchAssociative(
            'SELECT fajr, dhuhr, asr, maghrib, isha, timezone FROM azan_quran_times WHERE station_id = ? AND day = ?',
            [$stationId, $day]
        );
        return false === $row ? null : $row;
    }

    /** @param array<string, array{fajr: string, dhuhr: string, asr: string, maghrib: string, isha: string}> $days keyed by Y-m-d */
    public function storeTimes(int $stationId, array $days, string $timezone): void
    {
        foreach ($days as $day => $t) {
            $this->db()->executeStatement(
                'REPLACE INTO azan_quran_times (station_id, day, fajr, dhuhr, asr, maghrib, isha, timezone)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$stationId, $day, $t['fajr'], $t['dhuhr'], $t['asr'], $t['maghrib'], $t['isha'], $timezone]
            );
        }
        // Keep the last week and what's ahead.
        $this->db()->executeStatement(
            'DELETE FROM azan_quran_times WHERE station_id = ? AND day < ?',
            [$stationId, date('Y-m-d', strtotime('-7 days'))]
        );
    }
}
