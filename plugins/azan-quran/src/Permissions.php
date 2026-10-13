<?php

declare(strict_types=1);

namespace Plugin\AzanQuran;

/**
 * Station-level permission added through App\Event\BuildPermissions ("administer all" covers it). The opening
 * and closing times are shared by every station and need the global "administer settings" permission.
 */
final class Permissions
{
    public const string MANAGE = 'manage azan quran';

    public const array NAMES = [
        self::MANAGE => 'Manage Azan & Quran (prayer-time pauses, Quran before opening)',
    ];
}
