<?php

declare(strict_types=1);

namespace Plugin\MusicDownloader;

/**
 * Station-level permission added through App\Event\BuildPermissions. It shows up in Administration → Roles
 * like core ones; "administer all" covers it. The Spotify app settings (Client ID/Secret, YouTube cookies,
 * proxy) need AzuraCast's global "administer settings" instead.
 */
final class Permissions
{
    public const string DOWNLOAD = 'download music';

    public const array NAMES = [
        self::DOWNLOAD => 'Download Music (Spotify playlists into the media library)',
    ];
}
