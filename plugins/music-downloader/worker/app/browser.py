"""Chromium session for the YouTube account (runs in the music-downloader-browser container).

An administrator signs a (throwaway) Google account in once, through the remote view in Settings; no password
is stored. The saved Chromium profile keeps that session, and the worker asks it for fresh youtube.com cookies.
YouTube rotates cookies while a browser has them open, so Chromium only runs while it's needed (a refresh, or
the remote view) and is closed gracefully afterwards so the profile on disk holds the newest cookies.

Chromium is started as a plain process and Playwright attaches over CDP: launching it through Playwright
adds automation switches that Google's sign-in page refuses ("This browser or app may not be secure").
Every Playwright call runs on one thread (Playwright's sync API isn't thread-safe).
"""

import base64
import logging
import os
import queue
import shutil
import signal
import subprocess
import threading
import time
import urllib.request
from pathlib import Path
from typing import Any, Callable, Dict, List, Optional

from app import config

log = logging.getLogger("music-downloader.browser")

PROFILE = config.DATA_DIR / "profile"
CHROMIUM = os.environ.get("MD_CHROMIUM", "/usr/bin/chromium")
CDP_PORT = 9222
WIDTH, HEIGHT = 1280, 800
IDLE_CLOSE_SECONDS = 600  # an abandoned remote view closes after 10 minutes

LOGIN_URL = (
    "https://accounts.google.com/ServiceLogin?service=youtube&uilel=3&passive=true&hl=en"
    "&continue=https%3A%2F%2Fwww.youtube.com%2Fsignin%3Faction_handle_signin%3Dtrue%26app%3Ddesktop"
    "%26hl%3Den%26next%3D%252F"
)
YOUTUBE_URL = "https://www.youtube.com/?hl=en"
LOGIN_COOKIES = {"LOGIN_INFO", "SID", "__Secure-1PSID", "__Secure-3PSID"}
VIEW_KEYS = {"Enter", "Tab", "Backspace", "Escape", "ArrowUp", "ArrowDown", "ArrowLeft", "ArrowRight", "Space"}


class Busy(RuntimeError):
    """The browser is open in the remote view; automatic use has to wait."""


def _is_youtube(domain: str) -> bool:
    return domain.lstrip(".") == "youtube.com" or domain.endswith(".youtube.com")


# ------------------------------------------------------------------ Playwright -> cookies.txt


def to_netscape(cookies: List[Dict[str, Any]]) -> str:
    lines = ["# Netscape HTTP Cookie File", "# Exported by Music Downloader's YouTube account browser", ""]
    for c in cookies:
        if not _is_youtube(c["domain"]):
            continue
        domain = c["domain"]
        prefix = "#HttpOnly_" if c.get("httpOnly") else ""
        expires = int(c["expires"]) if c.get("expires", -1) and c.get("expires", -1) > 0 else 0
        lines.append("\t".join([
            prefix + domain,
            "TRUE" if domain.startswith(".") else "FALSE",
            c.get("path") or "/",
            "TRUE" if c.get("secure") else "FALSE",
            str(expires),
            c["name"],
            c["value"],
        ]))
    return "\n".join(lines) + "\n"


# ------------------------------------------------------------------ the browser


class Browser:
    def __init__(self) -> None:
        self.tasks: "queue.Queue" = queue.Queue()
        self.proc: Optional[subprocess.Popen] = None
        self.pw = None
        self.cdp = None
        self.view_open = False
        self.last_used = time.time()
        threading.Thread(target=self._loop, daemon=True, name="browser").start()

    # Every public method runs its body on the browser thread.
    def call(self, fn: Callable[[], Any], timeout: float = 240) -> Any:
        box: Dict[str, Any] = {}
        done = threading.Event()
        self.tasks.put((fn, box, done))
        if not done.wait(timeout):
            raise TimeoutError("The browser didn't finish in time.")
        if "error" in box:
            raise box["error"]
        return box["result"]

    def _loop(self) -> None:
        from playwright.sync_api import sync_playwright  # pylint: disable=import-outside-toplevel

        self.pw = sync_playwright().start()
        while True:
            try:
                fn, box, done = self.tasks.get(timeout=30)
            except queue.Empty:
                if self.proc is not None and time.time() - self.last_used > IDLE_CLOSE_SECONDS:
                    log.info("Closing the idle browser")
                    self._close()
                continue
            try:
                box["result"] = fn()
            except Exception as exc:  # pylint: disable=broad-except
                log.exception("Browser task failed")
                box["error"] = exc
            finally:
                self.last_used = time.time()
                done.set()

    # ---------------------------------------------------------------- process

    def _running(self) -> bool:
        return self.proc is not None and self.proc.poll() is None and self.cdp is not None and self.cdp.is_connected()

    def _open(self) -> None:
        if self._running():
            return
        self._close()
        PROFILE.mkdir(parents=True, exist_ok=True)
        # A Chromium that was killed leaves these behind, and the next start would refuse the profile.
        for name in ("SingletonLock", "SingletonSocket", "SingletonCookie"):
            try:
                (PROFILE / name).unlink()
            except FileNotFoundError:
                pass
        logfile = open(config.DATA_DIR / "chromium.log", "ab")  # pylint: disable=consider-using-with
        self.proc = subprocess.Popen(  # pylint: disable=consider-using-with
            [
                CHROMIUM,
                f"--user-data-dir={PROFILE}",
                f"--remote-debugging-port={CDP_PORT}",
                "--remote-debugging-address=127.0.0.1",
                "--no-first-run",
                "--no-default-browser-check",
                "--no-sandbox",  # Docker's default seccomp profile doesn't allow Chromium's sandbox
                "--disable-dev-shm-usage",
                "--password-store=basic",
                "--disable-features=Translate,MediaRouter,OptimizationHints",
                f"--window-size={WIDTH},{HEIGHT}",
                "--window-position=0,0",
                "--lang=en-US",
                "about:blank",
            ],
            stdin=subprocess.DEVNULL,
            stdout=logfile,
            stderr=subprocess.STDOUT,
            start_new_session=True,
        )
        logfile.close()
        deadline = time.time() + 40
        while time.time() < deadline:
            if self.proc.poll() is not None:
                raise RuntimeError(f"Chromium exited at start (code {self.proc.returncode}); see chromium.log.")
            try:
                urllib.request.urlopen(f"http://127.0.0.1:{CDP_PORT}/json/version", timeout=2).read()
                break
            except OSError:
                time.sleep(0.5)
        else:
            raise RuntimeError("Chromium didn't start within 40 seconds.")
        self.cdp = self.pw.chromium.connect_over_cdp(f"http://127.0.0.1:{CDP_PORT}")
        log.info("Chromium started (pid %s)", self.proc.pid)

    def _close(self) -> None:
        """Close Chromium gracefully, so it writes the newest cookies to the profile."""
        self.view_open = False
        if self.cdp is not None:
            try:
                self.cdp.new_browser_cdp_session().send("Browser.close")
            except Exception:  # pylint: disable=broad-except
                pass
            try:
                self.cdp.close()
            except Exception:  # pylint: disable=broad-except
                pass
            self.cdp = None
        if self.proc is not None:
            try:
                self.proc.wait(timeout=20)
            except subprocess.TimeoutExpired:
                os.killpg(self.proc.pid, signal.SIGTERM)
                try:
                    self.proc.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    os.killpg(self.proc.pid, signal.SIGKILL)
            self.proc = None

    def _context(self):
        return self.cdp.contexts[0]

    def _page(self):
        pages = self._context().pages
        return pages[-1] if pages else self._context().new_page()

    # ---------------------------------------------------------------- YouTube

    def _dismiss_consent(self, page) -> None:
        """EU visitors get a cookie-consent page first."""
        if "consent." not in page.url:
            return
        for label in ("Reject all", "Accept all"):
            button = page.get_by_role("button", name=label)
            if button.count():
                button.first.click()
                page.wait_for_load_state("domcontentloaded")
                return

    def _youtube_state(self) -> Dict[str, Any]:
        """Open youtube.com, give it time to rotate its cookies, and report the login and the cookies."""
        page = self._page()
        page.goto(YOUTUBE_URL, wait_until="domcontentloaded", timeout=60000)
        self._dismiss_consent(page)
        try:
            page.wait_for_selector("ytd-masthead", timeout=30000)
        except Exception:  # pylint: disable=broad-except
            pass
        page.wait_for_timeout(6000)
        sign_in_button = page.locator('ytd-masthead a[href*="accounts.google.com/ServiceLogin"]').count()
        # Leave YouTube before exporting: its pages rotate the session cookies, and a rotation after the export
        # would leave the worker with stale cookies that Google treats as a signed-out (or stolen) session.
        page.goto("about:blank")
        page.wait_for_timeout(1000)
        cookies = self._context().cookies()
        names = {c["name"] for c in cookies if _is_youtube(c["domain"])}
        logged_in = bool(names & LOGIN_COOKIES) and sign_in_button == 0
        return {"logged_in": logged_in, "cookies": to_netscape(cookies)}

    def refresh(self) -> Dict[str, Any]:
        """The profile is the only source of the session: cookies are exported from it, never imported into it
        (loading an older copy over a rotated session signs it out)."""
        def run():
            if self.view_open:
                raise Busy("The browser is open in the remote view.")
            self._open()
            try:
                return self._youtube_state()
            finally:
                self._close()

        return self.call(run)

    # ---------------------------------------------------------------- remote view

    def view_start(self) -> Dict[str, Any]:
        def run():
            self._open()
            self.view_open = True
            page = self._page()
            if page.url in ("about:blank", ""):
                page.goto(LOGIN_URL, wait_until="domcontentloaded", timeout=60000)
            return {"open": True}

        return self.call(run)

    def view_frame(self) -> Dict[str, Any]:
        def run():
            if not (self.view_open and self._running()):
                self.view_open = False
                return {"open": False}
            page = self._page()
            image = page.screenshot(type="jpeg", quality=60, timeout=15000)
            return {
                "open": True,
                "url": page.url,
                "title": page.title(),
                "image": base64.b64encode(image).decode(),
            }

        return self.call(run, timeout=30)

    def view_input(self, action: Dict[str, Any]) -> Dict[str, Any]:
        def run():
            if not (self.view_open and self._running()):
                raise Busy("The browser view isn't open.")
            page = self._page()
            kind = action.get("type")
            if kind == "click":
                page.mouse.click(float(action["x"]), float(action["y"]))
            elif kind == "type":
                page.keyboard.type(str(action.get("text", ""))[:500], delay=40)
            elif kind == "key":
                key = str(action.get("key", ""))
                if key not in VIEW_KEYS:
                    raise ValueError("Unsupported key.")
                page.keyboard.press(" " if key == "Space" else key)
            elif kind == "scroll":
                page.mouse.wheel(0, max(-2000, min(2000, float(action.get("dy", 0)))))
            elif kind == "back":
                page.go_back(timeout=15000)
            elif kind == "login_page":
                page.goto(LOGIN_URL, wait_until="domcontentloaded", timeout=60000)
            else:
                raise ValueError("Unknown action.")
            return {"ok": True}

        return self.call(run, timeout=90)

    def view_finish(self) -> Dict[str, Any]:
        """Done in the view: pass Google's session on to YouTube and check it. Signed in: export the cookies and
        close. Not yet: go back to the sign-in page and stay open, so the admin can carry on."""
        def run():
            if not self._running():
                self.view_open = False
                return {"logged_in": False, "cookies": "", "open": False}
            page = self._page()
            # Signed in to Google but not yet to YouTube: this sign-in link passes the session on.
            page.goto(LOGIN_URL, wait_until="domcontentloaded", timeout=60000)
            try:
                page.wait_for_url("https://www.youtube.com/**", timeout=20000)
            except Exception:  # pylint: disable=broad-except
                return {"logged_in": False, "cookies": "", "open": True}
            result = self._youtube_state()
            if result["logged_in"]:
                self._close()
                return {**result, "open": False}
            self._page().goto(LOGIN_URL, wait_until="domcontentloaded", timeout=60000)
            return {"logged_in": False, "cookies": "", "open": True}

        return self.call(run)

    def view_cancel(self) -> Dict[str, Any]:
        return self.call(lambda: (self._close(), {"open": False})[1])

    def wipe(self) -> Dict[str, Any]:
        def run():
            self._close()
            shutil.rmtree(PROFILE, ignore_errors=True)
            return {"wiped": True}

        return self.call(run)
