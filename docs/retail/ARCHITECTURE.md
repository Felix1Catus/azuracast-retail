# Acacia Radio: architecture map

This is a map of the upstream 0.23.8 codebase (upstream `5ca47e6`) as it applies to the retail fork. Use it to find the code behind a feature, and to see what each part costs at runtime before keeping, hiding or removing it. See [REDESIGN.md](REDESIGN.md) for the retail plan and [REVIEW.md](REVIEW.md) for the bugs and improvements found while reading the code.

## 1. One container, many processes

The production image (`Dockerfile`, target `final`) is a single Debian Trixie + PHP 8.5 container. `supervisord` starts everything:

| Process (supervisor conf) | Source | What it does | Retail need |
|---|---|---|---|
| `nginx` | `util/docker/web/service.full/nginx.conf`, vhost written by `backend/src/Nginx/` | HTTP/HTTPS, proxies `/listen/*` and `/hls/*` to stations, serves `web/` | **Keep** |
| `php-fpm` | `util/docker/web/` | Runs the Slim app (`web/index.php`) | **Keep** |
| `php-nowplaying` | `backend/src/Sync/NowPlaying/` | Loop that polls every station's frontend (Icecast) and backend (Liquidsoap). It builds the Now Playing API, records listeners and history, and fires webhooks | **Keep** (branch online status comes from listeners) |
| `php-worker` | `backend/src/MessageQueue/`, `backend/src/Message/` | Symfony Messenger consumer (Redis transport): media processing, webhook dispatch, backups, notifications | **Keep** |
| `cron` | `backend/src/Sync/Task/*` via `acacia:sync:run` | Scheduled tasks (see §5) | **Keep** |
| `centrifugo` | `util/docker/web/centrifugo/` | WebSocket and SSE push of Now Playing to players and the panel | **Keep** (live board) |
| `sftpgo` | `util/docker/web/service.full/sftpgo.conf`, `backend/src/Controller/Api/Internal/SftpAuthAction.php` | SFTP media upload per station | **Remove** (uploads go through the panel) |
| `mariadb`, `redis` | `util/docker/mariadb`, `util/docker/redis` | Database, cache, sessions, queue | **Keep** |
| `station_N_backend` / `station_N_frontend` | written at runtime by `backend/src/Radio/Configuration.php` into `/etc/supervisor/...` | One Liquidsoap and one Icecast per station | **Keep** |
| `vite` (dev only) | `util/docker/dev/service.full/vite.conf` | Frontend hot reload | dev only |

Startup scripts in `util/docker/*/startup_scripts/` run first. Among other things, they run `acacia:setup` (migrations, fixtures, settings) and, when `COMPOSER_PLUGIN_MODE=true`, `composer update` so that plugins under `plugins/*/composer.json` are installed.

## 2. Backend (PHP, `backend/src`, about 1,100 files)

**Bootstrap:** `backend/src/AppFactory.php` builds the PHP-DI container from `backend/config/services.php`, plus each plugin's `services.php`. It creates the Slim app, registers middleware, and loads the route files `backend/config/routes/*.php`. Routes are cached in `www_tmp/app_routes.cache.php`.

**Events:** `backend/src/CallableEventDispatcher.php` is the extension bus. Plugins (`backend/src/Plugins.php`) attach to it in `plugins/<name>/events.php`. Useful events (`backend/src/Event/`):
- `BuildRoutes`
- `BuildPermissions`
- `BuildDoctrineMappingPaths`
- `BuildMigrationConfigurationArray`
- `GetSyncTasks`
- `BuildConsoleCommands`
- `BuildView`
- `Radio\WriteLiquidsoapConfiguration` (inject Liquidsoap code)
- `Radio\GenerateRawNowPlaying`

There is **no event for the station menu**, which lives in the frontend (see §3).

| Area | Path | Notes |
|---|---|---|
| HTTP controllers | `Controller/Api/{Admin,Stations,Frontend,Internal,Public}`, `Controller/Frontend/*` | 237 single-action classes. The `api_station.php` route file alone is 1,042 lines |
| Middleware | `Middleware/` | Auth (session, API key), `GetStation`, `StationSupportsFeature`, `Permissions`, `RateLimit`, `EnableView` |
| ACL | `Acl.php`, `Enums/GlobalPermissions.php`, `Enums/StationPermissions.php` | Role-based, with global and per-station permissions |
| Entities | `Entity/*.php` (44 entities) | Doctrine attributes; repositories in `Entity/Repository`; API DTOs in `Entity/Api`; migrations in `Entity/Migration` |
| Change tracking | `Doctrine/` | **Explicit** change-tracking policy: call `persist()` before `flush()` or nothing is saved |
| Radio adapters | `Radio/Backend/Liquidsoap*`, `Radio/Frontend/{Icecast,Shoutcast,Rsas}`, `Radio/Remote/*` | `Radio/Configuration.php` writes the supervisor programs. `Radio/Backend/Liquidsoap/ConfigWriter.php` generates the `.liq` script from playlists, schedules, mounts, crossfade and audio processing |
| AutoDJ | `Radio/AutoDJ/{Queue,Scheduler,Annotations}.php` | Builds the play queue (`station_queue`) from weighted, scheduled, sequential and once-per-X playlists |
| Media | `Media/`, `Service/` (ffmpeg, metadata), `Flysystem/` | Storage locations: local, S3, Dropbox, SFTP (`Entity/StorageLocation`) |
| Now Playing | `Sync/NowPlaying/Task/NowPlayingTask.php`, `Entity/ApiGenerator/*` | Updates `Listener` rows (in `ListenerRepository`) and `SongHistory`, then dispatches `DispatchWebhookMessage` |
| Webhooks | `Webhook/Dispatcher.php`, `Webhook/LocalWebhookHandler.php`, `Webhook/Connector/*` | The local handler writes static NP JSON and pushes to Centrifugo. Connectors cover Discord, Telegram, Mastodon, TuneIn, GA, Matomo and generic HTTP |
| Scheduling | `Entity/StationSchedule`, `Radio/AutoDJ/Scheduler.php` | Day-of-week and time windows per playlist or streamer, in the station's timezone |
| Console | `Console/Command/*` | `acacia:setup`, `backup`, `restore`, `settings:set`, `sync:*`, `locale:*`, `api:docs` |
| Version | `Version.php` | Reads `.gitinfo` (written at build time) or falls back to `git` |
| Updater | `Sync/Task/CheckUpdatesTask.php`, `Service/WebUpdater.php` | Calls the upstream update service; the web updater pulls the **upstream** image |

**Feature gating:** `Enums/StationFeatures.php` gives each optional feature a `supportedForStation()` check, combining station settings, the backend/frontend type and global settings. The checks are enforced in two places:
- routes, through `Middleware\StationSupportsFeature`
- the frontend menu, through `station.features.*`, which `Controller/Frontend/...` hands to Vue

This is the natural hinge for a "retail mode".

## 3. Frontend (Vue 3 + TypeScript + Vite, `frontend/`)

| Folder | .vue files | What |
|---|---|---|
| `components/Stations` | 145 | Station panel: media, playlists, schedule, streamers, mounts, remotes, HLS, podcasts, webhooks, reports, logs, profile |
| `components/Admin` | 70 | System admin: users, roles, settings, storage, backups, stations, branding, GeoIP, Shoutcast/RSAS/Stereo Tool installers, logs, updates |
| `components/Common` | 48 | Shared widgets, including `PanelFooter.vue` |
| `components/Public` | 25 | Public player, requests, history, on-demand, podcasts, schedule, WebDJ |
| `components/Account`, `Setup`, `Layout`, `Form` | 34 | Profile, 2FA and passkeys, first-run setup, layout shell |

- **Station sidebar:** `components/Stations/menu.ts` (393 lines). Items are gated by `station.value.features.*` and `userAllowedForStation(StationPermissions.X)`.
- **Admin sidebar:** `components/Admin/menu.ts`, the same pattern.
- **Data:** Each page gets its props from the matching PHP `VueComponent` or controller, and fetches the rest from `/api`.
- **API client:** generated types in `frontend/entities/`.
- **Translations:** gettext, compiled to `translations/*/translations.json` by `locale:import`.

**Build:** `npm run build` writes `web/static/vite_dist`. Upstream runs this in GitHub Actions. This fork runs it in the Dockerfile `assets` stage (see `util/build_image.sh`).

## 4. Data model (retail lens)

```
Settings (1 row) ── global switches: base_url, analytics, enable_all_webhooks, sync_disabled, branding, backups…
User ─< Role >─ RolePermission (global + per-station)
Station ─┬─ StationMount / StationRemote / StationHlsStream     (outputs)
         ├─ StationPlaylist ─< StationPlaylistMedia >─ StationMedia ── StorageLocation
         │        └─< StationSchedule                          (when a playlist plays)
         ├─ StationStreamer ─< StationStreamerBroadcast          (live DJs)
         ├─ StationQueue / SongHistory / StationRequest         (what plays)
         ├─ Listener ── (ListenerDevice, ListenerLocation embeddables)
         ├─ StationWebhook, Podcast*, SftpUser
         └─ backend_config / frontend_config / branding_config (JSON blobs)
```

- **Listener rows** are the raw data for branch presence (the Tracker plugin's board joins devices to listeners). They are only written when `Settings.analytics` is not `none` (see REVIEW R-6).
- **Retail entities currently in the Tracker plugin:** branches, PINs and device sessions.

## 5. Scheduled tasks (`backend/src/Sync/Task`)

| Task | Retail |
|---|---|
| CheckMedia, CheckFolderPlaylists, CleanupHistory, CleanupStorage, RotateLogs, RunBackup, UpdateStorageLocationSizes, CleanupLoginTokens, RunAnalytics | Keep |
| CheckRequests | Remove with requests |
| CheckPodcastPlaylists | Remove with podcasts |
| EnforceBroadcastTimes, ReactivateStreamer, MoveBroadcasts | Keep only if live announcements via streamers are wanted |
| CleanupRelays | Remove (AzuraRelay) |
| UpdateGeoLite | Remove (MaxMind not used) |
| RenewAcmeCert | Keep |
| CheckUpdates | Replace with a fork-aware check, or disable (REVIEW R-8) |

## 6. Request paths worth knowing

- **Player:** `GET /public/{station}` → `Controller/Frontend/PublicPages/PlayerAction` → Vue `Public/Player`. Audio comes from `/listen/{station}/{mount}`: nginx proxies to Icecast, and Icecast URL auth calls `/api/internal/{station}/listener-auth` (`Controller/Api/Internal/ListenerAuthAction.php`).
- **Now Playing:** `/api/nowplaying/{station}` (dynamic), plus static `/api/nowplaying_static/{station}.json` and Centrifugo `station:{shortcode}`.
- **Source code:** `GET /source` → `Controller/Frontend/SourceCodeAction` (retail addition, AGPL §13). It is linked from the panel footer.

## 7. Build and release

| Stage | Input | Output |
|---|---|---|
| `assets` (retail) | repo + Node 24 + composer | `web/static/vite_dist`, `web/static/openapi.yml`, `translations/*`, `.gitinfo` |
| `final` | repo + composer `--no-dev` + `assets` | runnable image |

```bash
util/build_image.sh acacia-radio:dev
```

Upstream also pulls Crowdin translations in CI. The fork uses the `.po` files committed in `translations/`.
