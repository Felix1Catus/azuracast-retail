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

## Phase 2: internal names (next)

Code identifiers, the `azuracast` Linux user, `/var/azuracast` paths, `AZURACAST_*` variables, `azuracast_cli` and
its `azuracast:*` commands, the database name, Docker image, container and project names. Station paths in the
database and Docker volume names depend on these, so phase 2 needs a migration for existing installs.

## Licence

AGPL-3.0 allows renaming but requires keeping the copyright and licence notices and offering the source:
`LICENSE.md` is unchanged, `docs/retail/ATTRIBUTION.md` names the original work, and every installation serves
its source at `/source`.
