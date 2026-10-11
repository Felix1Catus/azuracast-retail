# Music Downloader: Spotify playlists into AzuraCast

**Music Downloader** adds a page to each AzuraCast station where staff paste a Spotify playlist, album or
track link, choose a folder of the station's media library, and get the music downloaded there. It uses
[spotDL](https://github.com/spotDL/spotify-downloader): the track list and tags come from Spotify, and the audio
comes from YouTube Music (or the other sources listed in Settings).

| | |
|---|---|
| **Version** | 0.1.0 (first version, not yet integrated into the AzuraCast Retail installer) |
| **Developed on** | AzuraCast Retail 0.23.8-retail.6 · spotDL 4.5.2 · yt-dlp 2026.08.19 · Deno 2.4.5 · ffmpeg 7.1 |
| **Works with** | AzuraCast 0.23.x on Docker, stations with **local** media storage |
| **Not supported** | S3 / Dropbox / SFTP media storage; spotify.link short links; ongoing playlist sync (one-time downloads only) |

## How it works

```
AzuraCast panel ──► PHP plugin (in the AzuraCast container)
                      • /music-downloader/{station}: page; AzuraCast login + "Download Music" permission
                      • /api/station/{station}/music-downloader/...: JSON API used by the page
                      • /music-downloader/oauth/callback: Spotify login return (HTTPS sites)
                          │  internal Docker network, shared secret (music-downloader.env)
                          ▼
                    music-downloader worker container (Python, this folder's worker/)
                      • FastAPI on :8000, never published on a host port
                      • SQLite in the music_downloader_data volume: settings, Spotify logins, jobs, tracks
                      • one job at a time, each in its own process (nice 10, cpu_shares 256, 1 GB RAM limit)
                          │  station_data volume, mounted at the same path as in AzuraCast
                          ▼
         /var/azuracast/stations/<station>/media/<folder>/Artist - Title.mp3
                          │
                          ▼
         AzuraCast's media scan (every 5 minutes) adds the files to the library
```

A job:
1. **Reads the list** from Spotify, with the user's Spotify login when they have one. If the login can't read
   it (see the Spotify rules below), it falls back to spotDL's public access, which needs no account.
2. **Downloads one track at a time** into a staging folder, converts it with ffmpeg, writes tags and cover art,
   and moves the finished file into the media folder. It's copied under a temporary `.mdpart` name and then
   renamed, so AzuraCast never scans half a file.
3. **Skips** tracks whose file already exists in the folder, and **stops early** after 5 failures in a row
   (usually YouTube blocking the server) instead of grinding through the whole list.

Jobs survive a worker restart: interrupted jobs resume and skip what's already saved. **Retry** re-runs only
failed or cancelled tracks.

## Install

From a checkout of the fork on the AzuraCast server:

```bash
sudo bash plugins/music-downloader/install.sh            # install or update
sudo bash plugins/music-downloader/install.sh --uninstall
```

The installer copies the plugin to `<azuracast>/plugins/music-downloader` and builds the worker image locally.
It creates `music-downloader.env` (the shared secret, root-only) and adds the plugin mount and the worker
service to `docker-compose.override.yml` (backed up first). It then recreates the AzuraCast container
(about a minute of downtime) and checks that AzuraCast can reach the worker.

## Setup

1. **Spotify app** (needed for the Spotify login, not for public playlists). At
   [developer.spotify.com/dashboard](https://developer.spotify.com/dashboard), create an app with *Web API*
   and register the **Redirect URI** shown under Settings on the Music Downloader page. Then enter the
   app's Client ID and Client Secret there.
   - Spotify requires the app **owner to have Spotify Premium**, and a development-mode app allows **5 users**.
     Each user must be added under *User Management* in the dashboard (these rules date from February 2026).
   - **HTTPS site:** the redirect URI is `https://<your site>/music-downloader/oauth/callback`, and the login
     finishes by itself.
   - **No HTTPS:** Spotify only accepts HTTPS or `127.0.0.1` redirect URIs, so the redirect URI is
     `http://127.0.0.1:8888/callback`. After approving, the browser lands on a page that doesn't load, and the
     user pastes that address into the page. `MUSIC_DOWNLOADER_REDIRECT_URI` in `music-downloader.env`
     overrides the choice.
2. **Permissions.** Administrators can use everything. Other roles need the station permission **Download
   Music** (Administration → Roles). Settings need *administer settings*.
3. **Each user** clicks **Connect Spotify** on the page. Logins are per AzuraCast user.

### What a Spotify login can read (Spotify's 2026 rules)

Spotify's Web API returns a playlist's tracks only for playlists the logged-in user **owns or collaborates
on**. So:

| Link | How it's read |
|---|---|
| Your own playlist (public or private), or one you collaborate on | your Spotify login |
| Someone else's public playlist, Spotify's editorial playlists, albums, tracks | public access (automatic fallback) |
| Someone else's private playlist | not possible: ask the owner to add you as a collaborator |

### YouTube blocking the server

Datacenter IP addresses (Vultr, Hetzner, DigitalOcean, AWS…) are often blocked by YouTube ("Sign in to
confirm you're not a bot"). The page then shows *YouTube refused the download*. Options, in Settings:

- **YouTube cookies** from a normal (free) YouTube account: sign in to youtube.com in a private window, export
  `cookies.txt` (Netscape format), close the window, and paste the file. Use a separate account, because
  YouTube may restrict accounts used this way. Cookies expire, so refresh them when downloads start failing again.
- **Proxy:** `http://user:pass@host:port` or `socks5://host:port`. A residential proxy avoids the block.

SoundCloud and Bandcamp are offered as audio sources too, but major-label music on SoundCloud is DRM-protected
and can't be downloaded.

## Files

| Path | What |
|---|---|
| `events.php`, `services.php` | Plugin wiring: routes, the "Download Music" permission, class autoloader |
| `src/Controller/` | Page, station API, settings API, Spotify callback, nav script |
| `src/Service/WorkerClient.php` | HTTP client for the worker (`MUSIC_DOWNLOADER_WORKER_URL`, `MUSIC_DOWNLOADER_WORKER_TOKEN`) |
| `src/Service/MediaFolders.php` | Which folders downloads may go to (local station media only) |
| `src/Middleware/InjectNavButton.php`, `templates/nav.js` | "Music Downloader" button on station pages |
| `templates/page.html` | The page (plain HTML + JS, no build step) |
| `worker/` | The worker image: `app/main.py` (API + scheduler), `app/runner.py` (one job), `app/db.py` |
| `tests/worker_selftest.py` | Offline test of the runner (fake download step; checks files, skips, cancel) |
| `install.sh`, `reload.sh` | Install/update/uninstall; apply code changes to a running container |

## Development

```bash
docker build -t azuracast-music-downloader:dev plugins/music-downloader/worker
docker run --rm -e MD_DATA_DIR=/tmp/md -e MD_STATIONS_ROOT=/tmp/stations \
  -v "$PWD/plugins/music-downloader/tests:/tests:ro" azuracast-music-downloader:dev python /tests/worker_selftest.py
plugins/music-downloader/reload.sh        # after changing PHP or templates on a server where it's mounted
```

**When downloads start failing everywhere,** YouTube has usually changed something. Bump `yt-dlp` in
`worker/requirements.txt` and re-run the installer.

## Integrating into AzuraCast Retail (next steps)

The fork image already contains this folder (the Dockerfile copies the repository), so the PHP side loads on
every fork install. Without the worker, the page says the downloader is offline and the nav button stays
hidden. To make it a built-in module like the Tracker:

1. Add the `music-downloader` service, the `music_downloader_data` volume and `env_file` to
   `docker-compose.retail.yml`. Build and tag the worker image in `util/build_image.sh`. Generate the
   secret in `util/install_retail.sh`.
2. Add `StationFeatures::MusicDownloader` and a station menu item in `frontend/components/Stations/menu.ts`
   (as for `BranchBoard`). `events.php` turns the injected button off by itself once that enum case exists.
3. Optionally trigger AzuraCast's media scan when a job finishes, so files appear right away instead of
   within 5 minutes.
