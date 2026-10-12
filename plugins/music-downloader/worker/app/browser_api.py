"""API of the music-downloader-browser container (same image as the worker, different command).

Internal only, called by the worker (app/youtube_session.py) with the shared token. An administrator signs the
YouTube account in through the remote view; the session's cookies go to the worker and the profile is deleted.
"""

import hmac
import logging
from typing import Any, Dict

from fastapi import Depends, FastAPI, Header, HTTPException

from app import config
from app.browser import Browser, Busy

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")

app = FastAPI(title="Music Downloader browser", docs_url=None, redoc_url=None, openapi_url=None)
browser = Browser()


def require_token(authorization: str = Header(default="")) -> None:
    if not config.TOKEN:
        raise HTTPException(503, "The browser has no MD_WORKER_TOKEN set.")
    if not hmac.compare_digest(authorization.encode(), f"Bearer {config.TOKEN}".encode()):
        raise HTTPException(401, "Bad worker token.")


auth = [Depends(require_token)]


def run(fn) -> Dict[str, Any]:
    try:
        return fn()
    except Busy as exc:
        raise HTTPException(409, str(exc)) from exc
    except (ValueError, KeyError) as exc:
        raise HTTPException(422, str(exc) or "Bad request.") from exc
    except Exception as exc:  # pylint: disable=broad-except
        raise HTTPException(500, f"Browser error: {exc}") from exc


@app.get("/health")
def health() -> Dict[str, Any]:
    return {"ok": True}


@app.post("/view/open", dependencies=auth)
def view_open() -> Dict[str, Any]:
    return run(browser.view_start)


@app.get("/view", dependencies=auth)
def view() -> Dict[str, Any]:
    return run(browser.view_frame)


@app.post("/view/input", dependencies=auth)
def view_input(body: Dict[str, Any]) -> Dict[str, Any]:
    return run(lambda: browser.view_input(body))


@app.post("/view/finish", dependencies=auth)
def view_finish() -> Dict[str, Any]:
    return run(browser.view_finish)


@app.post("/view/cancel", dependencies=auth)
def view_cancel() -> Dict[str, Any]:
    return run(browser.view_cancel)


@app.delete("/profile", dependencies=auth)
def wipe_profile() -> Dict[str, Any]:
    return run(browser.wipe)
