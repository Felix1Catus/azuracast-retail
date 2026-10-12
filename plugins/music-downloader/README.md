# Music Downloader: Spotify playlists into Acacia Radio

**Music Downloader** adds a page to each Acacia Radio station where staff paste a Spotify playlist, album or
track link, choose a folder of the station's media library, and get the music downloaded there. It uses
[spotDL](https://github.com/spotDL/spotify-downloader): the track list and tags come from Spotify, and the audio
comes from YouTube Music (or the other sources listed in Settings).

| | |
|---|---|
| **Version** | 0.1.3 (first version, not yet integrated into the Acacia Radio installer) |
| **Developed on** | upstream 0.23.8-retail.6 · spotDL 4.5.2 · yt-dlp 2026.08.19 · Deno 2.4.5 · ffmpeg 7.1 |
| **Works with** | upstream 0.23.x on Docker, stations with **local** media storage |
| **Not supported** | S3 / Dropbox / SFTP media storage; spotify.link short links; ongoing playlist sync (one-time downloads only) |

## How it works

```
Acacia Radio panel ──► PHP plugin (in the Acacia Radio container)
                      • /music-downloader/{station}: page; Acacia Radio login + "Download Music" permission
                      • /api/station/{station}/music-downloader/...: JSON API used by the page
                      • /music-downloader/oauth/callback: Spotify login return (HTTPS sites)
                          │  internal Docker network, shared secret (music-downloader.env)
                          ▼
                    music-downloader worker container (Python, this folder's worker/)
                      • FastAPI on :8000, never published on a host port
                      • SQLite in the music_downloader_data volume: settings, Spotify logins, jobs, tracks
                      • one job at a time, each in its own process (nice 10, cpu_shares 256, 1 GB RAM limit)
                      • asks music-downloader-pot (bgutil PO-token server, 512 MB limit) for YouTube tokens
                      • asks music-downloader-browser (same image, Chromium, 1 GB limit) for fresh YouTube cookies
                          │  station_data volume, mounted at the same path as in Acacia Radio
                          ▼
         /var/acacia/stations/<station>/media/<folder>/Artist - Title.mp3
                          │
                          ▼
         Acacia Radio's media scan (every 5 minutes) adds the files to the library
```

A job:
1. **Reads the list** from Spotify, with the user's Spotify login when they have one. If the login can't read
   it (see the Spotify rules below), it falls back to spotDL's public access, which needs no account.
2. **Downloads one track at a time** into a staging folder, converts it with ffmpeg, writes tags and cover art,
   and moves the finished file into the media folder. It's copied under a temporary `.mdpart` name and then
   renamed, so Acacia Radio never scans half a file.
3. **Skips** tracks whose file already exists in the folder, and **stops early** after 5 failures in a row
   (usually YouTube blocking the server) instead of grinding through the whole list.

Jobs survive a worker restart: interrupted jobs resume and skip what's already saved. **Retry** re-runs only
failed or cancelled tracks.

## Install

On the Acacia Radio server (Docker install), as root. The plugin is kept in two places: its own private
repository (this folder at the repository root), and `plugins/music-downloader` in the Acacia Radio fork.

**From the plugin's private repository.** The server needs read access: an SSH key on a GitHub account that can
see the repository, or a read-only deploy key.

```bash
git clone git@github.com:Felix1Catus/acacia-music-downloader.git /opt/acacia-music-downloader
sudo bash /opt/acacia-music-downloader/install.sh              # install, or update after a git pull
sudo bash /opt/acacia-music-downloader/install.sh --uninstall
```

**From a checkout of the fork:**

```bash
sudo bash plugins/music-downloader/install.sh            # install or update
sudo bash plugins/music-downloader/install.sh --uninstall
```

`--dir /path/to/acacia` selects the installation if it isn't auto-detected; `--yes` skips the confirmation.
Updating: `git pull`, then run `install.sh` again. It rebuilds the worker and swaps the files in place, and
only recreates the Acacia Radio container when its mounts change. The first install needs about 2 GB of disk
space for the images.

The installer copies the plugin to `<acacia>/plugins/music-downloader` and builds the worker image locally.
It creates `music-downloader.env` (the shared secret, root-only) and adds the plugin mount and the worker
service to `docker-compose.override.yml` (backed up first). It then recreates the Acacia Radio container
(about a minute of downtime) and checks that Acacia Radio can reach the worker.

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
3. **Each user** clicks **Connect Spotify** on the page. Logins are per Acacia Radio user.

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

- **YouTube account (recommended):** press **Connect YouTube account** and sign a separate Google account in, in
  a browser that runs on the server (the `music-downloader-browser` container) and is shown on the page as a
  live picture you can click and type into. No password is stored. On **Done**, the session's youtube.com
  cookies go to the worker and the browser deletes its profile: Google signs a session out as soon as two
  different clients use it (tested: Chromium reopening a session that yt-dlp had used was signed out within
  seconds), so yt-dlp must be its only user. This is yt-dlp's "export from a private window, then close it",
  done for you. yt-dlp writes back what YouTube sends, which keeps the session current. The worker checks the
  session every 6 hours (`MD_YT_CHECK_HOURS`) while idle and when a download is refused; when YouTube signs it
  out, settings admins see a dashboard notice and get an email (when email is set up) to sign in again, which
  starts a new session. Chromium only runs while the sign-in view is open.
- **YouTube cookies** by hand from a normal (free) YouTube account: sign in to youtube.com in a private window, export
  `cookies.txt` (Netscape format) **from the youtube.com tab** while your account picture shows at the top
  right, close the window, and paste the file. Only youtube.com cookies are kept. A signed-in YouTube session
  is the most robust, but visitor cookies from a real browser often work too: **Test with YouTube** checks them. Use a separate account, because
  YouTube may restrict accounts used this way. Cookies expire, so refresh them when downloads start failing again.
- **Proxy:** `http://user:pass@host:port` or `socks5://host:port`. A residential proxy avoids the block.

Even when signed in, YouTube may offer the regular web player only as SABR streams, which can't be downloaded.
The worker therefore asks for the `web_safari` client first (it also gets HLS streams) and attaches PO tokens
from the `music-downloader-pot` container. If no audio-only stream is offered, it takes the HLS stream's AAC
audio (about 128 kbps) and drops the video. `MD_YOUTUBE_CLIENTS` and `MD_POT_URL` (in `music-downloader.env`)
change these choices; see `worker/app/ytdlp.py`.

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
| `worker/app/youtube_session.py` | YouTube account: checks, state and alerts (worker side) |
| `worker/app/browser.py`, `browser_api.py` | The `music-downloader-browser` service: Chromium for the sign-in (remote view) |
| `src/Notification/`, `src/Sync/` | Dashboard notice and alert email when the YouTube account needs a new sign-in |
| `tests/worker_selftest.py` | Offline test of the runner (fake download step; checks files, skips, cancel) |
| `install.sh`, `reload.sh` | Install/update/uninstall; apply code changes to a running container |

## Development

```bash
docker build -t acacia-music-downloader:dev plugins/music-downloader/worker
docker run --rm -e MD_DATA_DIR=/tmp/md -e MD_STATIONS_ROOT=/tmp/stations \
  -v "$PWD/plugins/music-downloader/tests:/tests:ro" acacia-music-downloader:dev python /tests/worker_selftest.py
plugins/music-downloader/reload.sh        # after changing PHP or templates on a server where it's mounted
```

**When downloads start failing everywhere,** YouTube has usually changed something. Bump `yt-dlp` in
`worker/requirements.txt` and re-run the installer.

## Built into Acacia Radio

In the fork this module is built in: `docker-compose.retail.yml` runs the worker, token and browser containers, and
`util/build_image.sh` builds the worker image as `acacia-radio-music-downloader:<version>`. The installer and
`./docker.sh update` create `music-downloader.env`, and the station menu has a native **Music Downloader**
item (`StationFeatures::MusicDownloader`, which also switches off the injected button). There, use
`./docker.sh update` and not this `install.sh`; the installer refuses such installs. See
`docs/retail/INSTALL.md`, section 3b.

Still open: trigger Acacia Radio's media scan when a job finishes, so files appear right away instead of within
5 minutes.
