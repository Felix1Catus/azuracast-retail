<?php

declare(strict_types=1);

namespace Plugin\MusicDownloader\Service;

use App\Entity\Enums\StorageLocationAdapters;
use App\Entity\Station;
use App\Flysystem\StationFilesystems;
use League\Flysystem\StorageAttributes;

/**
 * Where downloads may go: folders of a station's media library, on local storage below the stations folder
 * (the worker container mounts the same station_data volume at the same path). S3, Dropbox or SFTP media
 * storage isn't supported.
 */
final class MediaFolders
{
    public const string STATIONS_ROOT = '/var/acacia/stations';
    private const int MAX_FOLDERS = 1000;

    public function __construct(
        private readonly StationFilesystems $stationFilesystems,
    ) {
    }

    /** Absolute path of the station's media folder, or null when downloads can't be written there. */
    public function localRoot(Station $station): ?string
    {
        $location = $station->media_storage_location;
        if (StorageLocationAdapters::Local !== $location->adapter) {
            return null;
        }

        // The stored path, not realpath(): on installs from before the rebrand /var/acacia/stations can be a link
        // to the old mount point (util/docker/common/scripts/my_init). The worker checks the final folder itself.
        $path = rtrim($location->getFilteredPath(), '/');
        if (str_contains($path, '/../')) {
            return null;
        }

        return str_starts_with($path . '/', self::STATIONS_ROOT . '/') ? $path : null;
    }

    public function unsupportedReason(Station $station): ?string
    {
        if (null !== $this->localRoot($station)) {
            return null;
        }
        return StorageLocationAdapters::Local !== $station->media_storage_location->adapter
            ? 'This station keeps its media on remote storage (' . $station->media_storage_location->adapter->value
                . '). Music Downloader can only write to local station storage.'
            : 'This station\'s media folder is outside ' . self::STATIONS_ROOT . ', which the downloader can\'t reach.';
    }

    /**
     * Clean a folder path relative to the media root: '' for the root, 'A/B' otherwise, null when invalid.
     */
    public static function normalize(string $folder): ?string
    {
        $folder = trim(str_replace('\\', '/', $folder));
        $parts = [];
        foreach (explode('/', $folder) as $part) {
            $part = trim($part);
            if ('' === $part || '.' === $part) {
                continue;
            }
            if ('..' === $part || str_starts_with($part, '.') || preg_match('/[\x00-\x1f\x7f]/u', $part) || mb_strlen($part) > 120) {
                return null;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    /** @return list<string> every folder of the media library, relative to its root, sorted */
    public function listFolders(Station $station): array
    {
        $fs = $this->stationFilesystems->getMediaFilesystem($station);
        $folders = [];
        foreach ($fs->listContents('', true) as $item) {
            /** @var StorageAttributes $item */
            if ($item->isDir() && !StationFilesystems::isDotFile($item->path())) {
                $folders[] = trim($item->path(), '/');
                if (count($folders) >= self::MAX_FOLDERS) {
                    break;
                }
            }
        }
        natcasesort($folders);
        return array_values($folders);
    }
}
