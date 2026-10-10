# Fix log

These are the changes AzuraCast Retail makes to upstream AzuraCast, newest first. Each entry gives:
- the finding (`R-n`, from [REVIEW.md](REVIEW.md))
- the GitHub issue
- the files involved
- how it was verified

Upstream syncs are listed too.

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
