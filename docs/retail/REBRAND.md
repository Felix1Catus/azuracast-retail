# Rebrand: Acacia Radio

The product name, logo and default audio are Acacia Radio's. Upstream merges bring the original name back into
strings, so the rebrand is a script plus a few files, not a one-off edit.

## After every upstream merge

```bash
util/rebrand.py            # re-apply the name (UI, messages, e-mails, CLI, docs, translations)
util/rebrand.py --check    # 0 = nothing user-visible left; otherwise lists file:line
git diff                   # review, then commit
```

`util/rebrand.py` replaces the product name as a word only. It also rewrites references that would become untrue,
such as "upstream AzuraCast" (now "upstream") and "AzuraCast 0.23.8" (now "upstream 0.23.8"). It never touches
`LICENSE.md`, `CHANGELOG.md`, `README.md`, this file, `docs/retail/ATTRIBUTION.md`, or third-party code.

## Phase 1: everything users see (done)

| What | Where |
|---|---|
| Name in UI, messages, e-mails, logs, CLI, docs; translations (msgid and msgstr together) | `util/rebrand.py` |
| Panel header wordmark `acacia`**`radio`** | `frontend/components/Layout/DashboardLayout.vue` |
| Logo (acacia branch): icons and favicon `web/static/icons/*`, `web/favicon.ico`, `resources/icon*.png`; panel header and login page | master: `util/branding/acacia-logo.svg` (Inkscape, kept out of the image); render: `util/branding/build_icons.sh` |
| Fallback jingle (stations with nothing to play, dropped streams): `resources/error.mp3`, and all Icecast `fallback-[kbps].*` files at build time | `util/branding/build_fallback_audio.sh` (run by the Dockerfile) |
| Upstream docs site (`/docs`) and links to it, "Need Help?" GitHub card, links to the original project's sites | removed |
| Update check and IP lookup against the original project's server | update check off (`./docker.sh update` instead), IP from ipify |
| Authenticator app logo, oEmbed provider, default avatar, demo/example data | own server / neutral placeholders |

**To change the logo:** replace `util/branding/acacia-logo.svg`, run `util/branding/build_icons.sh` (it crops the
drawing and centres it on a cream rounded tile; `ICON_TILE_COLOR` changes the tile), and commit.
**To change the jingle:** replace `resources/error.mp3` (tag it title/artist "Acacia Radio") and rebuild the image.

## Phase 2: internal names (done)

| Before | After |
|---|---|
| `/var/azuracast` (inside the container) | `/var/acacia` |
| Linux user and group `azuracast` (uid/gid 1000) | `acacia` (same ids, so file ownership is unchanged) |
| `azuracast_cli`, `azuracast:*` commands, `azuracast_db`, `azuracast_restore`, ... | `acacia_cli`, `acacia:*`, `acacia_db`, `acacia_restore`, ... |
| `azuracast.env`, `AZURACAST_*` variables, `AZURACAST_RETAIL_IMAGE` / `_PREVIOUS_IMAGE` | `acacia.env`, `ACACIA_*`, `ACACIA_IMAGE` / `ACACIA_PREVIOUS_IMAGE` |
| Images `azuracast-retail`, `azuracast-retail-music-downloader` | `acacia-radio`, `acacia-radio-music-downloader` |
| Containers `azuracast`, `azuracast_music_downloader(_pot)`; compose project `azuracast` | `acacia`, `acacia_music_downloader(_pot)`; project `acacia` |
| Database and user default `azuracast` | `acacia` |
| Liquidsoap `azuracast.*`, `settings.azuracast.*`, `azuracast_*` annotations | `acacia.*`, `settings.acacia.*`, `acacia_*` |
| Code identifiers (`AzuraCastCentral`, `useAzuraCast*`, `azuracastEnv`, ...) and file names | `AcaciaRadio...`, `useAcaciaRadio*`, `acaciaEnv`, ... |
| Source checkout `/opt/azuracast-retail`, install dir `/var/azuracast` (defaults for new installs) | `/opt/acacia-radio`, `/var/acacia` |

Not renamed: composer packages `azuracast/*` and base images `ghcr.io/azuracast/*` (external projects), the GitHub
repositories (rename them on GitHub; old URLs redirect), `audit_log` history.

### Existing installs

`./docker.sh update` migrates them (`retail-migrate-rebrand` in `docker.sh`, idempotent):
- `azuracast.env` becomes `acacia.env`, with `MYSQL_USER`/`MYSQL_DATABASE` written as `azuracast`: the database
  keeps its name
- `.env` keys `AZURACAST_*` become `ACACIA_*`; `COMPOSE_PROJECT_NAME=azuracast` stays, so the Docker volumes
  (stations, database, backups, downloader data) are the same ones
- `docker-compose.yml` is replaced by the new template (mounts at `/var/acacia`, containers named `acacia*`)
- the database migration `Version20261012120000` moves stored paths (`station.radio_base_dir`,
  `storage_location.path`) to `/var/acacia/` and renames the Liquidsoap namespace in custom station code
- backups: `azuracast.env.bak-*`, `.env.bak-*`, `docker-compose.yml.bak-*`

For an update run by an old `docker.sh` (which starts the new image with the old compose file and env), the image
keeps working on its own (`util/docker/common/scripts/my_init`): it keeps the old database name when only that
database exists, links volumes mounted at the old paths into `/var/acacia`, maps `AZURACAST_*` variables to
`ACACIA_*`, and links `/var/azuracast` to `/var/acacia` for paths stored before the migration. The Music Downloader
worker uses whichever stations path is mounted. Run `./docker.sh update-self` and `./docker.sh update` again to
finish the migration.

**Rolling back** to a version from before phase 2: restore `docker-compose.yml.bak-*`, `azuracast.env.bak-*` and
`.env.bak-*`, then `docker compose up -d`. The database migration has a `down()`, but the older image runs
migrations only upward, so to undo the path change run
`acacia_cli migrations:migrate 'App\Entity\Migration\Version20261003120502'` *before* rolling back.

## Licence

AGPL-3.0 allows renaming but requires keeping the copyright and licence notices and offering the source:
`LICENSE.md` is unchanged, `docs/retail/ATTRIBUTION.md` names the original work, and every installation serves
its source at `/source`.
