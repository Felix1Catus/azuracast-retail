"""YouTube account: keeps the cookie file fresh from a browser that an administrator signed in once.

The music-downloader-browser container (app/browser_api.py) runs Chromium with the account's saved profile.
The administrator signs in through the remote view in Settings (no password is stored). After that this
module, used by the API process, its keeper thread and the job runners, asks the browser to refresh: open
youtube.com with the newest cookies and export what YouTube sends back. When YouTube has signed the account
out, the state becomes "expired" and an alert asks an administrator to sign in again.

The state lives in the settings table (yt_state, JSON). A file lock keeps the processes from driving the
browser at the same time. States: disconnected (no account), connected, expired, error.
"""

import fcntl
import json
import logging
import threading
import time
import urllib.error
import urllib.request
from contextlib import contextmanager
from typing import Any, Dict, Iterator, Optional

from app import config, cookies, db

log = logging.getLogger("music-downloader.youtube")

LOCK_FILE = config.DATA_DIR / "youtube-session.lock"
ALERT_STATUSES = {"expired", "error"}


class Busy(RuntimeError):
    pass


class BrowserError(RuntimeError):
    pass


class BadInput(ValueError):
    pass


# ------------------------------------------------------------------ state


def get_state(conn) -> Dict[str, Any]:
    try:
        state = json.loads(db.get_settings(conn)["yt_state"] or "{}")
    except ValueError:
        state = {}
    return state if isinstance(state, dict) else {}


def set_state(**changes: Any) -> Dict[str, Any]:
    with db.session() as conn:
        state = get_state(conn)
        old = state.get("status")
        state.update(changes)
        new = state.get("status")
        if new != old:
            if new in ALERT_STATUSES and state.get("account"):
                state["alert"] = {"status": new, "message": state.get("message", ""), "at": db.now(), "sent": False}
            elif new in ("connected", "disconnected"):
                state["alert"] = None
        state["updated_at"] = db.now()
        db.set_setting(conn, "yt_state", json.dumps(state))
        return state


def public_view_now() -> Dict[str, Any]:
    with db.session() as conn:
        return public_view(conn)


def public_view(conn) -> Dict[str, Any]:
    state = get_state(conn)
    return {
        "connected_once": bool(state.get("account")),
        "status": state.get("status") or "disconnected",
        "message": state.get("message", ""),
        "last_refresh": state.get("last_refresh"),
        "connected_at": state.get("connected_at"),
        "busy": _running.is_set(),
        "cookie_source": db.get_settings(conn)["cookie_source"],
        "refresh_hours": round(config.YT_REFRESH_SECONDS / 3600, 1),
    }


# ------------------------------------------------------------------ browser container


def browser(method: str, path: str, body: Optional[Dict[str, Any]] = None, timeout: float = 260) -> Dict[str, Any]:
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(
        config.BROWSER_URL.rstrip("/") + path,
        data=data,
        method=method,
        headers={"Authorization": f"Bearer {config.TOKEN}", "Content-Type": "application/json"},
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as res:
            return json.loads(res.read() or b"{}")
    except urllib.error.HTTPError as exc:
        try:
            detail = json.loads(exc.read()).get("detail", "")
        except ValueError:
            detail = ""
        if exc.code == 409:
            raise Busy(detail or "The browser is busy.") from exc
        if exc.code == 422:
            raise BadInput(detail or "The browser refused that input.") from exc
        raise BrowserError(detail or f"The browser answered HTTP {exc.code}.") from exc
    except (OSError, ValueError) as exc:
        raise BrowserError(
            "The YouTube account browser isn't reachable. Check that the music-downloader-browser container is running."
        ) from exc


@contextmanager
def _lock(blocking: bool = True) -> Iterator[None]:
    with open(LOCK_FILE, "w", encoding="utf-8") as fd:
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | (0 if blocking else fcntl.LOCK_NB))
        except BlockingIOError as exc:
            raise Busy("The YouTube account is already being refreshed.") from exc
        yield


def _store_cookies(text: str) -> None:
    cookies.save(text)
    with db.session() as conn:
        db.set_setting(conn, "cookie_source", "account")


# ------------------------------------------------------------------ refresh


def refresh(*, max_age: Optional[int] = None, blocking: bool = True) -> Dict[str, Any]:
    """Refresh the cookies from the browser. max_age: skip when the last attempt is younger (before a job)."""
    with _lock(blocking):
        with db.session() as conn:
            s = db.get_settings(conn)
            state = get_state(conn)
        if not state.get("account"):
            return state
        if max_age is not None and db.now() - int(state.get("last_check") or 0) < max_age:
            return state

        cookie_text = None
        if s["cookie_source"] == "account" and config.COOKIE_FILE.exists():
            # yt-dlp may have stored newer cookies than the browser profile has; continue from those.
            cookie_text = config.COOKIE_FILE.read_text(errors="replace")

        try:
            result = browser("POST", "/refresh", {"cookies": cookie_text})
        except Busy:
            return state  # the remote view is open
        except BrowserError as exc:
            return set_state(status="error", message=str(exc), last_check=db.now())

        if not result.get("logged_in"):
            log.info("YouTube signed the account out")
            return set_state(status="expired", last_check=db.now(),
                             message="YouTube signed the account out. Sign in again under Music Downloader "
                                     "settings > YouTube account.")
        try:
            _store_cookies(result.get("cookies", ""))
        except cookies.CookieError as exc:
            return set_state(status="error", message=f"The browser's cookies were refused: {exc}", last_check=db.now())
        log.info("YouTube cookies refreshed")
        return set_state(status="connected", message="Signed in; cookies refreshed.",
                         last_refresh=db.now(), last_check=db.now())


_running = threading.Event()


def refresh_in_background() -> None:
    """"Refresh now" in Settings: the page polls the state while it runs (about 20-40 s)."""
    if _running.is_set():
        raise Busy("The YouTube account is already being refreshed.")
    _running.set()

    def run():
        try:
            refresh()
        except Exception:  # pylint: disable=broad-except
            log.exception("YouTube refresh failed")
            set_state(status="error", message="The refresh failed unexpectedly; see the worker's logs.")
        finally:
            _running.clear()

    threading.Thread(target=run, daemon=True, name="youtube-refresh").start()


# ------------------------------------------------------------------ sign-in through the remote view


def open_view() -> Dict[str, Any]:
    return browser("POST", "/view/open")


def finish_view() -> Dict[str, Any]:
    """The administrator pressed Done: keep the session if YouTube shows the account as signed in."""
    with _lock():
        result = browser("POST", "/view/finish")
        if not result.get("logged_in"):
            view = public_view_now()
            view["view_open"] = bool(result.get("open"))
            view["message"] = "Not signed in to YouTube yet: finish signing in to the Google account, then press Done."
            return view
        try:
            _store_cookies(result.get("cookies", ""))
        except cookies.CookieError as exc:
            set_state(status="error", message=f"Signed in, but the cookies were refused: {exc}")
            return public_view_now()
        with db.session() as conn:
            proxy = db.get_settings(conn)["proxy"]
        test = cookies.test(proxy)
        log.info("YouTube account connected (test ok: %s)", test["ok"])
        set_state(account=True, status="connected", message="Signed in to YouTube. " + test["message"],
                  connected_at=db.now(), last_refresh=db.now(), last_check=db.now())
        return public_view_now()


def disconnect() -> Dict[str, Any]:
    try:
        browser("DELETE", "/profile")
    except (Busy, BrowserError):
        pass
    with db.session() as conn:
        if db.get_settings(conn)["cookie_source"] == "account":
            config.COOKIE_FILE.unlink(missing_ok=True)
            db.set_setting(conn, "cookie_source", "manual")
    return set_state(account=False, status="disconnected", message="", last_refresh=None, last_check=None,
                     connected_at=None)


# ------------------------------------------------------------------ alerts (dashboard + email, see the PHP plugin)


def pending_alert() -> Optional[Dict[str, Any]]:
    with db.session() as conn:
        alert = get_state(conn).get("alert")
    return alert if isinstance(alert, dict) and not alert.get("sent") else None


def ack_alert(at: int) -> None:
    with db.session() as conn:
        state = get_state(conn)
        alert = state.get("alert")
        if isinstance(alert, dict) and alert.get("at") == at:
            alert["sent"] = True
            db.set_setting(conn, "yt_state", json.dumps(state))


# ------------------------------------------------------------------ keeper


class Keeper(threading.Thread):
    """Refreshes the cookies every MD_YT_REFRESH_HOURS while no job is running."""

    def __init__(self) -> None:
        super().__init__(daemon=True, name="youtube-keeper")

    def run(self) -> None:
        time.sleep(60)
        while True:
            try:
                with db.session() as conn:
                    busy = conn.execute(
                        "SELECT 1 FROM jobs WHERE status IN ('resolving', 'downloading', 'cancelling') LIMIT 1"
                    ).fetchone()
                if not busy and not _running.is_set():
                    refresh(max_age=config.YT_REFRESH_SECONDS, blocking=False)
            except Busy:
                pass
            except Exception:  # pylint: disable=broad-except
                log.exception("YouTube keeper failed")
            time.sleep(600)
