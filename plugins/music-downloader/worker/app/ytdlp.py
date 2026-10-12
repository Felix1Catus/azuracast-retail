"""yt-dlp options for YouTube, shared by job runs and the cookie test.

YouTube blocks most datacenter IPs unless the request is signed in (cookies) and carries a "PO token". On a
signed-in session, YouTube often serves the regular web client only as SABR streams, which yt-dlp can't
download, so:
  - player clients: web_safari first (it also gets HLS streams, which aren't SABR), then web
  - PO tokens: from the bgutil server in the music-downloader-pot container
  - format: audio-only when offered, otherwise the HLS streams 93-95 (AAC-LC audio + a small video track,
    which ffmpeg drops with -vn), then anything with audio
"""

from typing import List

from app import config

FORMAT = "bestaudio/93/94/95/18/best"


def args() -> List[str]:
    result = ["-f", FORMAT, "--extractor-args", f"youtube:player_client={config.YOUTUBE_CLIENTS}"]
    if config.POT_URL:
        result += ["--extractor-args", f"youtubepot-bgutilhttp:base_url={config.POT_URL}"]
    return result
