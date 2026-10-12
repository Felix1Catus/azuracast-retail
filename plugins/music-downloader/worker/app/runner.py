"""Runs one job in its own process:  python -m app.runner <job_id>

spotDL keeps a single Spotify client per process, so every job gets a fresh process. The API process starts
runners one at a time (see main.Scheduler) and reads progress from the database.

Steps:
  1. resolve: read the playlist/album/track from Spotify (with the user's Spotify login when there is one,
     otherwise, or when the login can't read it, with spotDL's public access) and store one row per track
  2. download: one track at a time into a staging folder, then move the finished file into the station's
     media folder (copied under a temporary ".mdpart" name and renamed, so Acacia Radio never scans half a file)
"""

import json
import logging
import os
import shlex
import shutil
import signal
import sys
from pathlib import Path
from typing import List, Optional

from app import config, db, youtube_session, ytdlp

log = logging.getLogger("music-downloader.runner")

FILE_TEMPLATE = "{artists} - {title}.{output-ext}"
MAX_FILENAME_LENGTH = 180
FAILURES_IN_A_ROW_LIMIT = 5
# yt-dlp errors that mean YouTube no longer accepts the cookies.
SIGNED_OUT_MARKERS = ("Sign in to confirm", "no longer valid", "cookies are no longer valid")


class JobError(Exception):
    pass


def _terminate(_signum, _frame):
    raise SystemExit(143)


def main(job_id: int) -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    signal.signal(signal.SIGTERM, _terminate)

    conn = db.connect()
    job = db.get_job(conn, job_id)
    if job is None or job["status"] != "queued":
        log.info("Job %s is not queued; nothing to do.", job_id)
        return 0

    workdir = config.JOBS_DIR / str(job_id)
    staging = workdir / "staging"
    token_cache = workdir / "spotify-token.json"
    staging.mkdir(parents=True, exist_ok=True)

    db.update_job(conn, job_id, status="resolving", started_at=db.now(), error=None)
    login_user: Optional[int] = None

    try:
        settings = db.get_settings(conn)
        has_tracks = conn.execute("SELECT 1 FROM tracks WHERE job_id = ? LIMIT 1", (job_id,)).fetchone()

        account = db.get_account(conn, job["user_id"]) if job["use_login"] else None
        login_usable = account is not None and settings["client_id"] and settings["client_secret"]
        if login_usable:
            token_cache.write_text(account["token_json"])
            token_cache.chmod(0o600)
            login_user = job["user_id"]

        if has_tracks:
            # Retry or resume: the track list is already stored; the Spotify client is only needed for tags.
            use_login = login_usable and job["source"] == "spotify-login"
            _init_spotify(settings, token_cache if use_login else None)
        else:
            songs, source, note = _resolve(job, settings, token_cache if login_usable else None)
            _store_tracks(conn, job, songs, source, note)

        _persist_token(conn, login_user, token_cache)
        db.update_job(conn, job_id, status="downloading")
        result = _download(conn, db.get_job(conn, job_id), settings, staging)
        _persist_token(conn, login_user, token_cache)

        db.refresh_counts(conn, job_id)
        final = db.get_job(conn, job_id)
        if result == "cancelled":
            conn.execute(
                "UPDATE tracks SET status = 'cancelled' WHERE job_id = ? AND status IN ('pending', 'downloading')",
                (job_id,),
            )
            db.update_job(conn, job_id, status="cancelled", finished_at=db.now())
        elif result is not None:
            db.update_job(conn, job_id, status="failed", error=result, finished_at=db.now())
        elif final["total"] > 0 and final["failed"] == final["total"]:
            db.update_job(conn, job_id, status="failed", error="No track could be downloaded.", finished_at=db.now())
        else:
            db.update_job(conn, job_id, status="done", finished_at=db.now())
        return 0

    except JobError as exc:
        db.update_job(conn, job_id, status="failed", error=str(exc), finished_at=db.now())
        return 0
    except SystemExit:
        db.update_job(conn, job_id, status="cancelled", finished_at=db.now())
        raise
    except Exception as exc:  # pylint: disable=broad-except
        log.exception("Job %s crashed", job_id)
        db.update_job(conn, job_id, status="failed", error=f"Unexpected error: {exc}", finished_at=db.now())
        return 1
    finally:
        try:
            _persist_token(conn, login_user, token_cache)
        except Exception:  # pylint: disable=broad-except
            log.exception("Could not save the refreshed Spotify token")
        token_cache.unlink(missing_ok=True)
        shutil.rmtree(staging, ignore_errors=True)
        db.refresh_counts(conn, job_id)
        conn.close()


# ------------------------------------------------------------------ Spotify


def _init_spotify(settings, token_cache: Optional[Path]) -> None:
    """(Re)create spotDL's process-wide Spotify client: the user's login, or spotDL's public access."""
    from spotdl.utils.config import SPOTIFY_OPTIONS
    from spotdl.utils.spotify import SpotifyClient

    SpotifyClient._instance = None  # pylint: disable=protected-access
    if token_cache is not None:
        SpotifyClient.init(
            client_id=settings["client_id"],
            client_secret=settings["client_secret"],
            user_auth=True,
            headless=True,
            use_official_api=True,
            cache_path=str(token_cache),
        )
    else:
        SpotifyClient.init(
            client_id=SPOTIFY_OPTIONS["client_id"],
            client_secret=SPOTIFY_OPTIONS["client_secret"],
            headless=True,
        )


def _query(url: str) -> list:
    from spotdl.utils.search import parse_query

    return parse_query(
        query=[url],
        threads=config.SEARCH_THREADS,
        use_ytm_data=False,
        playlist_numbering=False,
        album_type=None,
        playlist_retain_track_cover=False,
    )


def _resolve(job, settings, token_cache: Optional[Path]):
    login_error = None
    if token_cache is not None:
        try:
            _init_spotify(settings, token_cache)
            songs = _query(job["url"])
            if songs:
                return songs, "spotify-login", None
            login_error = "the list came back empty"
        except Exception as exc:  # pylint: disable=broad-except
            login_error = str(exc) or exc.__class__.__name__
        log.info("Spotify login couldn't read %s (%s); trying public access", job["url"], login_error)

    try:
        _init_spotify(settings, None)
        songs = _query(job["url"])
    except Exception as exc:  # pylint: disable=broad-except
        log.info("Public access failed for %s: %s", job["url"], exc)
        if token_cache is not None:
            raise JobError(
                "Spotify didn't return this list, either with your Spotify login or publicly. If it's a "
                "private playlist, it must be owned by (or shared for collaboration with) the Spotify "
                "account connected here."
            ) from exc
        raise JobError(
            "Spotify didn't return this list publicly. If it's a private playlist, connect the Spotify "
            "account that owns it and try again."
        ) from exc

    if not songs:
        raise JobError("Spotify returned no tracks for this link.")

    note = None
    if token_cache is not None:
        note = (
            "Read without your Spotify login: Spotify only lets apps read playlists you own or "
            "collaborate on, so public access was used."
        )
    return songs, "public", note


def _store_tracks(conn, job, songs: list, source: str, note: Optional[str]) -> None:
    first = songs[0]
    if job["kind"] == "track":
        title = first.display_name
    else:
        title = first.list_name or first.album_name or job["url"]

    conn.execute("BEGIN")
    try:
        for position, song in enumerate(songs, start=1):
            conn.execute(
                "INSERT INTO tracks (job_id, position, title, artists, spotify_url, status, song_json) "
                "VALUES (?, ?, ?, ?, ?, 'pending', ?)",
                (job["id"], position, song.name, ", ".join(song.artists or []), song.url, json.dumps(song.json)),
            )
        db.update_job(conn, job["id"], title=title, source=source, note=note)
        conn.execute("COMMIT")
    except Exception:
        conn.execute("ROLLBACK")
        raise
    db.refresh_counts(conn, job["id"])


def _persist_token(conn, user_id: Optional[int], token_cache: Path) -> None:
    """spotipy rewrites the cache file when it refreshes the token; keep the newest copy for the next job."""
    if user_id is None or not token_cache.exists():
        return
    try:
        token = json.loads(token_cache.read_text())
    except (OSError, ValueError):
        return
    if isinstance(token, dict) and token.get("access_token"):
        account = db.get_account(conn, user_id)
        if account is not None and json.loads(account["token_json"]).get("access_token") != token["access_token"]:
            db.save_account_token(conn, user_id, token)


# ------------------------------------------------------------------ downloads


def _download(conn, job, settings, staging: Path) -> Optional[str]:
    """Download every pending track. Returns None when finished, "cancelled", or an error message."""
    from spotdl.download.downloader import Downloader
    from spotdl.types.song import Song
    from spotdl.utils.formatter import create_file_name

    _speed_up_spotdl_search()

    dest = Path(job["dest_dir"])
    dest.mkdir(parents=True, exist_ok=True)
    _check_free_space(dest)

    providers: List[str] = [
        p.strip() for p in settings["audio_providers"].split(",") if p.strip() in config.AUDIO_PROVIDERS
    ] or ["youtube-music"]

    def new_downloader():
        # spotDL's YouTube provider reads the cookie file when it's created, so a refresh needs a new one.
        return Downloader(
            settings={
                "output": str(staging / FILE_TEMPLATE),
                "format": job["format"],
                "bitrate": job["bitrate"],
                "audio_providers": providers,
                "lyrics_providers": [],
                "threads": 1,
                "overwrite": "skip",
                "cookie_file": str(config.COOKIE_FILE) if config.COOKIE_FILE.exists() else None,
                "proxy": settings["proxy"] or None,
                "max_filename_length": MAX_FILENAME_LENGTH,
                "yt_dlp_args": shlex.join(ytdlp.args()),
                "ffmpeg_args": "-vn",  # HLS fallback formats carry a video track
                "simple_tui": True,
                "log_level": "INFO",
            }
        )

    downloader = new_downloader()
    refreshed_cookies = False

    # Pass 1 downloads everything pending; pass 2 retries this run's failures once (the first YouTube request
    # after a restart sometimes fails while the PO-token server warms up).
    for attempt in (1, 2):
        statuses = "('pending', 'downloading')" if attempt == 1 else "('failed')"
        rows = conn.execute(
            f"SELECT * FROM tracks WHERE job_id = ? AND status IN {statuses} ORDER BY position",
            (job["id"],),
        ).fetchall()
        if attempt == 2 and rows:
            log.info("Retrying %d failed track(s) once", len(rows))
        failures_in_a_row = 0

        for row in rows:
            if _cancel_requested(conn, job["id"]):
                return "cancelled"

            song = Song.from_dict(json.loads(row["song_json"]))
            target = create_file_name(
                song=song,
                template=str(dest / FILE_TEMPLATE),
                file_extension=job["format"],
                file_name_length=MAX_FILENAME_LENGTH,
            )
            if target.exists():
                _set_track(conn, row["id"], "skipped", file=target.name, error="Already in this folder")
                failures_in_a_row = 0
                continue

            _set_track(conn, row["id"], "downloading")
            errors_before = len(downloader.errors)
            path: Optional[Path] = None
            error: Optional[str] = None
            try:
                _, path = downloader.loop.run_until_complete(downloader.async_search_and_download(song))
            except Exception as exc:  # pylint: disable=broad-except
                error = str(exc) or exc.__class__.__name__

            if path is not None and Path(path).exists():
                failures_in_a_row = 0
                staged = Path(path)
                final = dest / staged.name
                if final.exists():
                    staged.unlink()
                    _set_track(conn, row["id"], "skipped", file=final.name, error="Already in this folder")
                else:
                    part = final.with_name(final.name + ".mdpart")
                    shutil.copyfile(staged, part)
                    part.chmod(0o664)
                    os.replace(part, final)
                    staged.unlink()
                    _set_track(conn, row["id"], "done", file=final.name)
            else:
                failures_in_a_row += 1
                new_errors = downloader.errors[errors_before:]
                raw_error = error or (new_errors[-1] if new_errors else None)
                _set_track(conn, row["id"], "failed", error=_friendly_error(raw_error))
                if not refreshed_cookies and raw_error and any(m in raw_error for m in SIGNED_OUT_MARKERS):
                    # Once per job: get fresh cookies from the YouTube account's browser; the retry pass
                    # downloads this track again.
                    refreshed_cookies = True
                    log.info("YouTube refused the cookies; refreshing them from the YouTube account")
                    try:
                        if youtube_session.refresh().get("status") == "connected":
                            downloader = new_downloader()
                            failures_in_a_row = 0
                    except Exception:  # pylint: disable=broad-except
                        log.exception("YouTube cookie refresh failed")

            db.refresh_counts(conn, job["id"])

            if failures_in_a_row >= FAILURES_IN_A_ROW_LIMIT:
                return (
                    f"Stopped after {FAILURES_IN_A_ROW_LIMIT} failed tracks in a row. If they all say YouTube "
                    "refused the download, YouTube is blocking this server: add YouTube cookies or a proxy in "
                    "Settings, then retry."
                )

    return None


def _speed_up_spotdl_search() -> None:
    """spotDL's YouTube search fetches every result page in full ("ytsearch10:" without flat extraction) and
    may fetch up to 8 more for view counts. Behind PO tokens and JS challenges each fetch takes ~6 s, so
    matching took ~60 s per track. A flat search returns the same title, duration, channel and views from
    the single search page."""
    from spotdl.providers.audio.base import AudioProvider
    from spotdl.providers.audio.youtube import YouTube

    original_get_results = YouTube.get_results

    def flat_get_results(self, search_term, *args, **kwargs):
        params = self.audio_handler.params
        previous = params.get("extract_flat")
        params["extract_flat"] = "in_playlist"
        try:
            return original_get_results(self, search_term, *args, **kwargs)
        finally:
            params["extract_flat"] = previous

    YouTube.get_results = flat_get_results  # type: ignore[method-assign]

    if not config.RANK_BY_VIEWS:
        # Views of 0 for results without them make get_best_result() keep the best-scoring match.
        AudioProvider.get_views = lambda self, url: 0  # type: ignore[method-assign]


def _set_track(conn, track_id: int, status: str, file: Optional[str] = None, error: Optional[str] = None) -> None:
    conn.execute("UPDATE tracks SET status = ?, file = ?, error = ? WHERE id = ?", (status, file, error, track_id))


def _cancel_requested(conn, job_id: int) -> bool:
    row = conn.execute("SELECT status FROM jobs WHERE id = ?", (job_id,)).fetchone()
    return row is None or row["status"] == "cancelling"


def _check_free_space(dest: Path) -> None:
    free_mb = shutil.disk_usage(dest).free // (1024 * 1024)
    if free_mb < config.MIN_FREE_MB:
        raise JobError(f"Only {free_mb} MB free on the station storage (at least {config.MIN_FREE_MB} MB needed).")


def _friendly_error(raw: Optional[str]) -> str:
    if not raw:
        return "No matching audio found."
    # spotDL prefixes errors with the Spotify URL: "https://open.spotify.com/track/... - AudioProviderError: ..."
    text = raw.split(" - ", 1)[1] if raw.startswith("https://open.spotify.com/") and " - " in raw else raw
    if "YT-DLP download error" in text:
        return "YouTube refused the download (often: YouTube is blocking this server). " + text
    if "No results found" in text or "LookupError" in text:
        return "No matching audio found. " + text
    return text[:500]


if __name__ == "__main__":
    sys.exit(main(int(sys.argv[1])))
