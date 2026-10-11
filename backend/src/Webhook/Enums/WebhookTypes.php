<?php

declare(strict_types=1);

namespace App\Webhook\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum WebhookTypes: string
{
    case Generic = 'generic';
    case Email = 'email';

    case TuneIn = 'tunein';
    case RadioDe = 'radiode';
    case RadioReg = 'radioreg';
    case GetMeRadio = 'getmeradio';

    case Discord = 'discord';
    case Telegram = 'telegram';
    case GroupMe = 'groupme';
    case Mastodon = 'mastodon';
    case Bluesky = 'bluesky';

    case GoogleAnalyticsV4 = 'google_analytics_v4';
    case MatomoAnalytics = 'matomo_analytics';

    // Retired connectors
    case Twitter = 'twitter';
    case GoogleAnalyticsV3 = 'google_analytics';
}
