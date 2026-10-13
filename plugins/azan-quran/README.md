# Azan & Quran for Acacia Radio

A plugin for [Acacia Radio](https://github.com/Felix1Catus/azuracast-retail) stations that play in shops across
the MENA region:

- **Prayer-time pauses:** at each prayer time the music stops for exactly 3 minutes (configurable), then
  carries on. The stream itself never stops, so the branches' players stay connected and nothing has to be
  restarted.
- **Quran before opening:** a Quran recording plays for the hour before opening time and fades into the music
  over 15 seconds at opening time.

Prayer times come from the [Aladhan API](https://aladhan.com/prayer-times-api) (free, no key) for each
station's city and calculation method, a month at a time, cached in the database so a few days without
internet don't skip a prayer.

## How it works

It uses Acacia Radio's own player; there's no separate one. For each station that has it turned on, the plugin
keeps two playlists that only hold its audio (marked in their descriptions; change them from the plugin's
page, not by hand). They stay **disabled and unscheduled**, so neither the AutoDJ nor Liquidsoap plays them on
their own:

| Playlist | Content |
|---|---|
| **Azan pause** | A silent MP3 of the pause length, generated with ffmpeg into `Azan & Quran/` in the station's media. Jingle mode keeps the previous song's title on the players. |
| **Quran** | The recording chosen on the plugin's page. |

A sync task (`Sync\CueTask`) runs every minute and, in each station's time zone:

- at **opening − Quran length**, pushes the Quran recording into Liquidsoap's *interrupting* queue (the same
  queue core uses for "interrupt other songs" playlists), which cuts the current song at once;
- at each **prayer time** outside the Quran hour, pushes the silent file the same way: the music stops for
  exactly the pause length, then the AutoDJ carries on. The stream never stops, so players stay connected;
- at **opening time**, if the Quran is still playing, sends `azan_quran.fade_skip` to the station's
  Liquidsoap. The plugin adds that command to the station's configuration: it lowers the volume to zero over
  15 s, skips the Quran, and brings the next song in over 3 s. The station needs **one restart** after the
  plugin is turned on to load it; until then the Quran is skipped without a fade.

A cue fires within the minute (the sync runs once a minute, usually in its first seconds), and again in the
next minute if the sync ran late; it's never sent twice. No pause happens inside the Quran hour.

Why not scheduled playlists: Acacia Radio also writes interrupting playlists' schedules into Liquidsoap's
configuration, which is only reloaded on a restart, so prayer times that change every day would need daily
restarts, and a pause could play twice.

| Setting | Where | Default |
|---|---|---|
| On/off, country, city, calculation method, which prayers | per station | method from the country (Umm Al-Qura for SA, Dubai for AE, ...) |
| Opening and closing time, Quran length, pause length, fade length, pause all day or only while open | all stations (administrators) | 09:00, 23:00, 60 min, 180 s, 15 s, all day |

The station's time zone (its profile) must match the city's; the page warns when it doesn't.

## Permissions

- Station permission **Manage Azan & Quran** (Administration > Roles) for the page and station settings.
- The opening hours are shared by all stations and need the global **administer settings** permission.

## Files

| Path | What |
|---|---|
| `events.php`, `services.php` | Wiring: routes, permission, migrations, sync tasks, Liquidsoap hook, class autoloader |
| `src/Service/Store.php` | The plugin's tables (DBAL) |
| `src/Service/PrayerTimes.php` | Aladhan API client, methods, country defaults |
| `src/Service/Planner.php` | Creates the two playlists, generates the silence, works out each day's cues, today's preview |
| `src/Radio/LiquidsoapFade.php` | The `azan_quran.fade_skip` Liquidsoap command |
| `src/Sync/CueTask.php`, `PlanTask.php` | The pauses, the Quran and the fade, every minute; hourly readiness check and prayer-time cache |
| `src/Controller/`, `templates/` | The page (plain HTML + JS) and its API |
| `src/Migration/` | Tables: `azan_quran_settings`, `azan_quran_station`, `azan_quran_times` |

## Install

Built into Acacia Radio Retail from the release that includes it. On a server where it isn't, copy this
folder to the web container as `/var/acacia/www/plugins/azan-quran` (or mount it there), run the migrations
(`acacia_cli migrations:migrate`), and restart PHP (`supervisorctl restart php-fpm php-nowplaying php-worker`).

## License

AGPL-3.0, like Acacia Radio.
