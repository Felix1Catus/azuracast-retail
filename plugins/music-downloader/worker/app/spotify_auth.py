"""Spotify login (OAuth authorization code flow) for one AzuraCast user at a time.

The token is stored in spotipy's cache format, so a job runner can hand it to spotDL through a cache file
and spotipy refreshes it on its own during long jobs.
"""

import base64
import secrets
import time
from typing import Any, Dict
from urllib.parse import urlencode

import requests

from app import config

AUTHORIZE_URL = "https://accounts.spotify.com/authorize"
TOKEN_URL = "https://accounts.spotify.com/api/token"
ME_URL = "https://api.spotify.com/v1/me"
STATE_TTL = 15 * 60


class AuthError(Exception):
    pass


def new_state() -> str:
    return secrets.token_urlsafe(24)


def authorize_url(client_id: str, redirect_uri: str, state: str) -> str:
    return AUTHORIZE_URL + "?" + urlencode(
        {
            "client_id": client_id,
            "response_type": "code",
            "redirect_uri": redirect_uri,
            "scope": config.SPOTIFY_SCOPES,
            "state": state,
            "show_dialog": "true",
        }
    )


def exchange_code(client_id: str, client_secret: str, code: str, redirect_uri: str) -> Dict[str, Any]:
    basic = base64.b64encode(f"{client_id}:{client_secret}".encode()).decode()
    try:
        res = requests.post(
            TOKEN_URL,
            data={"grant_type": "authorization_code", "code": code, "redirect_uri": redirect_uri},
            headers={"Authorization": f"Basic {basic}"},
            timeout=15,
        )
    except requests.RequestException as exc:
        raise AuthError(f"Could not reach Spotify: {exc}") from exc

    body = _json(res)
    if res.status_code != 200 or "access_token" not in body:
        detail = body.get("error_description") or body.get("error") or f"HTTP {res.status_code}"
        if body.get("error") == "invalid_client":
            detail = "Spotify rejected the Client ID or Client Secret."
        elif body.get("error") == "invalid_grant":
            detail = "The login code was already used or has expired, or the redirect URI doesn't match. Start the login again."
        raise AuthError(detail)

    body["expires_at"] = int(time.time()) + int(body.get("expires_in", 3600))
    return body


def fetch_profile(access_token: str) -> Dict[str, Any]:
    try:
        res = requests.get(ME_URL, headers={"Authorization": f"Bearer {access_token}"}, timeout=15)
    except requests.RequestException as exc:
        raise AuthError(f"Could not reach Spotify: {exc}") from exc

    if res.status_code == 403:
        # Development-mode apps only accept users listed under "User Management" in the Spotify dashboard.
        raise AuthError(
            "Spotify refused this account. In the Spotify developer dashboard, add it under "
            "User Management (development-mode apps allow up to 5 listed users), and make sure the "
            "app owner has Spotify Premium."
        )
    if res.status_code != 200:
        raise AuthError(f"Spotify profile request failed (HTTP {res.status_code}).")
    return _json(res)


def _json(res: requests.Response) -> Dict[str, Any]:
    try:
        data = res.json()
        return data if isinstance(data, dict) else {}
    except ValueError:
        return {}
