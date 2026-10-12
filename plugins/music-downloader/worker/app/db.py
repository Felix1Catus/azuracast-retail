"""SQLite storage for settings, Spotify logins and jobs. Shared by the API process and job runners (WAL mode)."""

import json
import sqlite3
import time
from contextlib import contextmanager
from typing import Any, Dict, Iterator, Optional

from app import config

SCHEMA = """
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS accounts (
    user_id INTEGER PRIMARY KEY,          -- Acacia Radio user id
    spotify_id TEXT NOT NULL,
    display_name TEXT,
    product TEXT,
    token_json TEXT NOT NULL,             -- spotipy token cache format (access/refresh token, expires_at, scope)
    connected_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS oauth_states (
    state TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    redirect_uri TEXT NOT NULL,
    return_to TEXT,
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id INTEGER NOT NULL,
    url TEXT NOT NULL,
    kind TEXT NOT NULL,                   -- playlist | album | track
    title TEXT,
    dest_dir TEXT NOT NULL,               -- absolute folder inside the station's media
    dest_label TEXT NOT NULL,             -- the same folder as shown in Acacia Radio ("/" = media root)
    format TEXT NOT NULL,
    bitrate TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    user_name TEXT,
    use_login INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL,                 -- queued | resolving | downloading | cancelling | done | failed | cancelled
    source TEXT,                          -- spotify-login | public
    note TEXT,
    error TEXT,
    total INTEGER NOT NULL DEFAULT 0,
    done INTEGER NOT NULL DEFAULT 0,
    skipped INTEGER NOT NULL DEFAULT 0,
    failed INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    started_at INTEGER,
    finished_at INTEGER,
    cancel_requested_at INTEGER
);
CREATE INDEX IF NOT EXISTS jobs_station ON jobs (station_id, id);
CREATE TABLE IF NOT EXISTS tracks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NOT NULL REFERENCES jobs (id) ON DELETE CASCADE,
    position INTEGER NOT NULL,
    title TEXT NOT NULL,
    artists TEXT,
    spotify_url TEXT,
    status TEXT NOT NULL,                 -- pending | downloading | done | skipped | failed | cancelled
    file TEXT,                            -- file name in the destination folder
    error TEXT,
    song_json TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS tracks_job ON tracks (job_id, position);
"""

ACTIVE_STATUSES = ("queued", "resolving", "downloading", "cancelling")
FINISHED_STATUSES = ("done", "failed", "cancelled")


def connect() -> sqlite3.Connection:
    conn = sqlite3.connect(config.DB_PATH, timeout=30, isolation_level=None)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA foreign_keys=ON")
    conn.execute("PRAGMA busy_timeout=30000")
    return conn


@contextmanager
def session() -> Iterator[sqlite3.Connection]:
    conn = connect()
    try:
        yield conn
    finally:
        conn.close()


def init() -> None:
    config.DATA_DIR.mkdir(parents=True, exist_ok=True)
    config.JOBS_DIR.mkdir(parents=True, exist_ok=True)
    with session() as conn:
        conn.executescript(SCHEMA)
    config.DB_PATH.chmod(0o600)


def now() -> int:
    return int(time.time())


def get_settings(conn: sqlite3.Connection) -> Dict[str, str]:
    values = dict(config.DEFAULT_SETTINGS)
    for row in conn.execute("SELECT key, value FROM settings"):
        values[row["key"]] = row["value"]
    return values


def set_setting(conn: sqlite3.Connection, key: str, value: str) -> None:
    conn.execute(
        "INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value",
        (key, value),
    )


def get_account(conn: sqlite3.Connection, user_id: int) -> Optional[sqlite3.Row]:
    return conn.execute("SELECT * FROM accounts WHERE user_id = ?", (user_id,)).fetchone()


def save_account_token(conn: sqlite3.Connection, user_id: int, token: Dict[str, Any]) -> None:
    conn.execute(
        "UPDATE accounts SET token_json = ?, updated_at = ? WHERE user_id = ?",
        (json.dumps(token), now(), user_id),
    )


def get_job(conn: sqlite3.Connection, job_id: int) -> Optional[sqlite3.Row]:
    return conn.execute("SELECT * FROM jobs WHERE id = ?", (job_id,)).fetchone()


def update_job(conn: sqlite3.Connection, job_id: int, **fields: Any) -> None:
    if not fields:
        return
    cols = ", ".join(f"{k} = ?" for k in fields)
    conn.execute(f"UPDATE jobs SET {cols} WHERE id = ?", (*fields.values(), job_id))


def refresh_counts(conn: sqlite3.Connection, job_id: int) -> None:
    conn.execute(
        """
        UPDATE jobs SET
            total = (SELECT COUNT(*) FROM tracks WHERE job_id = :id),
            done = (SELECT COUNT(*) FROM tracks WHERE job_id = :id AND status = 'done'),
            skipped = (SELECT COUNT(*) FROM tracks WHERE job_id = :id AND status = 'skipped'),
            failed = (SELECT COUNT(*) FROM tracks WHERE job_id = :id AND status = 'failed')
        WHERE id = :id
        """,
        {"id": job_id},
    )
