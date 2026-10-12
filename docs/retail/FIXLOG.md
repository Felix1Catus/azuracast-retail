# Fix log

These are the changes Acacia Radio makes to upstream, newest first. Each entry gives:
- the finding (`R-n`, from [REVIEW.md](REVIEW.md))
- the GitHub issue
- the files involved
- how it was verified

Upstream syncs are listed too.

## 0.23.8-retail.7 (2026-10-12)

First Acacia Radio release: the Music Downloader module and the rebrand of everything users see. Base: upstream 0.23.8
rolling (unchanged). No changes to the core database schema; the downloader keeps its own data in a volume.

| # | Change | Files | Verified |
|---|---|---|---|
| — | **Music Downloader (built in).** Paste a Spotify playlist, album or track link, pick a station folder, and spotDL downloads the tracks (Spotify for the list, YouTube for the audio), tagged with cover art. Spotify login per user, per-track progress, retry, cancel. **Music Downloader** in the station menu for users with *Download Music* | `plugins/music-downloader`, `StationFeatures::MusicDownloader`, `menu.ts` | Dev server: albums 14/14 and 10/10, single tracks (~17-45 s each); files imported by the media scan |
| — | **Worker containers.** `music-downloader` (spotDL, yt-dlp, ffmpeg, Deno; built by `util/build_image.sh`) and `music-downloader-pot` (YouTube tokens), internal only, low CPU priority. The installer and `./docker.sh update` create the shared secret `music-downloader.env` | `docker-compose.retail.yml`, `util/build_image.sh`, `util/install_retail.sh`, `docker.sh` | Dev server: switched from the standalone plugin; cookies, Spotify settings and download history kept |
| — | **YouTube on blocked servers.** Signed-in YouTube cookies (Settings, with a live test), PO tokens and the `mweb`/`web_safari` clients; flat search makes matching ~4x faster | `plugins/music-downloader/worker` | Dev server (Vultr IP blocked by YouTube) |
| — | **Rebrand to Acacia Radio (phase 1).** Name in the panel, public pages, e-mails, CLI and all translations (`util/rebrand.py`, re-run after upstream merges); Acacia Radio logo (header, login, icons, favicon); the Acacia Radio jingle as fallback audio in every Icecast format; upstream docs site, help links and update check removed | `util/rebrand.py`, `util/branding/`, `resources/error.mp3`, `docs/retail/REBRAND.md`, `ATTRIBUTION.md` | Built image: 0 user-visible mentions; dev server login page, icons and fallback files checked |

Updating from retail.6: `./docker.sh update-self`, then `./docker.sh update`. The update replaces `docker-compose.yml` with the new template (backup kept) to add the worker containers. If the standalone Music Downloader plugin is installed, run its `install.sh --uninstall` first. The new compose file needs Docker Compose 2.24 or newer.

## 0.23.8-retail.6 (2026-10-11)

These changes came from moving the development server to its domain. The application code is unchanged.

| # | Change | Files | Verified |
|---|---|---|---|
| — | **`--domain` takes several names** (comma-separated; the first is the site URL, all go on the certificate) | `util/install_retail.sh` | Dev server: certificate for `acaciaradio.tech` and `www.acaciaradio.tech` (Let's Encrypt), both valid over HTTPS |
| — | **Re-running the installer on an existing install no longer stops on low disk**; it warns, because the image is usually built already | `util/install_retail.sh` | Dev server re-run with 6 GB free |
| — | **`./docker.sh update` removes old builds** and keeps only the running image and the rollback target, then trims the build cache to 4 GB. Old builds of about 3 GB each had filled the development server | `docker.sh` | Dev server update |

## 0.23.8-retail.5 (2026-10-11)

These fixes were found by setting up a fresh install on the development server (setup wizard, then the first station).

| # | Fix | Files | Verified |
|---|---|---|---|
| — | **Leftover `UpdateCheck` registration removed.** The class was deleted in retail.2 (#13) but `events.php` still registered it, so every notification load logged "No entry or class found". A scan of `backend/config` finds no other references to deleted classes | `backend/config/events.php` | Dev server: no new errors after dashboard loads |
| — | **The radio web proxy is always on.** Only 80/443 are published, but the setup wizard saved `use_radio_proxy=false` over the installer's setting, so listen URLs pointed at the closed port 8000. `Settings::$use_radio_proxy` now always reads and stores `true`, and the toggle is gone from System Settings | `Settings.php`, `GeneralTab.vue` | Dev server: listen and mount URLs are `/listen/store/radio.mp3` |

Not a bug: a new station starts only after **Start Station** on its overview page. Until then `azuracast:radio:restart` skips it, as upstream does.

## 0.23.8-retail.4 (2026-10-11)

Base: upstream 0.23.8 rolling, upstream `ebf75a267` (unchanged). Application code is unchanged from retail.3: this release adds the installer and fixes `docker.sh`.

| # | Change | Files | Verified |
|---|---|---|---|
| — | **One-command installer** for a fresh Ubuntu/Debian server. It checks the machine, installs Docker, clones the source, builds the newest release, writes `/var/azuracast` (ports 80/443), starts Acacia Radio with the `/listen/` proxy and, with `--domain`, gets HTTPS. Safe to run again | `util/install_retail.sh`, INSTALL §2a | Fresh Ubuntu 24.04 VPS (1 vCPU, 4 GB): install from GitHub in 7 min 53 s; setup wizard on :80; :443 answers; `/source` and the Branch Board module respond; ports 8000/2022/8005 closed. Re-run: 33 s, settings kept. Also tested in a Docker-in-Docker container without systemd |
| — | **Installer fixes found in testing:** without systemd, the Docker daemon starts detached (a Ctrl-C used to stop its port proxies). The final URL shows the public address | `util/install_retail.sh` | Re-run after a daemon restart |
| — | **`docker.sh update` re-run keeps the rollback target.** Updating to the installed version used to set `AZURACAST_RETAIL_PREVIOUS_IMAGE` to itself | `docker.sh` | Local install and VPS |
| — | **Docs:** the repository is public (no token needed); the HTTPS step no longer points at `docker.sh setup-letsencrypt`, which only prints a hint | INSTALL.md, FORK.md | — |

## 0.23.8-retail.3 (2026-10-11)

Base: upstream 0.23.8 rolling, upstream `ebf75a267` (unchanged). No core migrations: the Branch Board tables are Tracker's own, and they're created or kept as they are.

| # | Change | Issue | Files | Verified |
|---|---|---|---|---|
| R-13 | **Built-in Branch Board.** Tracker 1.3.2 ships in the image as `plugins/branch-tracker`, so there is nothing to install. `StationFeatures::BranchBoard` is on when the module is present, and the station menu has a native **Branch Board** item (permission *View Branches*). Tracker no longer injects its floating button when that item exists. The same tables are used, so data carries over and branches stay logged in | #12 | `plugins/branch-tracker`, `StationFeatures.php`, `StationGlobalFeatures.php`, `GetDashboardAction.php`, `Stations/menu.ts`, `util/sync_tracker.sh` | Local copy of production without the plugin mount: menu item, board, player and PIN login work, and the Tracker suites pass |


Base: upstream 0.23.8 rolling, upstream `ebf75a267` (unchanged). No database migrations: rolling back to `0.23.8-retail.1` or to upstream 0.23.8 is safe.

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

The donation line in the panel footer and the admin donation notification are gone. "Powered by Acacia Radio" and the source code link stay.

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

Base: upstream 0.23.8 rolling, upstream `ebf75a267`.

| # | Fix | Issue | Files | Verified |
|---|---|---|---|---|
| R-4 | **Upstream sync** to `ebf75a267`. This brings in the security fix that stops the static Now Playing files and Centrifugo publishing stations whose API is private. It also brings composer and npm updates, FullCalendar v7 and Centrifugo 6.9.7. No new migrations | #4 | merge commit | Image build; panel smoke test |
| R-12 | **Source code link** in the public page footer (login, player), shown even when the product name is hidden. Tracker 1.3.1 adds the same link to the branch player and board | #10 | `backend/templates/minimal.phtml`; plugin `SourceLink` | `/login` footer shows the link; plugin player footer shows the link |
| R-10 | **Compose file with HTTP/HTTPS only** (`docker-compose.retail.yml`). It keeps the upstream project, container and volume names, uses the fork image and has no updater container | #9 | `docker-compose.retail.yml` | Local stack on the fork image |
| R-8 | **Upstream updates disabled.** The web updater is always off, the the upstream update service check and its notification are skipped, and `docker.sh update` / `update-self` refuse to run on a retail install. The image defaults to `ENABLE_WEB_UPDATER=false` | #7 | `Environment.php`, `CheckUpdatesTask.php`, `UpdateCheck.php`, `docker.sh`, `Dockerfile` | `docker.sh update` exits with a message on a retail compose file |
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
