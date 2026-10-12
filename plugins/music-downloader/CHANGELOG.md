# Changelog

## 0.1.3 (2026-10-12)

- About 4x faster: ~17 s per track instead of ~65-75 s on a 1-CPU server. spotDL's YouTube search fully
  extracted all 10 results (plus up to 8 more pages for view counts), each costing ~6 s behind PO tokens and
  JS challenges. The search is now flat (one request; same title, duration, channel and views), and view
  counts are no longer fetched page by page (`MD_RANK_BY_VIEWS=1` restores that).
- YouTube clients are now `mweb,web_safari` (`MD_YOUTUBE_CLIENTS`): with signed-in cookies, mweb gets
  audio-only Opus (~130-140 kbps) for both music videos and auto-generated "Topic" uploads, which web_safari
  alone couldn't download.

## 0.1.2 (2026-10-12)

- Downloads work on servers that YouTube blocks: with signed-in cookies, YouTube still served the web client
  only as SABR streams (nothing downloadable). yt-dlp now uses the web_safari client (HLS streams) and gets
  PO tokens from a new `music-downloader-pot` container (bgutil-ytdlp-pot-provider 2.0.2). When no audio-only
  stream is offered, the HLS stream's AAC audio is used and ffmpeg drops the video (`-vn`).
- The cookie test uses the same yt-dlp options as downloads, and says when Google has rotated (invalidated) the cookies.
- Failed tracks are retried once at the end of a job (the first YouTube request after a restart can fail while
  the PO-token server warms up).
- install.sh waits for the worker to answer before its health check.
- Cookies without a YouTube login are accepted again (shown as such): with PO tokens, YouTube also accepts a
  real browser's visitor cookies. Only youtube.com cookies are still kept.

## 0.1.1 (2026-10-12)

- YouTube cookies: only the youtube.com cookies are kept, and cookies without a YouTube login are refused
  with an explanation (Google-account cookies alone don't sign YouTube in). Settings show what's saved, and
  a "Test with YouTube" button (run automatically after saving) checks the cookies for real.
- install.sh: updates replace the plugin files in place, so a running AzuraCast picks them up.

## 0.1.0 (2026-10-11)

First version.

- Station page: paste a Spotify playlist, album or track link, choose a media folder (or a new subfolder), a format
  and a quality, and follow the download track by track (retry failed tracks, cancel, technical log).
- Spotify login per AzuraCast user (OAuth). Works automatically on HTTPS sites and by pasting the redirect
  address otherwise. Falls back to public access for playlists the login can't read.
- Settings for administrators: Spotify app Client ID and Secret, audio sources, default format and quality,
  YouTube cookies and a proxy for servers that YouTube blocks.
- Worker container (spotDL 4.5.2, yt-dlp 2026.08.19, Deno, ffmpeg): runs one job at a time at low priority,
  writes files as the azuracast user, never leaves partial files in the library, and resumes after restarts.
- `install.sh` (install, update, uninstall) and `reload.sh`.
