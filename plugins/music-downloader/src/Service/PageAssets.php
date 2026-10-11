<?php

declare(strict_types=1);

namespace Plugin\MusicDownloader\Service;

use App\Assets\BrowserIconCustomAsset;

/**
 * Favicon tags (AzuraCast's own browser icon) and, on AzuraCast Retail, the "Source code" link that the
 * fork's AGPL notice requires on every page. On stock AzuraCast the /source route doesn't exist and no link
 * is shown.
 */
final class PageAssets
{
    public function __construct(
        private readonly BrowserIconCustomAsset $icon,
    ) {
    }

    public function faviconTags(): string
    {
        try {
            $e = static fn(string $u) => htmlspecialchars($u, ENT_QUOTES);
            return implode("\n", [
                '<link rel="icon" type="image/png" sizes="32x32" href="' . $e($this->icon->getUrlForSize(32)) . '">',
                '<link rel="apple-touch-icon" sizes="180x180" href="' . $e($this->icon->getUrlForSize(180)) . '">',
            ]);
        } catch (\Throwable) {
            return '';
        }
    }

    public function sourceLink(): string
    {
        if (!class_exists('App\\Controller\\Frontend\\SourceCodeAction')) {
            return '';
        }
        return '<a class="src" href="/source" title="Source code of this server (GNU AGPL v3)">Source code</a>';
    }
}
