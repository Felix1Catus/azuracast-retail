"""Music Downloader worker API. Internal only: reachable from AzuraCast's container on the Docker network,
never published on a host port. Every call except /health needs the shared token (MD_WORKER_TOKEN).

The AzuraCast plugin does the user-facing checks (login, permissions, which station and folder); this API
trusts the user id and station it is given, and only checks that files stay inside the stations folder.
"""

import hmac
import json
import logging
import os
import re
import signal
import subprocess
import sys
import threading
from pathlib import Path
from typing import Any, Dict, List, Optional

from fastapi import Depends, FastAPI, Header, HTTPException, Request
from pydantic import BaseModel, Field

from app import config, db, spotify_auth

log = logging.getLogger("music-downloader")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")

SPOTIFY_URL = re.compile(
    r"^\s*(?:https?://open\.spotify\.com/(?:intl-[A-Za-z-]+/)?(?P<k1>playlist|album|track)/(?P<i1>[A-Za-z0-9]{22})"
    r"|spotify:(?P<k2>playlist|album|track):(?P<i2>[A-Za-z0-9]{22}))(?:[/?#].*)?\s*$"
)
COOKIE_LINE = re.compile(r"^[^\t#\s][^\t]*\t(TRUE|FALSE)\t[^\t]*\t(TRUE|FALSE)\t\d+\t[^\t]*\t", re.MULTILINE)

app = FastAPI(title="Music Downloader worker", docs_url=None, redoc_url=None, openapi_url=None)


def require_token(authorization: str = Header(default="")) -> None:
    if not config.TOKEN:
        raise HTTPException(503, "The worker has no MD_WORKER_TOKEN set.")
    expected = f"Bearer {config.TOKEN}"
    if not hmac.compare_digest(authorization.encode(), expected.encode()):
        raise HTTPException(401, "Bad worker token.")


auth = [Depends(require_token)]


# ------------------------------------------------------------------ scheduler


class Scheduler(threading.Thread):
    """Starts queued jobs one at a time, each in its own runner process, and watches it."""

    def __init__(self) -> None:
        super().__init__(daemon=True, name="scheduler")
        self.proc: Optional[subprocess.Popen] = None
        self.job_id: Optional[int] = None
        self.wake = threading.Event()

    def run(self) -> None:
        while True:
            try:
                self.tick()
            except Exception:  # pylint: disable=broad-except
                log.exception("Scheduler tick failed")
            self.wake.wait(2)
            self.wake.clear()

    def tick(self) -> None:
        with db.session() as conn:
            if self.proc is not None:
                code = self.proc.poll()
                job = db.get_job(conn, self.job_id) if self.job_id else None
                if code is None:
                    if (
                        job is not None
                        and job["status"] == "cancelling"
                        and job["cancel_requested_at"]
                        and db.now() - job["cancel_requested_at"] > config.CANCEL_GRACE_SECONDS
                    ):
                        log.info("Job %s did not stop in time; terminating it", self.job_id)
                        os.killpg(self.proc.pid, signal.SIGTERM)
                    return

                log.info("Job %s runner exited with code %s", self.job_id, code)
                if job is not None and job["status"] in db.ACTIVE_STATUSES:
                    cancelled = job["status"] == "cancelling"
                    db.update_job(
                        conn,
                        job["id"],
                        status="cancelled" if cancelled else "failed",
                        error=None if cancelled else f"The download process stopped unexpectedly (exit code {code}).",
                        finished_at=db.now(),
                    )
                    conn.execute(
                        "UPDATE tracks SET status = ? WHERE job_id = ? AND status IN ('pending', 'downloading')",
                        ("cancelled" if cancelled else "pending", job["id"]),
                    )
                if job is not None:
                    remove_partial_files(job["dest_dir"])
                self.proc = None
                self.job_id = None

            row = conn.execute("SELECT id FROM jobs WHERE status = 'queued' ORDER BY id LIMIT 1").fetchone()
            if row is not None:
                self.launch(row["id"])

    def launch(self, job_id: int) -> None:
        workdir = config.JOBS_DIR / str(job_id)
        workdir.mkdir(parents=True, exist_ok=True)
        with open(workdir / "runner.log", "ab") as logfile:
            self.proc = subprocess.Popen(  # pylint: disable=consider-using-with
                ["nice", "-n", str(config.RUNNER_NICE), sys.executable, "-m", "app.runner", str(job_id)],
                stdin=subprocess.DEVNULL,
                stdout=logfile,
                stderr=subprocess.STDOUT,
                cwd=str(Path(__file__).resolve().parent.parent),
                start_new_session=True,
            )
        self.job_id = job_id
        log.info("Started job %s (pid %s)", job_id, self.proc.pid)


scheduler = Scheduler()
VERSIONS: Dict[str, str] = {}


def remove_partial_files(dest_dir: str) -> None:
    try:
        for part in Path(dest_dir).glob("*.mdpart"):
            part.unlink(missing_ok=True)
    except OSError:
        pass


def _version(cmd: List[str]) -> str:
    try:
        out = subprocess.run(cmd, capture_output=True, text=True, timeout=10, check=False).stdout
        return out.splitlines()[0].strip() if out else "?"
    except (OSError, subprocess.SubprocessError):
        return "missing"


@app.on_event("startup")
def startup() -> None:
    db.init()
    with db.session() as conn:
        # A restart interrupted these: resume them (finished tracks are skipped because the files exist).
        conn.execute("UPDATE jobs SET status = 'queued' WHERE status IN ('resolving', 'downloading')")
        conn.execute("UPDATE jobs SET status = 'cancelled', finished_at = ? WHERE status = 'cancelling'", (db.now(),))
        conn.execute("UPDATE tracks SET status = 'pending' WHERE status = 'downloading'")
        conn.execute("DELETE FROM oauth_states WHERE created_at < ?", (db.now() - spotify_auth.STATE_TTL,))

    import spotdl  # pylint: disable=import-outside-toplevel
    import yt_dlp.version  # pylint: disable=import-outside-toplevel

    VERSIONS.update(
        {
            "spotdl": spotdl.__version__,
            "yt_dlp": yt_dlp.version.__version__,
            "deno": _version(["deno", "--version"]).replace("deno ", "").split(" ")[0],
            "ffmpeg": _version(["ffmpeg", "-version"]).replace("ffmpeg version ", "").split(" ")[0],
        }
    )
    scheduler.start()


# ------------------------------------------------------------------ health + settings


@app.get("/health")
def health() -> Dict[str, Any]:
    return {"ok": True, "versions": VERSIONS}


def public_settings(conn) -> Dict[str, Any]:
    s = db.get_settings(conn)
    return {
        "client_id": s["client_id"],
        "has_client_secret": bool(s["client_secret"]),
        "spotify_app_ready": bool(s["client_id"] and s["client_secret"]),
        "has_cookies": config.COOKIE_FILE.exists(),
        "proxy": s["proxy"],
        "audio_providers": [p for p in s["audio_providers"].split(",") if p],
        "default_format": s["default_format"],
        "default_bitrate": s["default_bitrate"],
        "choices": {
            "formats": list(config.FORMATS),
            "bitrates": list(config.BITRATES),
            "audio_providers": list(config.AUDIO_PROVIDERS),
        },
    }


class SettingsIn(BaseModel):
    client_id: Optional[str] = None
    client_secret: Optional[str] = None  # "" clears it; None keeps it
    proxy: Optional[str] = None
    audio_providers: Optional[List[str]] = None
    default_format: Optional[str] = None
    default_bitrate: Optional[str] = None


@app.get("/settings", dependencies=auth)
def get_settings() -> Dict[str, Any]:
    with db.session() as conn:
        return public_settings(conn)


@app.put("/settings", dependencies=auth)
def put_settings(body: SettingsIn) -> Dict[str, Any]:
    with db.session() as conn:
        if body.client_id is not None:
            if body.client_id and not re.fullmatch(r"[0-9a-f]{32}", body.client_id.strip()):
                raise HTTPException(422, "A Spotify Client ID is 32 characters (0-9, a-f).")
            db.set_setting(conn, "client_id", body.client_id.strip())
        if body.client_secret is not None:
            if body.client_secret and not re.fullmatch(r"[0-9a-f]{32}", body.client_secret.strip()):
                raise HTTPException(422, "A Spotify Client Secret is 32 characters (0-9, a-f).")
            db.set_setting(conn, "client_secret", body.client_secret.strip())
        if body.proxy is not None:
            proxy = body.proxy.strip()
            if proxy and not re.match(r"^(https?|socks5h?)://", proxy):
                raise HTTPException(422, "The proxy must start with http://, https://, socks5:// or socks5h://.")
            db.set_setting(conn, "proxy", proxy)
        if body.audio_providers is not None:
            providers = [p for p in body.audio_providers if p in config.AUDIO_PROVIDERS]
            if not providers:
                raise HTTPException(422, "Choose at least one audio source.")
            db.set_setting(conn, "audio_providers", ",".join(dict.fromkeys(providers)))
        if body.default_format is not None:
            if body.default_format not in config.FORMATS:
                raise HTTPException(422, "Unknown format.")
            db.set_setting(conn, "default_format", body.default_format)
        if body.default_bitrate is not None:
            if body.default_bitrate not in config.BITRATES:
                raise HTTPException(422, "Unknown bitrate.")
            db.set_setting(conn, "default_bitrate", body.default_bitrate)
        return public_settings(conn)


@app.put("/settings/cookies", dependencies=auth)
async def put_cookies(request: Request) -> Dict[str, Any]:
    text = (await request.body()).decode("utf-8", errors="replace")
    if len(text) > 1_000_000:
        raise HTTPException(413, "That cookie file is too large.")
    if not COOKIE_LINE.search(text) or "youtube.com" not in text:
        raise HTTPException(
            422,
            "That doesn't look like a Netscape-format cookies.txt containing youtube.com cookies.",
        )
    tmp = config.COOKIE_FILE.with_suffix(".tmp")
    tmp.write_text(text if text.startswith("# ") else "# Netscape HTTP Cookie File\n" + text)
    tmp.chmod(0o600)
    os.replace(tmp, config.COOKIE_FILE)
    with db.session() as conn:
        return public_settings(conn)


@app.delete("/settings/cookies", dependencies=auth)
def delete_cookies() -> Dict[str, Any]:
    config.COOKIE_FILE.unlink(missing_ok=True)
    with db.session() as conn:
        return public_settings(conn)


# ------------------------------------------------------------------ Spotify login


def account_view(row) -> Dict[str, Any]:
    if row is None:
        return {"connected": False}
    return {
        "connected": True,
        "spotify_id": row["spotify_id"],
        "display_name": row["display_name"] or row["spotify_id"],
        "product": row["product"],
        "connected_at": row["connected_at"],
    }


@app.get("/accounts/{user_id}", dependencies=auth)
def get_account(user_id: int) -> Dict[str, Any]:
    with db.session() as conn:
        return account_view(db.get_account(conn, user_id))


@app.delete("/accounts/{user_id}", dependencies=auth)
def delete_account(user_id: int) -> Dict[str, Any]:
    with db.session() as conn:
        conn.execute("DELETE FROM accounts WHERE user_id = ?", (user_id,))
    return {"connected": False}


class AuthStartIn(BaseModel):
    user_id: int
    redirect_uri: str = Field(min_length=10, max_length=500)
    return_to: Optional[str] = Field(default=None, max_length=500)


@app.post("/auth/start", dependencies=auth)
def auth_start(body: AuthStartIn) -> Dict[str, Any]:
    with db.session() as conn:
        s = db.get_settings(conn)
        if not (s["client_id"] and s["client_secret"]):
            raise HTTPException(409, "An administrator must enter the Spotify app's Client ID and Secret first.")
        conn.execute("DELETE FROM oauth_states WHERE created_at < ?", (db.now() - spotify_auth.STATE_TTL,))
        state = spotify_auth.new_state()
        conn.execute(
            "INSERT INTO oauth_states (state, user_id, redirect_uri, return_to, created_at) VALUES (?, ?, ?, ?, ?)",
            (state, body.user_id, body.redirect_uri, body.return_to, db.now()),
        )
        return {"authorize_url": spotify_auth.authorize_url(s["client_id"], body.redirect_uri, state), "state": state}


class AuthFinishIn(BaseModel):
    user_id: int
    code: str = Field(min_length=10, max_length=2000)
    state: str = Field(min_length=10, max_length=200)


@app.post("/auth/finish", dependencies=auth)
def auth_finish(body: AuthFinishIn) -> Dict[str, Any]:
    with db.session() as conn:
        row = conn.execute("SELECT * FROM oauth_states WHERE state = ?", (body.state,)).fetchone()
        if row is None or row["user_id"] != body.user_id or db.now() - row["created_at"] > spotify_auth.STATE_TTL:
            raise HTTPException(400, "This Spotify login link has expired or belongs to someone else. Start again.")
        conn.execute("DELETE FROM oauth_states WHERE state = ?", (body.state,))
        s = db.get_settings(conn)

    try:
        token = spotify_auth.exchange_code(s["client_id"], s["client_secret"], body.code, row["redirect_uri"])
        profile = spotify_auth.fetch_profile(token["access_token"])
    except spotify_auth.AuthError as exc:
        raise HTTPException(400, str(exc)) from exc

    with db.session() as conn:
        conn.execute(
            """
            INSERT INTO accounts (user_id, spotify_id, display_name, product, token_json, connected_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (user_id) DO UPDATE SET spotify_id = excluded.spotify_id,
                display_name = excluded.display_name, product = excluded.product,
                token_json = excluded.token_json, connected_at = excluded.connected_at, updated_at = excluded.updated_at
            """,
            (
                body.user_id,
                profile.get("id", "?"),
                profile.get("display_name"),
                profile.get("product"),
                json.dumps(token),
                db.now(),
                db.now(),
            ),
        )
        result = account_view(db.get_account(conn, body.user_id))
    result["return_to"] = row["return_to"]
    return result


# ------------------------------------------------------------------ jobs


def parse_spotify_url(url: str) -> Optional[Dict[str, str]]:
    m = SPOTIFY_URL.match(url or "")
    if not m:
        return None
    kind = m.group("k1") or m.group("k2")
    item_id = m.group("i1") or m.group("i2")
    return {"kind": kind, "url": f"https://open.spotify.com/{kind}/{item_id}"}


def job_view(row, queue_position: Optional[int] = None) -> Dict[str, Any]:
    data = {k: row[k] for k in row.keys() if k not in ("dest_dir", "cancel_requested_at")}
    data["use_login"] = bool(row["use_login"])
    data["finished"] = row["status"] in db.FINISHED_STATUSES
    data["queue_position"] = queue_position
    return data


def queue_positions(conn) -> Dict[int, int]:
    rows = conn.execute("SELECT id FROM jobs WHERE status = 'queued' ORDER BY id").fetchall()
    running = conn.execute(
        "SELECT COUNT(*) FROM jobs WHERE status IN ('resolving', 'downloading', 'cancelling')"
    ).fetchone()[0]
    return {r["id"]: i + 1 + running for i, r in enumerate(rows)}


def load_job(conn, job_id: int, station_id: int):
    row = db.get_job(conn, job_id)
    if row is None or row["station_id"] != station_id:
        raise HTTPException(404, "Download not found.")
    return row


class JobIn(BaseModel):
    station_id: int
    url: str = Field(min_length=10, max_length=500)
    dest_dir: str = Field(min_length=2, max_length=1000)
    dest_label: str = Field(min_length=1, max_length=500)
    format: str
    bitrate: str
    user_id: int
    user_name: Optional[str] = Field(default=None, max_length=200)
    use_login: bool = True


@app.post("/jobs", dependencies=auth, status_code=201)
def create_job(body: JobIn) -> Dict[str, Any]:
    parsed = parse_spotify_url(body.url)
    if parsed is None:
        raise HTTPException(
            422,
            "Paste a Spotify playlist, album or track link, like https://open.spotify.com/playlist/... "
            "(short spotify.link links aren't supported; open them in a browser first).",
        )
    if body.format not in config.FORMATS or body.bitrate not in config.BITRATES:
        raise HTTPException(422, "Unknown format or bitrate.")

    root = config.STATIONS_ROOT.resolve()
    dest = Path(body.dest_dir).resolve()
    if not dest.is_relative_to(root) or dest == root:
        raise HTTPException(422, "The destination must be inside a station's media folder.")

    with db.session() as conn:
        dup = conn.execute(
            "SELECT id FROM jobs WHERE station_id = ? AND url = ? AND dest_dir = ? AND status IN "
            "('queued', 'resolving', 'downloading', 'cancelling')",
            (body.station_id, parsed["url"], str(dest)),
        ).fetchone()
        if dup is not None:
            raise HTTPException(409, f"This link is already being downloaded to that folder (download #{dup['id']}).")

        cur = conn.execute(
            """
            INSERT INTO jobs (station_id, url, kind, dest_dir, dest_label, format, bitrate, user_id, user_name,
                              use_login, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued', ?)
            """,
            (
                body.station_id,
                parsed["url"],
                parsed["kind"],
                str(dest),
                body.dest_label,
                body.format,
                body.bitrate,
                body.user_id,
                body.user_name,
                int(body.use_login),
                db.now(),
            ),
        )
        job = db.get_job(conn, cur.lastrowid)
        result = job_view(job, queue_positions(conn).get(job["id"]))
    scheduler.wake.set()
    return result


@app.get("/jobs", dependencies=auth)
def list_jobs(station_id: int, limit: int = 50) -> Dict[str, Any]:
    with db.session() as conn:
        rows = conn.execute(
            "SELECT * FROM jobs WHERE station_id = ? ORDER BY id DESC LIMIT ?",
            (station_id, max(1, min(limit, 200))),
        ).fetchall()
        positions = queue_positions(conn)
        return {"jobs": [job_view(r, positions.get(r["id"])) for r in rows]}


@app.get("/jobs/{job_id}", dependencies=auth)
def get_job(job_id: int, station_id: int) -> Dict[str, Any]:
    with db.session() as conn:
        row = load_job(conn, job_id, station_id)
        tracks = conn.execute(
            "SELECT id, position, title, artists, spotify_url, status, file, error FROM tracks "
            "WHERE job_id = ? ORDER BY position",
            (job_id,),
        ).fetchall()
        data = job_view(row, queue_positions(conn).get(job_id))
        data["tracks"] = [dict(t) for t in tracks]
        return data


@app.get("/jobs/{job_id}/log", dependencies=auth)
def get_job_log(job_id: int, station_id: int) -> Dict[str, Any]:
    with db.session() as conn:
        load_job(conn, job_id, station_id)
    path = config.JOBS_DIR / str(job_id) / "runner.log"
    lines = path.read_text(errors="replace").splitlines()[-300:] if path.exists() else []
    return {"lines": lines}


@app.post("/jobs/{job_id}/cancel", dependencies=auth)
def cancel_job(job_id: int, station_id: int) -> Dict[str, Any]:
    with db.session() as conn:
        row = load_job(conn, job_id, station_id)
        if row["status"] == "queued":
            db.update_job(conn, job_id, status="cancelled", finished_at=db.now())
            conn.execute("UPDATE tracks SET status = 'cancelled' WHERE job_id = ? AND status = 'pending'", (job_id,))
        elif row["status"] in ("resolving", "downloading"):
            db.update_job(conn, job_id, status="cancelling", cancel_requested_at=db.now())
        else:
            raise HTTPException(409, "This download has already finished.")
        return job_view(db.get_job(conn, job_id))


@app.post("/jobs/{job_id}/retry", dependencies=auth)
def retry_job(job_id: int, station_id: int) -> Dict[str, Any]:
    with db.session() as conn:
        row = load_job(conn, job_id, station_id)
        if row["status"] not in db.FINISHED_STATUSES:
            raise HTTPException(409, "This download is still running.")
        conn.execute(
            "UPDATE tracks SET status = 'pending', error = NULL WHERE job_id = ? AND status IN ('failed', 'cancelled')",
            (job_id,),
        )
        db.update_job(conn, job_id, status="queued", error=None, finished_at=None, cancel_requested_at=None)
        db.refresh_counts(conn, job_id)
        result = job_view(db.get_job(conn, job_id), queue_positions(conn).get(job_id))
    scheduler.wake.set()
    return result


@app.delete("/jobs/{job_id}", dependencies=auth)
def delete_job(job_id: int, station_id: int) -> Dict[str, Any]:
    with db.session() as conn:
        row = load_job(conn, job_id, station_id)
        if row["status"] not in db.FINISHED_STATUSES:
            raise HTTPException(409, "Cancel this download before removing it.")
        conn.execute("DELETE FROM jobs WHERE id = ?", (job_id,))
    workdir = config.JOBS_DIR / str(job_id)
    for f in (workdir / "runner.log",):
        f.unlink(missing_ok=True)
    try:
        workdir.rmdir()
    except OSError:
        pass
    return {"deleted": True}


@app.exception_handler(Exception)
async def unhandled(_request, exc):  # pragma: no cover - last resort
    from fastapi.responses import JSONResponse  # pylint: disable=import-outside-toplevel

    log.exception("Unhandled error: %s", exc)
    return JSONResponse({"detail": "The worker hit an unexpected error; see its logs."}, status_code=500)

