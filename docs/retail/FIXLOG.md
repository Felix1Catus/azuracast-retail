# Fix log

These are the changes Acacia Radio makes to upstream, newest first. Each entry gives:
- the finding (`R-n`, from [REVIEW.md](REVIEW.md))
- the GitHub issue
- the files involved
- how it was verified

Upstream syncs are listed too.

## 0.23.8-retail.18 (2026-10-13)

Branch Board (Tracker 1.4.0). Update with `./docker.sh update`.

| # | Change | Files | Verified |
|---|---|---|---|
| — | **One-time "what's new" notice in the branch player**, once per branch (not per device), in Egyptian Arabic, right to left, over the player while the music keeps playing: the 3-minute silence at each azan, the new music source, and the playing-status and volume tracking. Branches already signed in get it with their next heartbeat; dismissing it is stored on the branch (`branch_branch.notice_seen`, new migration). Text and id: `plugins/branch-tracker/src/Service/BranchNotice.php` (a new id shows a new notice once) | `plugins/branch-tracker/` (`BranchNotice.php`, `BranchAuthController.php`, `SessionService.php`, `Entity/Branch.php`, `templates/player.html`, migration) | Dev server: shown via heartbeat, dismissed, not shown again after reload |

## 0.23.8-retail.17 (2026-10-13)

Faster installs and updates. `./docker.sh update` works as before but still builds the images; to download them
instead, refresh `docker.sh` from the release first:

```bash
git -C /opt/acacia-radio fetch --tags && git -C /opt/acacia-radio checkout --detach v0.23.8-retail.17
./docker.sh update-self && ./docker.sh update
```

(The source checkout is `RETAIL_SOURCE_DIR` in `.env`.) Later updates download the images on their own.

| # | Change | Files | Verified |
|---|---|---|---|
| — | **Prebuilt release images:** GitHub Actions builds both images for every release tag and publishes them as `ghcr.io/felix1catus/acacia-radio:<version>` and `...-music-downloader:<version>`. The installer and `./docker.sh update` download them (a few minutes instead of 15–40) and build locally only when they can't (arm64, `--build`, `ACACIA_IMAGE_REGISTRY=none`) | `.github/workflows/release-images.yml`, `util/install_retail.sh`, `docker.sh` | Fresh install in a clean Debian container; dev server updated from the registry in 60 s |
| — | **Quieter installer:** one line per step with a spinner and timings, all command output in `/var/log/acacia-install.log`, the log's last lines when a step fails, a summary at the end; partial git clone; checks Docker Compose is 2.24 or newer; `--build` and `--help` | `util/install_retail.sh` | Step runner tested (success, fallback, failure); shellcheck |
| — | **Leaner image:** the final image holds only what runs on the server (no frontend sources, tests, docs or development files) | `Dockerfile`, `.dockerignore` | Image built and started with a fresh database: all migrations ran, pages and plugins answer |
| — | **`/source` redirects to the repository** at the exact commit the server runs (or the repository's front page when unknown), instead of building an archive on the server | `SourceCodeAction.php`, `routes/public.php`, `PanelFooter.vue` | Fresh image: 302 to `github.com/Felix1Catus/azuracast-retail/tree/<commit>` |
| — | **Documentation:** new README and install guide; development notes, known issues and the migration guide moved into `docs/retail/` | `README.md`, `docs/retail/` | — |
| — | **Installer, after the release (on `main`):** stops with an explanation when a database from an earlier install exists but its `acacia.env` (with the password) is gone, instead of timing out at the first start; summary box fits its frame | `util/install_retail.sh` | Fresh install end to end in a clean Debian container: images downloaded in 52 s, up 18 s later |

## 0.23.8-retail.16 (2026-10-13)

New built-in module: Azan & Quran (plugin 0.2.0). Update with `./docker.sh update`; then, per channel that uses
it, turn it on in **Azan & Quran** and restart the channel once.

| # | Change | Files | Verified |
|---|---|---|---|
| — | **Prayer-time pauses:** at each prayer time (Aladhan API, the channel's city and calculation method, cached a month ahead) the music stops for 3 minutes and then carries on; the stream never stops, so players stay connected | `plugins/azan-quran/` (`Sync/CueTask.php`, `Service/Planner.php`, `Service/PrayerTimes.php`) | Dev server, live (Cairo): 3:00 of silence on the minute, then music |
| — | **Quran before opening:** a chosen recording plays for the hour before opening and fades into the music over 15 s at opening time (a Liquidsoap command the module adds); opening/closing hours are shared by all channels | `plugins/azan-quran/` (`Radio/LiquidsoapFade.php`) | Dev server, live: cut in on the minute, faded out, music 15 s after opening time |
| — | **Station menu item** "Azan & Quran" (permission *Manage Azan & Quran*) instead of the module's floating button | `StationFeatures.php`, `GetDashboardAction.php`, `StationGlobalFeatures.php`, `menu.ts`, `ApiInterfaces.ts`, `useStationQuery.ts` | Dev server |

## 0.23.8-retail.15 (2026-10-12)

Music Downloader 0.2.2. Update with `./docker.sh update`, then sign the YouTube account in again once.

| # | Fix | Files | Verified |
|---|---|---|---|
| — | **YouTube account kept signed in.** retail.13/14 had the browser refresh the session, and Google signed it out at the first refresh: a session used by two different clients (Chromium and yt-dlp) is treated as stolen. The browser now only does the sign-in, hands the cookies to the worker and deletes its profile; yt-dlp is the session's only user. The session is checked with yt-dlp every 6 hours and when a download is refused (**Check now** replaces Refresh now); a sign-out raises the same alerts | `plugins/music-downloader/worker/app/browser.py`, `browser_api.py`, `youtube_session.py`, `cookies.py`, `main.py`, `runner.py`, `templates/page.html`, `src/` | Dev server (sign-out cause found in the profile's cookies), test containers, worker self-test |

## 0.23.8-retail.14 (2026-10-12)

Music Downloader 0.2.1. Update with `./docker.sh update`, then sign the YouTube account in again once
(Music Downloader > Settings > YouTube account).

| # | Fix | Files | Verified |
|---|---|---|---|
| — | **YouTube account no longer signed out at its first refresh.** The worker loaded its older cookie file into the browser profile, overwriting the rotated session, and cookies were exported while YouTube's page could still rotate them. The profile is now never overwritten, and cookies are exported after leaving YouTube | `plugins/music-downloader/worker/app/browser.py`, `browser_api.py`, `youtube_session.py` | Test containers, worker self-test; on the dev server the session was still signed out at the first refresh (fixed in retail.15) |

## 0.23.8-retail.13 (2026-10-12)

Music Downloader 0.2.0: YouTube account. Update with `./docker.sh update` (it adds the
`music-downloader-browser` container and its `music_downloader_browser` volume through the compose template).

| # | Change | Files | Verified |
|---|---|---|---|
| — | **Connect YouTube account:** an administrator signs a separate Google account in once, in a Chromium on the server shown in Settings as a live, clickable picture. No password is stored; the browser profile keeps the session | `plugins/music-downloader/worker/app/browser.py`, `browser_api.py`, `templates/page.html`, `docker-compose.retail.yml`, `install.sh` | Dev server (real sign-in, cookie test passed) |
| — | **Cookies kept fresh** from that browser: every 6 hours while idle, before a job when older than 30 minutes, and once mid-job when YouTube refuses them (then the failed tracks are retried) | `worker/app/youtube_session.py`, `main.py`, `runner.py` | Test containers, worker self-test |
| — | **Alerts** when YouTube signs the account out: banner on the downloader page, dashboard notice for settings admins, one email to administrators when outgoing email is set up | `src/Notification/YouTubeNotice.php`, `src/Sync/YouTubeAlertTask.php` | Test containers (state + alert), task run on dev server |

## 0.23.8-retail.12 (2026-10-12)

Dashboard and usability. Update with `./docker.sh update`.

| # | Change | Files | Verified |
|---|---|---|---|
| — | **Listener summary** replaces the dashboard's listener chart: listening now, unique listeners, stations on air, most listened (refreshed every 15 s) | `Dashboard.vue`, `_acacia.scss` | Dev server |
| — | **Station cards** replace the dashboard's station table: cover art with play button, On air / Offline badge, current song, listener count, Public Page and Manage; search with more than 6 stations; stacks on phones | `Dashboard.vue`, `_acacia.scss` | Dev server |
| — | **Header** shows an initial badge and the user's name instead of the bare e-mail address; **keyboard focus** has a clear outline | `DashboardLayout.vue`, `_acacia.scss` | Dev server |

## 0.23.8-retail.11 (2026-10-12)

Design fixes from reviewing every page live. Update with `./docker.sh update`.

| # | Fix | Files | Verified |
|---|---|---|---|
| — | **No empty gap above every page.** The header was made sticky while the page kept 5.15rem of padding for the old fixed header; it is fixed again at its new 4.25rem height | `_variables.scss`, `_acacia.scss` | Dev server |
| — | **Dark mode links** are leaf green instead of a dim grey-green (media file names, Administration links) | `_acacia.scss` | Dev server |
| — | **Lists read better:** item names (media files, stations) are titles with their path muted below; table row actions are soft tinted buttons instead of solid green/red blocks on every row | `_acacia.scss` | Dev server |
| — | **Music Downloader page** uses the Acacia palette | `plugins/music-downloader/templates/page.html` | Dev server |

## 0.23.8-retail.10 (2026-10-12)

The Acacia Radio design, and a fix for stale plugin routes. Application behaviour is unchanged. Update from retail.8
or retail.9 with `./docker.sh update-self && ./docker.sh update`; from retail.7, follow the retail.8 steps with
`v0.23.8-retail.10`.

| # | Change | Files | Verified |
|---|---|---|---|
| — | **Acacia Radio design.** Savanna palette (acacia leaf green, blossom gold, bark, sky, clay on sand) in light and dark mode; light sticky header with the logo; cards with thin borders and blossom-accented titles instead of solid blue bars; rounded leaf-tint menu items; restyled tabs, dropdowns, modals, buttons (no all-caps), forms, tables, badges and progress bars | `frontend/scss/_acacia.scss`, `_variables.scss` | Dev server |
| — | **Login and public pages:** savanna dusk with an acacia on the horizon (night version in dark mode), drawn in CSS/SVG; frosted login card with the logo | `frontend/scss/_acacia.scss` | Dev server, dark mode |
| — | **Charts:** acacia palette, smooth lines without dots, light gridlines, rounded bars, softer fills, one tooltip for all series, whole-number axes from zero, flatter dashboard chart | `frontend/functions/useChart.ts`, `frontend/vendor/chartjs_colorschemes.ts`, `DashboardCharts.vue` | Dev server |
| — | **Lighter pages:** system UI font instead of six Roboto web fonts, no hexagon background images (874 KB) or header image. Login page about 340 KB instead of about 900 KB | `frontend/scss` | Dev server: measured |
| — | **Single-page lists** no longer show a page selector | `Pagination.vue` | Dev server |
| — | **Stale plugin routes after updates:** the route cache is keyed on file times, but opcache could still run the old `events.php`, caching old routes under the new key (new routes answered 405). Changed route files are now invalidated in opcache first; plugin reload/install scripts restart PHP before clearing `app_routes*.cache.php` | `backend/src/AppFactory.php`, `plugins/*/reload.sh`, `plugins/*/install.sh` | Found on the dev server while updating Music Downloader |

## 0.23.8-retail.9 (2026-10-12)

Fix for retail.8. Update from retail.8 with `./docker.sh update-self && ./docker.sh update`; from retail.7, follow
the retail.8 steps below with `v0.23.8-retail.9`.

| # | Fix | Files | Verified |
|---|---|---|---|
| — | **`./docker.sh backup` works on installs from before the rebrand.** It copied the archive out of a fixed volume name `acacia_backups`, but those installs keep their volume names (`COMPOSE_PROJECT_NAME`); the archive was made but not copied out, and an empty volume was created. It now uses the project's volume prefix, like `restore` | `docker.sh` | Dev server: backup 213 MB with database and all media |

## 0.23.8-retail.8 (2026-10-12)

Rebrand phase 2: internal names. Existing installs keep all data: the database keeps its name and the Docker
volumes stay the same; stored paths move with a migration. Details: [REBRAND.md](REBRAND.md#phase-2-internal-names-done).

| # | Change | Files | Verified |
|---|---|---|---|
| — | **Internal names.** `/var/acacia`, user `acacia` (same uid/gid), `acacia_cli` and `acacia:*` commands, `acacia.env` and `ACACIA_*` settings, images `acacia-radio`/`acacia-radio-music-downloader`, containers `acacia*`, Liquidsoap `acacia.*`, code identifiers and file names (`util/rebrand.py`, re-runnable) | whole tree | Built image: 0 mentions outside protected lines and external project names |
| — | **Migration for existing installs.** `./docker.sh update` renames the env file and keys (backups kept), pins the old database name and user, keeps `COMPOSE_PROJECT_NAME` (same volumes) and installs the new compose template; migration `Version20261012120000` moves `station.radio_base_dir` and `storage_location.path` to `/var/acacia/` and renames Liquidsoap identifiers in custom code | `docker.sh`, `backend/src/Entity/Migration/Version20261012120000.php` | Dev server: retail.7 to phase 2 via `./docker.sh update`; paths migrated, database and volumes kept, station configs regenerated with the new user and paths, Music Downloader kept its settings and history and downloads again |
| — | **Compatibility for installs started the old way** (old compose file or env): old database name kept, volumes mounted at the old paths linked into `/var/acacia`, `AZURACAST_*` variables mapped, stale station process configs rewritten before supervisord starts, `/var/azuracast` linked to `/var/acacia` | `util/docker/common/scripts/my_init` | Dev server | <!-- rebrand:keep -->
| — | **Fixes found deploying to the dev server:** `is-retail` didn't recognise installs still on the old image name, so `update` took the upstream path and replaced `docker.sh` (restored; nothing else changed). Station `supervisord.conf` files still named the old user, so the container restart-looped (~9 min off air until fixed by hand); now rewritten at startup | `docker.sh`, `my_init` | Dev server |

**Updating from retail.7** (the installed `docker.sh` predates the rename, so get the new one first):

```bash
cd /var/azuracast && ./docker.sh backup  # rebrand:keep (install folder of existing installs)
git -C "$(grep ^RETAIL_SOURCE_DIR= .env | cut -d= -f2)" fetch --tags origin
git -C "$(grep ^RETAIL_SOURCE_DIR= .env | cut -d= -f2)" checkout --detach v0.23.8-retail.8
./docker.sh update-self && ./docker.sh update v0.23.8-retail.8
```

Rolling back to retail.7 needs the file backups and a database migration step: see REBRAND.md, "Rolling back".

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

Not a bug: a new station starts only after **Start Station** on its overview page. Until then `acacia:radio:restart` skips it, as upstream does.

## 0.23.8-retail.4 (2026-10-11)

Base: upstream 0.23.8 rolling, upstream `ebf75a267` (unchanged). Application code is unchanged from retail.3: this release adds the installer and fixes `docker.sh`.

| # | Change | Files | Verified |
|---|---|---|---|
| — | **One-command installer** for a fresh Ubuntu/Debian server. It checks the machine, installs Docker, clones the source, builds the newest release, writes `/var/acacia` (ports 80/443), starts Acacia Radio with the `/listen/` proxy and, with `--domain`, gets HTTPS. Safe to run again | `util/install_retail.sh`, INSTALL §2a | Fresh Ubuntu 24.04 VPS (1 vCPU, 4 GB): install from GitHub in 7 min 53 s; setup wizard on :80; :443 answers; `/source` and the Branch Board module respond; ports 8000/2022/8005 closed. Re-run: 33 s, settings kept. Also tested in a Docker-in-Docker container without systemd |
| — | **Installer fixes found in testing:** without systemd, the Docker daemon starts detached (a Ctrl-C used to stop its port proxies). The final URL shows the public address | `util/install_retail.sh` | Re-run after a daemon restart |
| — | **`docker.sh update` re-run keeps the rollback target.** Updating to the installed version used to set `ACACIA_PREVIOUS_IMAGE` to itself | `docker.sh` | Local install and VPS |
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

**Verified** on a copy of production data (`acacia-radio:dev` plus hot-loaded backend):
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
| R-3 | **Restore re-enables sync.** `acacia:restore` turns `sync_disabled` off in a `finally` block | n/a | `RestoreCommand.php` | Code review; this bug hit production |
| R-1, R-2 | **Global "enable all webhooks" switch fixed.** It was wired to On-Demand. Now it gates the Webhooks feature, and the dispatcher skips outgoing connectors when it is off | n/a | `StationFeatures.php`, `Webhook/Dispatcher.php` | Code review |

## Template for new entries

```
| R-n | **Short title.** What was wrong and what changed | #issue | files | how it was checked |
```
