<?php

declare(strict_types=1);

namespace App\Enums;

use App\Entity\Settings;
use App\Entity\Station;
use App\Exception\StationUnsupportedException;

enum StationFeatures
{
    case CustomLiquidsoapConfig;
    case Media;
    case Sftp;
    case MountPoints;
    case RemoteRelays;
    case HlsStreams;
    case Streamers;
    case Webhooks;
    case Podcasts;
    case OnDemand;
    case Requests;
    case BranchBoard;
    case MusicDownloader;
    case AzanQuran;

    public function supportedForStation(
        Station $station,
        Settings $settings
    ): bool {
        $backendEnabled = $station->backend_type->isEnabled();

        return match ($this) {
            self::Media => $backendEnabled,
            self::CustomLiquidsoapConfig => $backendEnabled && $settings->enable_liquidsoap_editing,
            self::MountPoints => $station->frontend_type->supportsMounts(),
            // Branch Board (built-in Tracker module, plugins/branch-tracker). Its presence also tells the module
            // to skip its injected button: the station menu has a native item instead.
            self::BranchBoard => class_exists('Plugin\BranchTracker\Permissions'),
            // Music Downloader (built-in module, plugins/music-downloader): needs its worker container, which
            // docker-compose.retail.yml runs and the installer gives a shared secret. Its presence also tells
            // the module to skip its injected button.
            self::MusicDownloader => class_exists('Plugin\MusicDownloader\Permissions')
                && '' !== (string)getenv('MUSIC_DOWNLOADER_WORKER_TOKEN'),
            // Azan & Quran (built-in module, plugins/azan-quran; upstream: Felix1Catus/acacia-azan-quran). Its
            // presence also tells the module to skip its injected button.
            self::AzanQuran => class_exists('Plugin\AzanQuran\Permissions'),
            // Retail edition: these are not part of the product (docs/retail/REDESIGN.md). Their pages, API
            // routes and background tasks are gone or gated on this; the code is removed later (#13).
            self::Sftp,
            self::RemoteRelays,
            self::HlsStreams,
            self::Streamers,
            self::Webhooks,
            self::Podcasts,
            self::OnDemand,
            self::Requests => false,
        };
    }

    /**
     * @param Station $station
     * @param Settings $settings
     * @return void
     * @throws StationUnsupportedException
     */
    public function assertSupportedForStation(Station $station, Settings $settings): void
    {
        if (!$this->supportedForStation($station, $settings)) {
            throw match ($this) {
                self::Requests => StationUnsupportedException::requests(),
                self::OnDemand => StationUnsupportedException::onDemand(),
                default => StationUnsupportedException::generic(),
            };
        }
    }
}
