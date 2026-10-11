<?php

declare(strict_types=1);

namespace Plugin\MusicDownloader\Service;

use App\Http\ServerRequest;

/**
 * The redirect URI registered in the Spotify app. Spotify only accepts HTTPS redirect URIs, or plain HTTP
 * to a loopback address (127.0.0.1). So:
 *   - site on HTTPS  → https://<site>/music-downloader/oauth/callback, and the login finishes by itself
 *   - otherwise      → http://127.0.0.1:8888/callback: the browser lands on a page that doesn't load, and the
 *                      user pastes that address back into Music Downloader ("manual" mode)
 * MUSIC_DOWNLOADER_REDIRECT_URI overrides both.
 */
final class SpotifyRedirect
{
    public const string CALLBACK_PATH = '/music-downloader/oauth/callback';
    public const string LOOPBACK_URI = 'http://127.0.0.1:8888/callback';

    /** @return array{uri: string, automatic: bool} */
    public static function forRequest(ServerRequest $request): array
    {
        $override = getenv('MUSIC_DOWNLOADER_REDIRECT_URI');
        if (is_string($override) && '' !== $override) {
            return ['uri' => $override, 'automatic' => str_ends_with(rtrim($override, '/'), self::CALLBACK_PATH)];
        }

        $base = $request->getRouter()->getBaseUrl();
        if ('https' === $base->getScheme() && '' !== $base->getHost()) {
            $port = $base->getPort();
            $origin = 'https://' . $base->getHost() . (null !== $port && 443 !== $port ? ':' . $port : '');
            return ['uri' => $origin . self::CALLBACK_PATH, 'automatic' => true];
        }

        return ['uri' => self::LOOPBACK_URI, 'automatic' => false];
    }
}
