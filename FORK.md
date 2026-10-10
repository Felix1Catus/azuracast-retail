# AzuraCast Retail fork

A retail / in-store-radio focused fork of [AzuraCast](https://github.com/AzuraCast/AzuraCast), used by TLRadio
(tradelineradio.com). Branch tracking stays a separate plugin:
[azuracast-tracker-plugin](https://github.com/Felix1Catus/azuracast-tracker-plugin).

## Baseline

| | |
|---|---|
| Upstream commit | **`5ca47e6701b953e5a679971aa67c4364498766ba`** (`main`, 2026-10-03, "Fix issue #8642"), i.e. AzuraCast 0.23.8 + rolling updates |
| Tag | `upstream-baseline-5ca47e6` |
| Source of truth | This is the exact build running on tradelineradio.com (Docker image `8d536dd5f800`, label `org.opencontainers.image.revision`) |
| Parity check | The live server's `/var/azuracast/www` was pulled on 2026-10-10 and compared file by file with this commit: **no differences** apart from build outputs (`web/static/vite_dist`, generated `translations/`, `.gitinfo`) and the separately mounted Tracker plugin. Production has no core modifications |

## Current base

| | |
|---|---|
| Upstream | **`ebf75a267`** (2026-10-06), merged on top of the baseline below. No new database migrations since the baseline |
| Fork release | `0.23.8-retail.1`, see [docs/retail/FIXLOG.md](docs/retail/FIXLOG.md) |
| Install | [docs/retail/INSTALL.md](docs/retail/INSTALL.md) |

## Branches and remotes

```
upstream  https://github.com/AzuraCast/AzuraCast.git   (read-only, AzuraCast's history)
origin    https://github.com/Felix1Catus/azuracast-retail.git
main      the fork: baseline + our changes, one commit per logical change
```

Rules:
- Each change is its own commit with a clear message ("Remove podcasts UI", "Default station profile for stores", …),
  so it can be reviewed, reverted, or reapplied after an upstream update.
- Prefer **removing features from navigation, settings and startup** over deleting large code areas at first. That keeps
  upstream merges small. Delete code only when a feature is fully unwired.
- Keep business logic (branches, tracking) in the plugin where possible; change the core only for what a plugin can't do.

## Taking upstream updates

```bash
git fetch upstream
git log --oneline main..upstream/main      # what's new upstream
git merge upstream/main                    # or cherry-pick specific fixes
```

Resolve conflicts, rebuild, run the plugin's test suite and the checks below, then tag and deploy.

## Building and running

Upstream builds the frontend, translations, API docs and `.gitinfo` in GitHub Actions and only then runs
`docker build`. This fork does those steps in the Dockerfile `assets` stage, so a clean checkout builds on its own:

```bash
util/build_image.sh                          # -> azuracast-retail:dev, version info from git
util/build_image.sh azuracast-retail:0.1.0   # any tag
```

The script is `docker build --target final` plus the git commit, date and branch as build args (`.git` is not in
the build context). Translations come from the `.po` files in `translations/`; upstream's Crowdin pull is skipped.

The image uses a classmap-authoritative autoloader: a PHP class copied into a running container is not found until
`composer dump-autoload --classmap-authoritative` is rerun. Rebuild, or use the dev compose file, instead.

For development, `docker-compose.dev.yml` mounts the source tree into the container, so PHP changes apply without a
rebuild (frontend changes need `npm run build` or the dev server).

The local TLRadio stack (`~/tradelineradio/server/docker-compose.yml`) can point its `image:` at
`azuracast-retail:dev` to test the fork with the real (copied) production data and the Tracker plugin.

## Docs

- [docs/retail/INSTALL.md](docs/retail/INSTALL.md): build, install, switch an existing server, troubleshooting
- [docs/retail/FIXLOG.md](docs/retail/FIXLOG.md): every fork change, with issue and verification
- [docs/retail/ARCHITECTURE.md](docs/retail/ARCHITECTURE.md): component map
- [docs/retail/REVIEW.md](docs/retail/REVIEW.md): bugs and improvements (R-1 … R-14)
- [docs/retail/REDESIGN.md](docs/retail/REDESIGN.md): retail edition plan and phases

## License: AGPL-3.0-or-later

AzuraCast is licensed under the GNU AGPL v3 or later, and so is this fork (`LICENSE.md` is unchanged). In short, and
not as legal advice: when a **modified** version is run for users over a network (branch players, the admin panel),
those users must be offered the corresponding source code of that modified version, for example through a public
repository or a "Source code" link on the site. Keeping this repository private during development is fine;
publish the source (or provide the link) before modified code is put in front of users.

The fork does this with `GET /source` (`backend/src/Controller/Frontend/SourceCodeAction.php`). It serves a
`.tar.gz` of the code in the running container, without `vendor/`, `node_modules/` or build output (those are pinned
by `composer.lock` and `package-lock.json`). It is linked as "Source code" in the panel footer. Every deployment of
the fork must keep that route reachable.
