# Code review: bugs and improvements

This review covers upstream 0.23.8 (upstream `5ca47e6`) as forked into acacia-radio. Each finding has an ID that is referenced from the GitHub issues and [REDESIGN.md](REDESIGN.md).

**Status values:**
- **Fixed:** fixed in the fork; see [FIXLOG.md](FIXLOG.md).
- **Upstream:** fixed upstream but not merged yet.
- **Open:** still to do.

## Bugs

### R-1 · "Enable all webhooks" switch disabled On-Demand instead of webhooks: **Fixed**
`backend/src/Enums/StationFeatures.php`

Upstream commit `f70e897f9` ("Add a global toggle for webhooks") added `Settings::$enable_all_webhooks`, but wired it to `self::OnDemand`. As a result:
- Turning webhooks **off** hid the On-Demand page and its routes on every station.
- Webhooks kept firing.
- The `Webhooks` feature was hard-coded to `true`, so the menu entry never disappeared.

**Fix:**
- `OnDemand` depends only on `$station->enable_on_demand`.
- `Webhooks` depends on `$settings->enable_all_webhooks`.

This bug is still present in upstream `main`.

### R-2 · Webhook dispatcher ignored the global switch: **Fixed**
`backend/src/Webhook/Dispatcher.php`

Even with R-1 fixed, existing enabled webhooks were still dispatched from the Now Playing loop, because the dispatcher never read the setting. It now skips the outgoing connectors when the switch is off.

The local handler, which writes the static Now Playing files and pushes to Centrifugo, still runs because it is not an outgoing webhook. Test dispatch (`TestWebhookMessage`) is unchanged, since it is an explicit admin action.

### R-3 · Restoring a backup leaves sync paused forever: **Fixed**
`backend/src/Console/Command/Backup/RestoreCommand.php`

`acacia:restore` sets `sync_disabled = true` before importing and never sets it back. After a restore, nothing runs until someone runs `acacia:settings:set sync_disabled false` by hand:
- no Now Playing updates
- no AutoDJ queue building
- no scheduled tasks

We hit this on both the production server and the local clone.

**Fix:** the final `acacia:setup --update` step is now wrapped in `try/finally`, which turns sync back on even if the update fails.

### R-4 · Static Now Playing files ignore the station's "public API" setting: **Fixed** (upstream merged)
Upstream `ebf75a267` (#8604) touches `backend/src/Webhook/LocalWebhookHandler.php` and `backend/src/VueComponent/NowPlayingComponent.php`.

The static `/api/nowplaying_static/*.json` files and the Centrifugo channel kept publishing data for stations whose API is set to private.

**Action:** merge the 7 upstream commits after `5ca47e6`. Besides this security fix, they include Centrifugo 6.9.7, FullCalendar v7, and npm and composer updates.

## Build and release

### R-5 · `docker build` does not produce a working image: **Fixed**
The Dockerfile `final` stage copies the repo and runs composer only. In upstream these steps run in GitHub Actions with Crowdin secrets, and their output is dropped into the build context:
- the frontend build (`web/static/vite_dist`)
- the compiled translations
- the OpenAPI file
- `.gitinfo`

A plain `docker build` therefore produced a panel with no JavaScript.

**Fix:**
- A new `assets` stage (Node 24 + composer) runs `locale:import`, `npm run build` and `acacia:api:docs`, and writes `.gitinfo` from build args.
- `util/build_image.sh` passes the git info in.
- Translations come from the `.po` files committed in the repo, not from Crowdin.

### R-6 · Branch presence depends on the analytics setting: **Fixed**
`Listener` rows, which the branch board uses to show whether a store is online, are only written when **Administration → Settings → Analytics** is not `none` (`NowPlayingTask.php:71`, `isAnalyticsEnabled()`). An admin who turns analytics off "for privacy" silently breaks the board.

**Options:**
- Always record listeners. They are needed for presence, not just for analytics.
- Or have the board show a warning when analytics is off.

### R-7 · Route cache is not cleared when plugins change: **Fixed**
`AppFactory` caches routes in `www_tmp/app_routes.cache.php`. Installing, updating or removing a plugin that adds routes leaves stale routes until the file is deleted by hand, which happened several times while developing the Tracker plugin.

**Fix:** include a hash of the plugin list and plugin route files in the cache file name, or clear it in the startup script after the composer plugin step.

### R-8 · The built-in updater targets upstream images: **Fixed**
`Sync/Task/CheckUpdatesTask.php` asks the upstream update service for updates, and `Service/WebUpdater.php` (enabled by `ENABLE_WEB_UPDATER=true`) replaces the container with the **upstream** image. On a fork, one click on "Update" in the panel would wipe the retail changes.

**Fix:**
- Default `ENABLE_WEB_UPDATER=false` in the fork image.
- Hide the update UI.
- Later, point update checks at this repo's releases.

### R-9 · Classmap-authoritative autoloading: **Fixed (documented)**
The image uses `composer dump-autoload --classmap-authoritative`. Copying a new PHP class into a running container (hot-fix or dev) gives `Callable … does not exist` until `composer dump-autoload` is rerun. Document this in FORK.md, or use the dev image for iterating.

## Security and hardening

### R-10 · Station ports 8000–8499 are published: **Fixed**
`docker-compose.sample.yml` publishes 8000, 8005, 8006… for direct Icecast access. Nginx already proxies every mount at `/listen/{station}/{mount}` over 80/443. A retail deployment only needs **80/443**; the extra ports add attack surface and firewall work.

### R-11 · Unused services still run and still expose surface: **Open**
These services run for nothing in retail:
- SFTPGo (port 2022)
- Shoutcast, RSAS and Stereo Tool installers (admin can upload binaries)
- GeoLite updates (MaxMind not used)
- AzuraRelay remotes
- HLS
- the public requests and on-demand pages

Removing them shrinks the image, the attack surface and the panel. See [REDESIGN.md](REDESIGN.md).

### R-12 · AGPL source offer only in the panel footer: **Fixed**
The new `/source` endpoint is linked from the logged-in panel footer. Public players and the branch board are also network interactions with the modified program, so they should carry a small "Source" link too: the Vue `Public/` layout and the Tracker plugin pages.

## Extensibility

### R-13 · No hook for menus: **Open**
Plugins can add routes, permissions, entities, sync tasks and console commands, but not sidebar items. The station and admin menus are static TypeScript (`frontend/components/Stations/menu.ts`, `frontend/components/Admin/menu.ts`). The Tracker plugin had to add its board button with injected JS and CSS.

**Fix:** move the Branch Board in-tree as a native station menu item (REDESIGN phase 2), or add a `BuildStationMenu` event whose result is passed to Vue as props.

### R-14 · Feature gating is the right lever for retail mode
`StationFeatures::supportedForStation()` already controls both routes (`Middleware\StationSupportsFeature`) and menus (`station.features.*`). A single `Settings::$retail_mode`, or a build-time constant, read there can hide whole feature areas without deleting code first. This keeps upstream merges cheap during the transition.

## Smaller notes

- The `Powered by Acacia Radio` footer and donate link are upstream branding. AGPL does not require keeping them, but the copyright and license notices must stay. Keep "Powered by Acacia Radio" (it is accurate) and drop the donate line in the retail theme.
- `MYSQL_PASSWORD="azur4c457"` is a default in the Dockerfile `ENV`. It is harmless while MariaDB only listens inside the container, but production should set its own in `acacia.env`.
- `Settings.sync_disabled` is only visible under Administration → Settings → Debugging, and nothing warns when it is on. Add a dashboard banner, since R-3 shows how easily it gets stuck.
