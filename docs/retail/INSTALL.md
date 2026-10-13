# Installing and running Acacia Radio

| | |
|---|---|
| Server | Ubuntu or Debian, amd64 or arm64, with 2 CPU, 4 GB RAM and 20 GB of disk or more |
| Network | Ports 80 and 443 free and reachable from the internet; for HTTPS, a domain whose DNS points at the server |
| Software | Nothing: the installer sets up Docker Engine and the Compose plugin (2.24 or newer) |

- [1. Install](#1-install)
- [2. After installing](#2-after-installing)
- [3. Updates, backups and rollback](#3-updates-backups-and-rollback)
- [4. Built-in modules](#4-built-in-modules)
- [5. Building the images yourself](#5-building-the-images-yourself)
- [6. Troubleshooting](#6-troubleshooting)

## 1. Install

As root, on the server:

```bash
curl -fsSL https://raw.githubusercontent.com/Felix1Catus/azuracast-retail/main/util/install_retail.sh -o install.sh
sudo bash install.sh --domain radio.example.com --email you@example.com
```

Without a domain yet, leave out `--domain` and `--email`: the site runs on plain HTTP at the server's address
until you add one.

The installer shows one line per step and writes the output of every command to
`/var/log/acacia-install.log`. When a step fails, it shows the last lines of that log and stops; fix the
problem and run it again, and the steps that are already done are skipped. It:

1. checks the server: system, architecture, memory, disk and that ports 80/443 are free
2. installs Docker (from Docker's own apt repository), git and openssl if they're missing
3. downloads the source to `/opt/acacia-radio` (a partial clone: the history for updates, the files of one release)
4. downloads the release images from `ghcr.io/felix1catus` (about 1.5 GB, a few minutes). On arm64, with
   `--build`, or when they can't be downloaded, it builds them on the server instead (15–40 minutes)
5. writes `/var/acacia`: `docker-compose.yml`, `docker.sh`, `.env` (ports, image versions), `acacia.env` (with a
   random database password) and `music-downloader.env` (a random shared secret)
6. starts the containers and waits until the site answers (the first start sets up the database)
7. turns on the `/listen/` radio proxy, and with `--domain`, sets the site address and gets a Let's Encrypt
   certificate

| Option | Default |
|---|---|
| `--domain NAMES` | none: HTTPS is set up later. Several names are comma-separated; the first is the site address |
| `--email ADDRESS` | `admin@<domain>` (Let's Encrypt contact) |
| `--ref REF` | the newest `v*-retail.*` release; a branch name builds that branch |
| `--dir PATH` | `/var/acacia` |
| `--src-dir PATH` | `/opt/acacia-radio` |
| `--http-port N`, `--https-port N` | `80`, `443` |
| `--build` | download the images |
| `--yes` | ask before starting |

| Environment variable | |
|---|---|
| `ACACIA_IMAGE_REGISTRY` | where the images come from (default `ghcr.io/felix1catus`); `none` always builds |
| `RETAIL_GIT_TOKEN` | a read-only GitHub token, only for a private copy of the repository; kept for updates in `<src-dir>/.git/retail-credentials` (mode 600) |

**Ports:** Docker publishes only 80 and 443. Port 80 must stay reachable from the internet so Let's Encrypt can
issue and renew the certificate. If another web server already uses 80 or 443, the installer names it and
stops: stop that server, or choose other ports with `--http-port`/`--https-port` and put a reverse proxy in front.

## 2. After installing

1. Open the site and create the administrator account and the first channel (station).
2. Without `--domain`: add the domain and turn on Let's Encrypt in **Administration → System Settings →
   Services**.
3. Upload music under the channel's **Media**, set up **Playlists**, and add users under **Administration →
   Users** with a role that only covers their channel.
4. Players connect to `https://<domain>/listen/<channel>/<mount>` (the channel's **Profile** shows the exact
   address). Keep **Use Web Proxy for Radio** on: the stations' own ports are not published.

## 3. Updates, backups and rollback

Run these in `/var/acacia`:

```bash
./docker.sh backup                      # database, settings and media into a .tar.gz
./docker.sh update                      # the newest release
./docker.sh update v0.23.8-retail.17    # or a specific release (a branch name builds that branch)
./docker.sh rollback                    # back to the version that ran before the last update
./docker.sh restore backup.tar.gz       # restore a backup
./docker.sh update-self                 # refresh docker.sh itself from the source checkout
```

`update`:
1. fetches the releases into the source checkout (`RETAIL_SOURCE_DIR` in `.env`, default `/opt/acacia-radio`)
   and checks out the chosen one
2. downloads its images (`ghcr.io/felix1catus/acacia-radio:<version>` and `...-music-downloader:<version>`), or
   builds them when they can't be downloaded; if a build fails, the running install isn't touched
3. remembers the running version for `rollback`, brings `docker-compose.yml` up to the release's template (your
   changes belong in `docker-compose.override.yml`), and restarts on the new version
4. removes older images, keeping the running one and the rollback target

Database migrations run as the server starts. Everyone is logged out by an update or rollback.

## 4. Built-in modules

Each module has an item in the channel menu and its own permission (**Administration → Roles**).

**Branch Board** (`plugins/branch-tracker`, permission *View Branches*): branch PIN login and player, live
presence, the board, reports, "log out all", a public read-only board and an optional stream lock. Its tables
are created when the server starts. [Details](../../plugins/branch-tracker/README.md).

**Music Downloader** (`plugins/music-downloader`, permission *Download Music*): Spotify playlist, album or track
links into a folder of the channel's media. Setup (Spotify app, connecting a YouTube account) is under
**Settings** at the bottom of its page. It runs in three extra containers: the worker, a browser used only while
a YouTube account is connected, and a YouTube token helper. Its data is in the `music_downloader_data` volume.
[Details](../../plugins/music-downloader/README.md).

**Azan & Quran** (`plugins/azan-quran`, permission *Manage Azan & Quran*): a 3-minute pause at each prayer time
for the channel's city, and a Quran recording for the hour before opening that fades into the music at opening
time. The opening hours are shared by all channels and set by administrators. After turning it on for a
channel, restart that channel once. [Details](../../plugins/azan-quran/README.md).

## 5. Building the images yourself

The release images are built by GitHub Actions for every release tag
([release-images.yml](../../.github/workflows/release-images.yml)). To build them yourself, on the server or any
Linux machine with Docker and git (about 12 GB free):

```bash
git clone https://github.com/Felix1Catus/azuracast-retail.git acacia-radio && cd acacia-radio
git checkout v0.23.8-retail.17
util/build_image.sh acacia-radio:0.23.8-retail.17
# -> acacia-radio:0.23.8-retail.17 and acacia-radio-music-downloader:0.23.8-retail.17
```

The build compiles the frontend, translations and API docs inside Docker: 15–40 minutes the first time, much
less afterwards. To move the images to a server:

```bash
docker save acacia-radio:0.23.8-retail.17 acacia-radio-music-downloader:0.23.8-retail.17 | gzip \
  | ssh root@SERVER 'gunzip | docker load'
```

**A build machine with unreliable DNS** (common on WSL): use a builder with its own resolvers.

```bash
mkdir -p ~/.config/acacia-radio
printf '[dns]\n  nameservers = ["1.1.1.1", "8.8.8.8"]\n' > ~/.config/acacia-radio/buildkitd.toml
docker buildx create --name retail-builder --driver docker-container \
  --buildkitd-config ~/.config/acacia-radio/buildkitd.toml --bootstrap
docker exec buildx_buildkit_retail-builder0 sh -c 'printf "nameserver 1.1.1.1\nnameserver 8.8.8.8\n" > /etc/resolv.conf'
BUILDX_BUILDER=retail-builder BUILD_ATTEMPTS=4 util/build_image.sh acacia-radio:dev
```

Moving an existing install over to Acacia Radio: see [MIGRATING.md](MIGRATING.md).

## 6. Troubleshooting

### Where the logs are

| What | Where |
|---|---|
| The installer | `/var/log/acacia-install.log` |
| Server start, migrations, services | `docker compose logs -f web` |
| Application | `docker compose exec web sh -c 'tail -f /var/acacia/www_tmp/app-$(date +%Y-%m-%d).log'` |
| Now Playing, worker, nginx, cron | `/var/acacia/www_tmp/app_nowplaying-*.log`, `app_worker-*.log`, `service_*.log` in the `web` container |
| A channel's Liquidsoap and Icecast | Panel: the channel → **Logs** |
| Music Downloader | `docker compose logs -f music-downloader` |

### Known problems

| Symptom | Cause | Fix |
|---|---|---|
| The installer stops at "Ports 80 and 443" | Another web server runs on the machine | Stop it, or install on other ports with `--http-port`/`--https-port` |
| No HTTPS certificate after installing | DNS doesn't point at the server yet, or port 80 is blocked | Fix DNS or the firewall, then `docker compose exec --user=acacia web acacia_cli acacia:acme:get-certificate` |
| "No prebuilt images" during an install or update | The release is still being built by GitHub Actions (about 30 minutes after it's published), or the machine is arm64 | Nothing to do: it builds locally instead. Or wait and run it again |
| Image build fails with `i/o timeout`, `Could not resolve host` or `curl error 28` | Unreliable DNS or network on the build machine | `COMPOSER_MAX_PARALLEL_HTTP=2 BUILD_ATTEMPTS=4 util/build_image.sh`, or the builder with its own DNS above |
| `./docker.sh update` says "source not found" | No source checkout on the server | `git clone https://github.com/Felix1Catus/azuracast-retail.git /opt/acacia-radio`, or set `RETAIL_SOURCE_DIR` in `.env` |
| Players can't connect | They use a station port (`:8000`) instead of `/listen/` | Turn on **Use Web Proxy for Radio** and use the `/listen/` address |
| Branch Board shows every branch offline | Players connect through a station port | Same as above |
| Nothing updates after a restore (Now Playing frozen, AutoDJ silent) | Synchronisation left off by the restore | `./docker.sh cli acacia:settings:set sync_disabled false` |
| `Callable App\...\X does not exist` after copying a PHP file into a running container | The image uses a classmap-authoritative autoloader | Rebuild the image, or run `composer dump-autoload --classmap-authoritative` in the container |
| Webhooks don't fire | **Administration → System Settings → Enable all webhooks** is off | Turn it on |
