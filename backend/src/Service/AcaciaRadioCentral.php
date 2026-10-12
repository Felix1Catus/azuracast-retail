<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\EnvironmentAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Container\SettingsAwareTrait;
use App\Entity\Api\Admin\UpdateDetails;
use App\Version;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use RuntimeException;

final class AcaciaRadioCentral
{
    use LoggerAwareTrait;
    use EnvironmentAwareTrait;
    use SettingsAwareTrait;

    // Same response shape as the upstream service: {"ip": "..."}.
    private const string IP_URL = 'https://api.ipify.org?format=json';

    public function __construct(
        private readonly Version $version,
        private readonly Client $httpClient,
    ) {
    }

    /**
     * Ping the the upstream update service server for updates and return them if there are any.
     */
    public function checkForUpdates(): UpdateDetails
    {
        // Acacia Radio is updated with ./docker.sh update; it doesn't report to or ask the upstream project's server.
        throw new RuntimeException('Update checks are disabled in this edition.');
    }

    public function getUniqueIdentifier(): string
    {
        return $this->readSettings()->app_unique_identifier;
    }

    /**
     * Ping the the upstream update service server to retrieve this installation's likely public-facing IP.
     *
     * @param bool $cached
     */
    public function getIp(bool $cached = true): ?string
    {
        $settings = $this->readSettings();
        $ip = ($cached)
            ? $settings->external_ip
            : null;

        if (empty($ip)) {
            try {
                $response = $this->httpClient->request(
                    'GET',
                    self::IP_URL
                );

                $bodyRaw = $response->getBody()->getContents();
                $body = json_decode($bodyRaw, true, 512, JSON_THROW_ON_ERROR);

                $ip = $body['ip'] ?? null;
            } catch (Exception $e) {
                $this->logger->error('Could not fetch remote IP: ' . $e->getMessage());
                $ip = null;
            }

            if (!empty($ip) && $cached) {
                $settings->external_ip = $ip;
                $this->writeSettings($settings);
            }
        }

        return $ip;
    }
}
