# Acacia Radio

**Acacia Radio** is a self-hosted radio platform: one server runs many channels for many users, each with its own
music library, playlists, schedule, streams and public player. It runs in Docker on a single Linux server.

- **Channels:** every channel (station) has its own AutoDJ, playlists, schedules, mount points and listener stats.
  Users get roles per channel, so each customer sees and manages only their own.
- **Music Downloader:** paste a Spotify playlist, album or track link, choose a folder, and the music is added
  to the channel's library ([details](plugins/music-downloader/README.md)).
- **Branch Board:** in-store radio for chains of shops. Each branch's player signs in with a PIN, with a live
  board, listening reports and an optional stream lock ([details](plugins/branch-tracker/README.md)).
- **One web address:** players reach every channel through the site at `/listen/<channel>/<mount>`, over
  HTTPS, so only ports 80 and 443 need to be open.

## Install

On a fresh Ubuntu or Debian server (2 CPU / 4 GB RAM or more, ports 80 and 443), with your domain pointing at it:

```bash
curl -fsSL https://raw.githubusercontent.com/Felix1Catus/azuracast-retail/main/util/install_retail.sh -o install.sh
sudo bash install.sh --domain radio.example.com
```

Then open `https://radio.example.com`, create the administrator account and the first channel.
Details, updating and troubleshooting: [docs/retail/INSTALL.md](docs/retail/INSTALL.md).

```bash
cd /var/azuracast
./docker.sh backup        # before every update
./docker.sh update        # build and switch to the newest release
./docker.sh rollback      # back to the previous version
```

## Documentation

| | |
|---|---|
| [Install and troubleshooting](docs/retail/INSTALL.md) | Requirements, installer options, updates, modules |
| [Architecture](docs/retail/ARCHITECTURE.md) | How the system is put together, where to find things |
| [Rebrand](docs/retail/REBRAND.md) | How the product name is applied (`util/rebrand.py`) |
| [Fork notes](FORK.md) | Branches, releases, testing on a development server |

## License

Acacia Radio is free software under the **GNU Affero General Public License v3.0 or later** ([LICENSE.md](LICENSE.md)).
Every installation serves its exact source code at `/source`, as the licence requires for software used over a
network. It is based on AzuraCast; see [docs/retail/ATTRIBUTION.md](docs/retail/ATTRIBUTION.md).
