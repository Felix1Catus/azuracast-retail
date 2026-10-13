<div align="center">

# Acacia Radio

**Self-hosted radio for many channels on one server.**

Run a radio station for every shop, brand or customer from a single installation: each channel gets its own
music library, playlists, schedule, stream and public player, and each customer only sees their own.

[Install](#install) · [Features](#features) · [Running it](#running-it) · [Modules](#built-in-modules) ·
[Documentation](#documentation)

</div>

---

## Features

**Channels**
- Every channel has its own **AutoDJ**, playlists (rotation, sequential, scheduled, "once per hour" and more),
  schedule and mount points.
- A **media library** per channel with folders, bulk upload, tags, cue points and cover art.
- **Roles per channel:** give a customer access to their channel only, with exactly the permissions they need.

**Listening**
- **One web address for everything.** Players reach every channel at `https://your-domain/listen/<channel>/<mount>`,
  so only ports 80 and 443 are open, and every stream is on HTTPS.
- A **public page and embeddable player** for every channel.
- **Free HTTPS certificates** from Let's Encrypt, renewed automatically.

**Insight**
- A **dashboard** with listeners right now, unique listeners, channels on air and the most-listened channel.
- **Listener statistics and reports** per channel: listeners over time, top songs, where listeners tune in from.

**Look and feel**
- A calm, light-and-dark interface with large, readable controls, designed to be used by shop staff as well as
  administrators.

## Built-in modules

| Module | What it does |
|---|---|
| **[Branch Board](plugins/branch-tracker/README.md)** | In-store radio for chains of shops. Each branch's player signs in with a PIN; a live board shows which branches are playing, with listening reports, "log out all" and an optional stream lock. |
| **[Music Downloader](plugins/music-downloader/README.md)** | Paste a Spotify playlist, album or track link, choose a folder, and the tracks are added to the channel's library. Connect a YouTube account once from the settings page; the server keeps it working and tells you if it ever needs a new sign-in. |
| **[Azan & Quran](plugins/azan-quran/README.md)** | For shops in the region: the music pauses for 3 minutes at each prayer time (times for each channel's city, updated daily) and a Quran recording plays for the hour before opening, fading into the music at opening time. |

Each module has its own item in the channel menu and its own permission, so you decide who can use it.

## Install

You need a fresh **Ubuntu or Debian** server (amd64 or arm64) with **2 CPU, 4 GB RAM and 20 GB of disk** or
more, ports **80 and 443** free, and, for HTTPS, a domain whose DNS points at the server. As root:

```bash
curl -fsSL https://raw.githubusercontent.com/Felix1Catus/azuracast-retail/main/util/install_retail.sh -o install.sh
sudo bash install.sh --domain radio.example.com
```

It takes about **five minutes**: the installer sets up Docker, downloads the ready-made images, writes the
configuration, starts everything and gets an HTTPS certificate. The terminal stays tidy, one line per step,
and the full detail goes to `/var/log/acacia-install.log`:

```text
  Acacia Radio installer
  ──────────────────────

Checking this server
  ✓ Ubuntu 24.04.1 LTS · amd64 · 4 CPU · 7.8 GB RAM · 61 GB free
  ✓ Ports 80 and 443 are available

Preparing the server
  ✓ Installed Docker  41s
  ✓ Downloaded the source  3s
  ✓ Release 0.23.8-retail.17  2026-10-13

Getting the images
  ✓ Downloaded the images  2m 10s
  ...
  ╭────────────────────────────────────────────────────────────╮
  │  Acacia Radio 0.23.8-retail.17 is ready                    │
  │  Open https://radio.example.com                            │
  ╰────────────────────────────────────────────────────────────╯
```

Then open the address, create the administrator account and your first channel.

**Options:** `--domain` (one or more, comma-separated), `--email` for Let's Encrypt, `--ref` for a specific
release, `--dir` for the install directory (default `/var/acacia`), `--http-port` / `--https-port`, `--build` to
build the images on the server instead of downloading them, and `--yes` to skip the confirmation.
`sudo bash install.sh --help` lists them all. Running the installer again is safe: finished steps are skipped,
and it's also the way to repair an install.

No domain yet? Leave out `--domain`; the site runs on the server's IP address over HTTP until you add one under
**Administration → System Settings → Services**.

## Running it

Everything lives in `/var/acacia`; run these commands there.

| Task | Command |
|---|---|
| Update to the newest release | `./docker.sh update` |
| Install a specific release | `./docker.sh update v0.23.8-retail.17` |
| Go back to the previous version | `./docker.sh rollback` |
| Back up the database, settings and media | `./docker.sh backup` |
| Restore a backup | `./docker.sh restore backup.tar.gz` |
| Follow the logs | `docker compose logs -f` |
| Restart everything | `./docker.sh restart` |
| Command line inside the server | `./docker.sh cli <command>` (for example `./docker.sh cli acacia:settings:list`) |

**Updates** download the new release's images and restart on them; it takes a couple of minutes, database
changes are applied as the server starts, and everyone has to log in again afterwards. Take a backup first.
If the images can't be downloaded (or you prefer it), the update builds them on the server instead.

### What runs on the server

| Container | What it is |
|---|---|
| `acacia` | The radio server: web panel and API (nginx, PHP), database (MariaDB), cache (Valkey), and one Liquidsoap + Icecast pair per channel |
| `acacia_music_downloader` | Music Downloader worker (spotDL, yt-dlp, ffmpeg), one download at a time |
| `acacia_music_downloader_browser` | A browser used only while a YouTube account is being connected |
| `acacia_music_downloader_pot` | Helper that lets the downloader get through YouTube's checks |

| File in `/var/acacia` | Holds |
|---|---|
| `docker-compose.yml` | The containers (replaced on update; put your own changes in `docker-compose.override.yml`) |
| `.env` | Ports, the image versions in use, where the source checkout is |
| `acacia.env` | Database and application settings (created with a random database password) |
| `music-downloader.env` | The secret the radio server and the downloader share |

Your data (media, database, certificates, uploads, backups) lives in Docker volumes and survives updates,
rollbacks and reinstalls.

## Documentation

| Guide | |
|---|---|
| [Installing and running](docs/retail/INSTALL.md) | Requirements, installer options, updates, building the images yourself, troubleshooting |
| [Architecture](docs/retail/ARCHITECTURE.md) | How the system is put together and where to find things in the code |
| [Branch Board](plugins/branch-tracker/README.md), [Music Downloader](plugins/music-downloader/README.md), [Azan & Quran](plugins/azan-quran/README.md) | Setting up and using each module |
| [Release notes](docs/retail/FIXLOG.md) | What changed in each release |
| [Development](docs/retail/DEVELOPMENT.md) | Branches, releases and testing |

## Building from source

The release images are built by GitHub Actions from this repository for every release
([`.github/workflows/release-images.yml`](.github/workflows/release-images.yml)) and published at
`ghcr.io/felix1catus/acacia-radio`. To build them yourself:

```bash
git clone https://github.com/Felix1Catus/azuracast-retail.git acacia-radio && cd acacia-radio
util/build_image.sh acacia-radio:dev        # 15-40 minutes; also builds acacia-radio-music-downloader:dev
```

## License

Acacia Radio is free software under the **GNU Affero General Public License v3.0 or later**
([LICENSE.md](LICENSE.md)). The **Source code** link at the bottom of every page leads to this repository, at
the exact version that server runs. Acacia Radio is built on [AzuraCast](https://www.azuracast.com/); see
[ATTRIBUTION.md](docs/retail/ATTRIBUTION.md).
