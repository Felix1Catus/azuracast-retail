# Installing Acacia Radio

Acacia Radio runs exactly like a Docker install of Acacia Radio: one container, the same data volumes and the same `docker.sh` helper. The differences:
- **Image:** you build the image from this repository, so you never pull `ghcr.io/azuracast/azuracast`.
- **Ports:** only HTTP and HTTPS are published.
- **Updates:** upstream updates are switched off.

**Plugins:** plugins written for Acacia Radio, such as the [Tracker plugin](https://github.com/Felix1Catus/azuracast-tracker-plugin), install the same way.

| | |
|---|---|
| Based on | upstream 0.23.8 rolling (upstream `ebf75a267`, Oct 2026); current release `v0.23.8-retail.2` |
| Server | Linux with Docker Engine 24+ and the Compose plugin, 2 CPU / 4 GB RAM or more, ports 80 and 443 |
| Build machine | The server itself or any Linux/WSL machine with Docker and git (about 10 GB free for the build) |

## 1. Build the image

```bash
git clone https://github.com/Felix1Catus/azuracast-retail.git
cd azuracast-retail
util/build_image.sh azuracast-retail:0.23.8-retail.2      # pick any tag
docker tag azuracast-retail:0.23.8-retail.2 azuracast-retail:latest
```

The build compiles the frontend, translations and API docs inside Docker. It takes 15 to 30 minutes the first time, and much less afterwards. If it fails on network errors, see [Troubleshooting](#5-troubleshooting).

**Building on another machine:** if you build somewhere other than the server, copy the image over:

```bash
docker save azuracast-retail:0.23.8-retail.1 | gzip | ssh root@SERVER 'gunzip | docker load'
ssh root@SERVER docker tag azuracast-retail:0.23.8-retail.1 azuracast-retail:latest
```

## 2a. New server (one command)

This works on a fresh Ubuntu or Debian server (amd64 or arm64) with at least 2 GB RAM, about 12 GB free disk, ports 80 and 443 free, and the domain's DNS already pointing at the server. As root:

```bash
curl -fsSLo install_retail.sh https://raw.githubusercontent.com/Felix1Catus/azuracast-retail/main/util/install_retail.sh
less install_retail.sh                        # optional: read it first
sudo bash install_retail.sh --domain radio.example.com --email you@example.com
```

Without a domain yet, leave out `--domain` and `--email`; the site then runs on plain HTTP at the server's address until you add one. The repository is public, so no token is needed. If it is ever made private again, set `RETAIL_GIT_TOKEN` to a read-only token first; the installer passes it to git and keeps it for updates.

The installer:
1. checks the machine
2. installs Docker (from Docker's apt repository) and git
3. clones the source to `/opt/azuracast-retail` and checks out the newest release
4. builds the image (15–40 min the first time)
5. writes `/var/azuracast`: the retail compose file, `docker.sh`, `.env` with ports 80/443, and `azuracast.env` with a random database password
6. starts Acacia Radio and turns on the `/listen/` radio proxy
7. with `--domain`: sets the site URL and gets a Let's Encrypt certificate

It is safe to run again: finished steps are skipped and existing settings are kept.

| Option | Default |
|---|---|
| `--dir PATH` | `/var/azuracast` |
| `--src-dir PATH` | `/opt/azuracast-retail` |
| `--ref REF` | newest `v*-retail.*` release |
| `--domain NAME`, `--email ADDRESS` | none: HTTPS is set up later |
| `--http-port N`, `--https-port N` | `80`, `443` |
| `--yes` | ask before starting |

**Ports:** Docker binds 80 and 443 directly on the server, and nothing else is published. Port 80 has to stay reachable from the internet so Let's Encrypt can issue and renew the certificate. If another web server already uses 80/443, the installer stops and names it. Stop that server, or choose other ports and put a reverse proxy in front.

**Afterwards:**
1. Open the site and complete the setup wizard (admin account, first station).
2. Without `--domain`, set up HTTPS later in **Administration → System Settings → Services** (Let's Encrypt).

## 2b. Switching an existing Acacia Radio install to the fork

This applies to an install on upstream 0.23.8 (for example tradelineradio.com). The fork adds **no database migrations**, so the database stays compatible both ways and a rollback is just a matter of switching the compose file back.

```bash
cd /var/azuracast                                      # or wherever docker-compose.yml lives
./docker.sh backup                                     # 1. backup first
docker compose exec web azuracast_cli azuracast:settings:list | grep use_radio_proxy   # must be 1
cp docker-compose.yml docker-compose.yml.upstream      # 2. keep the old file for rollback
cp ~/azuracast-retail/docker-compose.retail.yml docker-compose.yml
docker compose up -d --remove-orphans                  # 3. recreates "azuracast" on the fork image and removes the updater
```

Keep `docker-compose.override.yml` as it is: it holds plugin mounts such as the Tracker plugin.

**Before you switch:** if any player connects to `http://SERVER:8000/...` directly instead of `/listen/...`, it stops working, because the fork only publishes 80/443. To check, open a player and look at the stream URL.

**Check after the switch:**
1. `https://SERVER/source` downloads a `.tar.gz`.
2. The panel footer shows **Source code**.
3. **Administration → System Maintenance** shows no update prompts.
4. Players are playing.

**Roll back:**

```bash
cp docker-compose.yml.upstream docker-compose.yml && docker compose up -d --remove-orphans
```

## 3. Branch Board (Tracker, built in)

Acacia Radio ships the Tracker module in the image (`plugins/branch-tracker`), so there is nothing to install. It includes:
- branch PIN login and the branch player
- live presence
- the Branch Board, as **Branch Board** in the station menu, for users with *View Branches*
- reports, "log out all" and the public read-only board

Its database tables are created by migrations when the container starts.

**Moving an install that used the plugin:** remove the plugin mount from `docker-compose.override.yml` (the installer marked it `managed-by: azuracast-tracker-plugin installer`). Then run `docker compose up -d`. The built-in module uses the same tables, so branches, PINs, sessions and history carry over and branches stay logged in. If the mount stays, the mounted copy takes over from the built-in one. That works, but it pins you to that plugin version.

The module is synced from the [Tracker repository](https://github.com/Felix1Catus/azuracast-tracker-plugin) with `util/sync_tracker.sh <path to a Tracker checkout>`.

## 3b. Music Downloader (built in)

Staff paste a Spotify playlist, album or track link, choose a folder of the station's media, and the tracks are
downloaded there (spotDL: track list from Spotify, audio from YouTube). It's **Music Downloader** in the station
menu, for users with the station permission *Download Music*. Details, Spotify app setup and YouTube cookies:
[plugins/music-downloader/README.md](../../plugins/music-downloader/README.md).

It runs in two extra containers from `docker-compose.retail.yml`: `music-downloader` (the worker, built by
`util/build_image.sh` as `azuracast-retail-music-downloader:<version>`) and `music-downloader-pot` (YouTube
tokens). The installer and `./docker.sh update` create `music-downloader.env`, the secret they share with
Acacia Radio. Data (Spotify logins, cookies, download history) is in the `music_downloader_data` volume.

**Moving an install that used the plugin:** run the plugin's `install.sh --uninstall` (or remove its parts from
`docker-compose.override.yml`) before `./docker.sh update`. `music-downloader.env` and the data volume have the
same names, so logins, cookies and history carry over.

## 4. Updating

Updates come from this repository's releases (tags `v<Acacia Radio version>-retail.<n>`), never from upstream. The install keeps a checkout of the source. By default it lives at `/opt/azuracast-retail`; to put it elsewhere, set `RETAIL_SOURCE_DIR` in `.env`. The repository is public, so no credentials are needed.

```bash
git clone https://github.com/Felix1Catus/azuracast-retail.git /opt/azuracast-retail   # once
cd /var/azuracast
./docker.sh backup
./docker.sh update            # newest release; or: ./docker.sh update v0.23.8-retail.2 / main
./docker.sh rollback          # back to the image that ran before the last update
./docker.sh update-self       # refresh docker.sh from the checkout
```

`update` does the following:
1. Fetches the releases.
2. Checks out the chosen release in the source checkout.
3. Builds `azuracast-retail:<version>` with `util/build_image.sh`.
4. Records the running image as `AZURACAST_RETAIL_PREVIOUS_IMAGE`, sets `AZURACAST_RETAIL_IMAGE` in `.env`, and runs `docker compose up -d`.

Database migrations run as the container starts. If the build fails, the running install is not touched.

## 5. Troubleshooting

### Where the logs are

| What | Command |
|---|---|
| Container (startup, migrations, supervisor) | `docker compose logs -f web` |
| Application log | `docker compose exec web sh -c 'tail -f /var/azuracast/www_tmp/app-$(date +%Y-%m-%d).log'` |
| Now Playing, worker, nginx, cron, Centrifugo | `/var/azuracast/www_tmp/app_nowplaying-*.log`, `app_worker-*.log`, `service_*.log` (list them with `docker compose exec web ls /var/azuracast/www_tmp/`) |
| Station (Liquidsoap, Icecast) | Panel: station → **Logs** |

### Known problems

| Symptom | Cause | Fix |
|---|---|---|
| Panel loads without menus or JavaScript | Image built with plain `docker build` from an old checkout, so frontend assets are missing | Build with `util/build_image.sh` (it uses the `assets` stage) |
| Build fails with `i/o timeout`, `Could not resolve host` or `curl error 28` | Unreliable DNS or network on the build machine (common on WSL) | `COMPOSER_MAX_PARALLEL_HTTP=2 BUILD_ATTEMPTS=4 util/build_image.sh`. If DNS keeps failing, build with a builder that has its own resolvers (see below) |
| `Callable App\...\X does not exist` after copying a PHP file into a running container | The image uses a classmap-authoritative autoloader | Rebuild the image, or run `composer dump-autoload --classmap-authoritative` in the container |
| Plugin routes return 404 after installing or updating a plugin | Stale route cache (fixed in the fork: the cache is keyed on route files) | Older images: `rm /var/azuracast/www_tmp/app_routes*.cache.php` and restart |
| Nothing updates after a restore (Now Playing frozen, AutoDJ silent) | `sync_disabled` left on by the restore (fixed in the fork) | `docker compose exec web azuracast_cli azuracast:settings:set sync_disabled false` |
| Branch board shows every branch offline | Older images dropped listener rows when analytics was *none* or *no IP* (fixed). Or players use a direct port | Use the current image; keep **Use Web Proxy for Radio** on |
| Players can't connect after the switch | Players used `:8000` direct ports, which the fork does not publish | Turn on **Use Web Proxy for Radio** and reload the players |
| `./docker.sh update` says "Acacia Radio source not found" | No source checkout on the server | Clone the repository to `/opt/azuracast-retail` or set `RETAIL_SOURCE_DIR` in `.env` ([Updating](#4-updating)) |
| Webhooks don't fire | **Administration → System Settings → Enable all webhooks** is off (the fork makes that switch actually work) | Turn it on |

### Builder with its own DNS

Use this when the build machine's DNS keeps failing:

```bash
mkdir -p ~/.config/azuracast-retail
printf '[dns]\n  nameservers = ["1.1.1.1", "8.8.8.8"]\n' > ~/.config/azuracast-retail/buildkitd.toml
docker buildx create --name retail-builder --driver docker-container \
  --buildkitd-config ~/.config/azuracast-retail/buildkitd.toml --bootstrap
# Registry logins use the builder container's own resolver; point it at public DNS too (resets if the builder restarts).
docker exec buildx_buildkit_retail-builder0 sh -c 'printf "nameserver 1.1.1.1\nnameserver 8.8.8.8\n" > /etc/resolv.conf'
BUILDX_BUILDER=retail-builder BUILD_ATTEMPTS=4 util/build_image.sh azuracast-retail:dev
```

Every fix the fork makes is listed in [FIXLOG.md](FIXLOG.md).
