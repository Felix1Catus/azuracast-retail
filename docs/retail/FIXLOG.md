# Fix log

These are the changes AzuraCast Retail makes to upstream AzuraCast, newest first. Each entry gives:
- the finding (`R-n`, from [REVIEW.md](REVIEW.md))
- the GitHub issue
- the files involved
- how it was verified

Upstream syncs are listed too.

## 0.23.8-retail.3 (2026-10-11)

Base: AzuraCast 0.23.8 rolling, upstream `ebf75a267` (unchanged). No core migrations: the Branch Board tables are Tracker's own, and they're created or kept as they are.

| # | Change | Issue | Files | Verified |
|---|---|---|---|---|
| R-13 | **Built-in Branch Board.** Tracker 1.3.2 ships in the image as `plugins/branch-tracker`, so there is nothing to install. `StationFeatures::BranchBoard` is on when the module is present, and the station menu has a native **Branch Board** item (permission *View Branches*). Tracker no longer injects its floating button when that item exists. The same tables are used, so data carries over and branches stay logged in | #12 | `plugins/branch-tracker`, `StationFeatures.php`, `StationGlobalFeatures.php`, `GetDashboardAction.php`, `Stations/menu.ts`, `util/sync_tracker.sh` | Local copy of production without the plugin mount: menu item, board, player and PIN login work, and the Tracker suites pass |


Base: AzuraCast 0.23.8 rolling, upstream `ebf75a267` (unchanged). No database migrations: rolling back to `0.23.8-retail.1` or to upstream 0.23.8 is safe.

### Code removed (#13)

The code behind the features switched off below (#11) is deleted: 250 files, about 26,600 lines. That covers:
- 80 PHP classes:
  - controllers for admin installers, relays and updates
  - controllers for station podcasts, streamers/DJs, webhooks, SFTP users, HLS streams, remote relays and Stereo Tool
  - controllers for on-demand and requests, and their reports and public pages
  - their middleware and sync tasks
  - the web updater, the update and donation notifications
  - 13 webhook connectors and the test-webhook message
- the matching Vue pages, components, public entry points and the player's request modal
- the tests for those APIs and the AutoDJ request scenarios

The webhook dispatcher keeps only the local handler (static now-playing files and Centrifugo). Now Playing no longer reports live DJs.

**Kept on purpose:** entities, enums and radio adapters, because existing database tables map to them. The station dashboard, profile and player keep their response shape and return empty URLs for removed pages.

**Image size:** code is about 1% of the image (about 3.1 GB). The bulk is Liquidsoap, ffmpeg and its libraries (about 720 MB), MariaDB (about 280 MB), PHP and nginx (about 310 MB, including the 57 MB listener map) and composer packages (160 MB, including the AWS SDK used for S3 storage). All of that is still needed. SFTPGo, the only removable runtime, went in #11.

**Verified:**
- PHPStan (level 8) and PHPCS are clean on the whole backend; the only remaining notices are in codeception helpers, as before.
- 206/206 unit tests pass.
- `vue-tsc` is clean, `biome` is clean and the Vite build succeeds.
- An import-graph check finds no frontend file left unreachable (besides the type shim and an upstream-unused `ErrorCard`).
- Runtime checks on the built image are listed under PR #17.

### Updating from this repository

`./docker.sh update` on a retail install now builds and switches to the newest `v*-retail.*` release from the fork's source checkout (`RETAIL_SOURCE_DIR`). `./docker.sh rollback` returns to the previous image, and `./docker.sh update-self` refreshes `docker.sh`. See [INSTALL.md](INSTALL.md#4-updating).

### Branding

The donation line in the panel footer and the admin donation notification are gone. "Powered by AzuraCast" and the source code link stay.

### Retail edition, feature strip (#11)

The panel now offers what a store chain needs and nothing else:
- stations
- media
- playlists and schedules
- mount points
- the AutoDJ and its queue
- reports
- branding
- users, backups and storage

The features below are switched off server-side, and every way into them is removed: menus, pages, form fields, API and public routes, background tasks and processes. Their code is still in the tree, to be deleted with the slim image (#13), so upstream merges stay workable. Database columns and tables are untouched, so there are no migrations and the change can be rolled back.

| Removed | How | Files |
|---|---|---|
| **Live streaming / DJs, Web DJ** | `StationFeatures::Streamers` is off. `Station::$enable_streamers` always reads and stores `false`, so Liquidsoap opens no live (harbor) input. The streamer, broadcast and DJ sync tasks are dropped | `StationFeatures.php`, `Station.php`, `events.php` |
| **Song requests** | Feature off. `enable_requests` is always `false`. The AutoDJ never queues leftover request rows. The requests report, form tab and playlist option are gone | `Station.php`, `QueueBuilder.php`, frontend |
| **On-demand page** | Feature off. `enable_on_demand` is always `false`. The menu items, form fieldset and playlist option are gone | `Station.php`, frontend |
| **Podcasts** | Feature off. The public pages, feeds and both podcast API groups require the feature. The podcast playlist sync task is dropped | `routes/api_station.php`, `routes/public.php`, `events.php` |
| **HLS** | Feature off. `enable_hls` is always `false` (no HLS output, nginx location or log rotation) | `Station.php` |
| **Remote relays / AzuraRelay** | Feature off. Existing relay rows no longer get Liquidsoap outputs. The admin relay page, the relay API (admin and internal) and the relay cleanup task are gone | `ConfigWriter.php`, `routes/api_admin.php`, `routes/api_internal.php` |
| **Webhooks** | Feature off. The dispatcher sends nothing (the local now-playing handler still runs). The settings switch is gone | `Webhook/Dispatcher.php`, frontend |
| **SFTP** | SFTPGo is no longer installed or started. The SFTP internal endpoints, the SFTP user pages and port 2022 are gone | `util/docker/web/*`, `ServiceControl.php`, `Dockerfile` |
| **Shoutcast, RSAS, Stereo Tool, GeoLite installers** | The admin pages and APIs ("Third-Party Software") are gone, along with the GeoLite update task. The Stereo Tool station page is gone | `routes/api_admin.php`, frontend |
| **Update page and checks** | The admin update page, its APIs and the update-check settings are gone (see R-8) | `routes/api_admin*.php`, frontend |
| **SoundExchange report** | The report page is gone | frontend |

Also: requests for a feature a station doesn't offer now return **404** instead of 500 (`StationUnsupportedException`).

**Verified** on a copy of production data (`azuracast-retail:dev` plus hot-loaded backend):
- The feature flags read `false` even when set to 1 in the database, and saving a station stores 0.
- Liquidsoap config has no harbor, HLS or relay output, and the stream plays.
- Removed pages and APIs answer 404/405, while kept APIs answer 200: playlists, files, mounts, queue, schedule, reports, listeners, history and admin.
- The kept sync tasks run cleanly.
- Tracker plugin tests pass: 38/38, 16/16 and 22/22.
- `vue-tsc` reports no errors and `biome lint` is clean.

## 0.23.8-retail.1 (2026-10-10)

Base: AzuraCast 0.23.8 rolling, upstream `ebf75a267`.

| # | Fix | Issue | Files | Verified |
|---|---|---|---|---|
| R-4 | **Upstream sync** to `ebf75a267`. This brings in the security fix that stops the static Now Playing files and Centrifugo publishing stations whose API is private. It also brings composer and npm updates, FullCalendar v7 and Centrifugo 6.9.7. No new migrations | #4 | merge commit | Image build; panel smoke test |
| R-12 | **Source code link** in the public page footer (login, player), shown even when the product name is hidden. Tracker 1.3.1 adds the same link to the branch player and board | #10 | `backend/templates/minimal.phtml`; plugin `SourceLink` | `/login` footer shows the link; plugin player footer shows the link |
| R-10 | **Compose file with HTTP/HTTPS only** (`docker-compose.retail.yml`). It keeps the upstream project, container and volume names, uses the fork image and has no updater container | #9 | `docker-compose.retail.yml` | Local stack on the fork image |
| R-8 | **Upstream updates disabled.** The web updater is always off, the AzuraCast Central check and its notification are skipped, and `docker.sh update` / `update-self` refuse to run on a retail install. The image defaults to `ENABLE_WEB_UPDATER=false` | #7 | `Environment.php`, `CheckUpdatesTask.php`, `UpdateCheck.php`, `docker.sh`, `Dockerfile` | `docker.sh update` exits with a message on a retail compose file |
| R-7 | **Route cache keyed on route files.** The cache file name hashes the core route files and each plugin's `events.php` (path and modification time), so installing or updating a plugin never serves stale routes | #6 | `AppFactory.php` | Plugin routes resolve after a plugin change without manual cache removal |
| R-6 | **Branch presence independent of analytics.** Connected clients are always collected. For the "none" and "no IP" levels, the analytics task now deletes only listener rows for ended connections (it used to delete all of them) | #5 | `NowPlayingTask.php`, `ListenerRepository.php`, `RunAnalyticsTask.php` | Board shows connected branches |
| R-9 | **Autoloader note.** Documented that the image uses a classmap-authoritative autoloader | #8 | `FORK.md`, `INSTALL.md` | n/a |
| R-5 | **Self-contained image build.** A Dockerfile `assets` stage builds the frontend, translations, OpenAPI docs and `.gitinfo`. `util/build_image.sh` adds git version info, retries and a composer parallelism setting, and works with any buildx builder | n/a | `Dockerfile`, `util/build_image.sh` | Full build from a clean checkout |
| AGPL | **`/source` endpoint** serves a cached `.tar.gz` of the running code (without `vendor`, `node_modules` or build output; lock files included), linked from the panel footer | n/a | `SourceCodeAction.php`, `routes/public.php`, `PanelFooter.vue` | HTTP 200, `application/gzip`, about 7.9 MB, 2,664 entries |
| R-3 | **Restore re-enables sync.** `azuracast:restore` turns `sync_disabled` off in a `finally` block | n/a | `RestoreCommand.php` | Code review; this bug hit production |
| R-1, R-2 | **Global "enable all webhooks" switch fixed.** It was wired to On-Demand. Now it gates the Webhooks feature, and the dispatcher skips outgoing connectors when it is off | n/a | `StationFeatures.php`, `Webhook/Dispatcher.php` | Code review |

## Template for new entries

```
| R-n | **Short title.** What was wrong and what changed | #issue | files | how it was checked |
```
