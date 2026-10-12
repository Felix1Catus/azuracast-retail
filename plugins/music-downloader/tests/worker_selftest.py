"""Offline self-test of the worker's job runner (no YouTube needed).

Resolves a real Spotify track list (public access, needs internet), but replaces the YouTube download with a
generated 3-second tone, then checks the files land in the destination folder, a second run skips them, and
the counters add up. Run it inside the worker image:

  docker run --rm -e MD_DATA_DIR=/tmp/md -e MD_STATIONS_ROOT=/tmp/stations \
    -v "$PWD/plugins/music-downloader/tests:/tests:ro" acacia-music-downloader:dev python /tests/worker_selftest.py
"""

import json
import os
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, "/app")

from app import config, db, runner  # noqa: E402

URL = os.environ.get("SELFTEST_URL", "https://open.spotify.com/album/4LH4d3cOWNNsVw41Gqt2kv")  # Dark Side of the Moon


async def fake_download(self, song):
    out = Path(self.settings["output"].replace("{artists} - {title}.{output-ext}", f"{song.artist} - {song.name}.{self.settings['format']}"))
    out.parent.mkdir(parents=True, exist_ok=True)
    subprocess.run(
        ["ffmpeg", "-loglevel", "error", "-y", "-f", "lavfi", "-i", "sine=frequency=440:duration=3", str(out)],
        check=True,
    )
    return song, out


def run_job(conn, job_id):
    rc = runner.main(job_id)
    assert rc == 0, rc
    return db.get_job(db.connect(), job_id)


def main():
    from spotdl.download.downloader import Downloader

    Downloader.async_search_and_download = fake_download

    db.init()
    dest = config.STATIONS_ROOT / "selftest" / "media" / "Albums"
    conn = db.connect()
    cur = conn.execute(
        "INSERT INTO jobs (station_id, url, kind, dest_dir, dest_label, format, bitrate, user_id, use_login, status, created_at) "
        "VALUES (1, ?, 'album', ?, '/Albums', 'mp3', 'auto', 1, 0, 'queued', ?)",
        (URL, str(dest), db.now()),
    )
    job_id = cur.lastrowid

    job = run_job(conn, job_id)
    print("run 1:", job["status"], job["title"], "total", job["total"], "done", job["done"], "failed", job["failed"])
    assert job["status"] == "done", dict(job)
    assert job["total"] > 0 and job["done"] == job["total"], dict(job)
    files = sorted(p.name for p in dest.iterdir())
    assert len(files) == job["total"], files
    assert not any(f.endswith(".mdpart") for f in files), files
    assert not (config.JOBS_DIR / str(job_id) / "staging").exists(), "staging not cleaned"
    print("  files:", files[:3], "...")

    # Retry the same list: everything is already there, so every track is skipped.
    conn.execute("UPDATE tracks SET status = 'pending' WHERE job_id = ?", (job_id,))
    db.update_job(conn, job_id, status="queued")
    job = run_job(conn, job_id)
    print("run 2:", job["status"], "skipped", job["skipped"], "done", job["done"])
    assert job["skipped"] == job["total"], dict(job)

    # Cancellation between tracks.
    cur = conn.execute(
        "INSERT INTO jobs (station_id, url, kind, dest_dir, dest_label, format, bitrate, user_id, use_login, status, created_at) "
        "VALUES (1, ?, 'album', ?, '/Other', 'mp3', 'auto', 1, 0, 'queued', ?)",
        (URL, str(dest.parent / "Other"), db.now()),
    )
    job2 = cur.lastrowid
    calls = {"n": 0}

    async def cancel_after_two(self, song):
        calls["n"] += 1
        if calls["n"] == 2:
            db.update_job(db.connect(), job2, status="cancelling")
        return await fake_download(self, song)

    Downloader.async_search_and_download = cancel_after_two
    job = run_job(conn, job2)
    print("run 3:", job["status"], "done", job["done"], "of", job["total"])
    assert job["status"] == "cancelled" and job["done"] == 2, dict(job)
    statuses = {r["status"] for r in conn.execute("SELECT status FROM tracks WHERE job_id = ?", (job2,))}
    assert statuses == {"done", "cancelled"}, statuses

    print(json.dumps({"ok": True}))


if __name__ == "__main__":
    main()
