"""Worker configuration, read once from the environment."""

import os
from pathlib import Path

DATA_DIR = Path(os.environ.get("MD_DATA_DIR", "/data"))
DB_PATH = DATA_DIR / "worker.db"
JOBS_DIR = DATA_DIR / "jobs"  # per-job staging folders and Spotify token caches
COOKIE_FILE = DATA_DIR / "youtube-cookies.txt"

# Shared secret with the Acacia Radio plugin. Without it the API refuses everything but /health.
TOKEN = os.environ.get("MD_WORKER_TOKEN", "")

# Downloads may only land below this folder (Acacia Radio's station_data volume, mounted at the same path).
# Installs from before the rebrand mount it at the original project's path until ./docker.sh update migrates them;
# The web container always sends /var/acacia/stations paths, which LEGACY_STATIONS_ROOT translates.
CANONICAL_STATIONS_ROOT = Path("/var/acacia/stations")
LEGACY_STATIONS_ROOT = Path("/var/azuracast/stations")  # rebrand:keep
STATIONS_ROOT = Path(
    os.environ.get("MD_STATIONS_ROOT")
    or (LEGACY_STATIONS_ROOT if LEGACY_STATIONS_ROOT.is_dir() and not CANONICAL_STATIONS_ROOT.is_dir()
        else CANONICAL_STATIONS_ROOT)
)

# Playlist lookup is network-bound, so it can use several threads; downloads run one track at a time.
SEARCH_THREADS = int(os.environ.get("MD_SEARCH_THREADS", "4"))
MIN_FREE_MB = int(os.environ.get("MD_MIN_FREE_MB", "1024"))
CANCEL_GRACE_SECONDS = int(os.environ.get("MD_CANCEL_GRACE_SECONDS", "90"))
RUNNER_NICE = int(os.environ.get("MD_RUNNER_NICE", "10"))

# YouTube access (see app/ytdlp.py). The PO-token server is the music-downloader-pot container.
POT_URL = os.environ.get("MD_POT_URL", "http://music-downloader-pot:4416")
YOUTUBE_CLIENTS = os.environ.get("MD_YOUTUBE_CLIENTS", "mweb,web_safari")

# spotDL breaks ties between similar YouTube matches by view count, fetching each candidate's page with yt-dlp
# (up to 8 per track; with PO tokens and JS challenges that's ~50 s of a ~65 s track). Off by default: the
# best text/duration match is used instead. Set MD_RANK_BY_VIEWS=1 to restore spotDL's behaviour.
RANK_BY_VIEWS = os.environ.get("MD_RANK_BY_VIEWS", "0") == "1"

SPOTIFY_SCOPES = "playlist-read-private playlist-read-collaborative user-library-read user-follow-read"

FORMATS = ("mp3", "m4a", "opus", "ogg", "flac")
BITRATES = ("auto", "128k", "160k", "192k", "256k", "320k")
AUDIO_PROVIDERS = ("youtube-music", "youtube", "soundcloud", "bandcamp", "piped")

DEFAULT_SETTINGS = {
    "client_id": "",
    "client_secret": "",
    "proxy": "",
    "audio_providers": "youtube-music,youtube",
    "default_format": "mp3",
    "default_bitrate": "auto",
}
