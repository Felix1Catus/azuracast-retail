"""YouTube cookies.txt handling: validation, clean-up and a live test with yt-dlp.

Only *.youtube.com cookies are kept: those are the ones yt-dlp sends to YouTube. Google-account cookies
(.google.com, accounts.google.com) aren't needed and would only widen what's stored on the server.
"""

import os
import subprocess
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from app import config

# A signed-in YouTube session always has at least one of these on .youtube.com.
LOGIN_COOKIES = {"LOGIN_INFO", "SID", "__Secure-1PSID", "__Secure-3PSID"}
TEST_VIDEO = "https://www.youtube.com/watch?v=jNQXAC9IVRw"  # "Me at the zoo", YouTube's first video


class CookieError(ValueError):
    pass


def _parse(text: str) -> List[Tuple[str, str, str, str]]:
    """Return (domain, path, name, line) for every cookie line, HttpOnly ones included."""
    cookies = []
    for raw in text.splitlines():
        line = raw.rstrip("\r")
        check = line[len("#HttpOnly_"):] if line.startswith("#HttpOnly_") else line
        if not check or check.startswith("#"):
            continue
        fields = check.split("\t")
        if len(fields) != 7 or fields[1] not in ("TRUE", "FALSE") or fields[3] not in ("TRUE", "FALSE"):
            continue
        cookies.append((fields[0].lower(), fields[2], fields[5], line))
    return cookies


def _is_youtube(domain: str) -> bool:
    return domain.lstrip(".") == "youtube.com" or domain.endswith(".youtube.com")


def clean(text: str) -> Tuple[str, Dict[str, Any]]:
    """Keep the YouTube cookies (last one wins for duplicates) and check they hold a YouTube login."""
    cookies = _parse(text)
    if not cookies:
        raise CookieError("That isn't a Netscape-format cookies.txt (tab-separated lines with 7 fields).")

    kept: Dict[Tuple[str, str, str], str] = {}
    for domain, path, name, line in cookies:
        if _is_youtube(domain):
            kept[(domain, path, name)] = line
    if not kept:
        raise CookieError("The file has no youtube.com cookies. Export it from a youtube.com tab.")

    names = {name for (_, _, name) in kept}
    if not names & LOGIN_COOKIES:
        raise CookieError(
            "These cookies don't include a YouTube login (no LOGIN_INFO or SID cookie for youtube.com), "
            "so YouTube would still treat the server as a stranger. Open youtube.com, check that your "
            "account picture shows at the top right (if not, click Sign in), then export the cookies "
            "again from that youtube.com tab."
        )

    body = "# Netscape HTTP Cookie File\n# Saved by Music Downloader (youtube.com cookies only)\n\n"
    body += "\n".join(kept.values()) + "\n"
    return body, {"count": len(kept), "youtube_login": True}


def save(text: str) -> Dict[str, Any]:
    body, _ = clean(text)
    tmp = config.COOKIE_FILE.with_suffix(".tmp")
    tmp.write_text(body)
    tmp.chmod(0o600)
    os.replace(tmp, config.COOKIE_FILE)
    return info()


def info() -> Optional[Dict[str, Any]]:
    path: Path = config.COOKIE_FILE
    if not path.exists():
        return None
    cookies = [c for c in _parse(path.read_text(errors="replace")) if _is_youtube(c[0])]
    return {
        "count": len(cookies),
        "youtube_login": bool({c[2] for c in cookies} & LOGIN_COOKIES),
        "saved_at": int(path.stat().st_mtime),
    }


def test(proxy: str) -> Dict[str, Any]:
    """Ask YouTube for one video's formats with the saved cookies (no download). yt-dlp also refreshes the
    cookie file with whatever YouTube sends back, which keeps the session alive."""
    if not config.COOKIE_FILE.exists():
        return {"ok": False, "message": "No cookies saved."}
    cmd = ["yt-dlp", "--no-update", "--no-warnings", "--cookies", str(config.COOKIE_FILE),
           "--skip-download", "-f", "bestaudio", "--print", "%(format_id)s %(abr)s", TEST_VIDEO]
    if proxy:
        cmd[1:1] = ["--proxy", proxy]
    try:
        res = subprocess.run(cmd, capture_output=True, text=True, timeout=120, check=False)
    except subprocess.TimeoutExpired:
        return {"ok": False, "message": "YouTube didn't answer within 2 minutes."}
    if res.returncode == 0 and res.stdout.strip():
        return {"ok": True, "message": "YouTube accepted the cookies: downloads should work."}
    err = next((l for l in res.stderr.splitlines() if l.startswith("ERROR")), res.stderr.strip()[-300:])
    if "Sign in to confirm" in err:
        return {"ok": False, "message": "YouTube still asks to sign in: the cookies are expired or signed out. "
                                        "Export fresh ones from a signed-in youtube.com tab."}
    return {"ok": False, "message": err[:400] or f"yt-dlp failed (exit code {res.returncode})."}
