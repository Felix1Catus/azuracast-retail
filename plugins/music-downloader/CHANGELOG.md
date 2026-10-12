# Changelog

## 0.2.2 (2026-10-12)

- The YouTube account is now signed in through the browser **only**: on Done, the cookies go to the worker and the
  browser deletes its profile. 0.2.0 and 0.2.1 had the browser refresh the session every few hours, and Google
  signed it out at the first refresh: it treats a session used by two different clients (Chromium and yt-dlp) as
  stolen and deletes the login cookies (seen in the profile: SID, HSID, LOGIN_INFO, __Secure-1PSID gone seconds
  after Chromium reopened it). 0.2.1's fix (export after leaving YouTube, no import) didn't change that.
- Instead of refreshing, the worker checks the session with yt-dlp every 6 hours (`MD_YT_CHECK_HOURS`) and when a
  download is refused; a sign-out raises the same alerts. **Check now** replaces Refresh now.

## 0.2.1 (2026-10-12)

- Fix: the YouTube account was signed out at its first refresh. The worker loaded its (older) cookie file into
  the browser profile before opening YouTube, overwriting the rotated session, and the export happened while
  YouTube's page could still rotate the cookies. Now the profile is never overwritten, and cookies are exported
  after leaving YouTube, so the worker's copy matches the profile exactly. Sign in again once after updating.

## 0.2.0 (2026-10-12)

- **Connect YouTube account:** an administrator signs a separate Google account in once, in a browser on the
  server shown on the settings page (click and type into a live picture). No password is stored. A new
  `music-downloader-browser` container (same image; Chromium under Xvfb, 1 GB limit) keeps the session, and the
  worker refreshes the YouTube cookies from it every 6 hours, before a job when they're older than 30 minutes,
  and once mid-job when YouTube refuses them (the downloader is then rebuilt and the retry pass uses the new
  cookies). Chromium only runs during a refresh or the sign-in.
- When YouTube signs the account out: a banner on the downloader page, a dashboard notice for settings admins,
  and one email to administrators when outgoing email is set up.
- Manually pasted cookies still work without an account; with one connected they're replaced at the next refresh.

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
- install.sh: updates replace the plugin files in place, so a running Acacia Radio picks them up.

## 0.1.0 (2026-10-11)

First version.

- Station page: paste a Spotify playlist, album or track link, choose a media folder (or a new subfolder), a format
  and a quality, and follow the download track by track (retry failed tracks, cancel, technical log).
- Spotify login per Acacia Radio user (OAuth). Works automatically on HTTPS sites and by pasting the redirect
  address otherwise. Falls back to public access for playlists the login can't read.
- Settings for administrators: Spotify app Client ID and Secret, audio sources, default format and quality,
  YouTube cookies and a proxy for servers that YouTube blocks.
- Worker container (spotDL 4.5.2, yt-dlp 2026.08.19, Deno, ffmpeg): runs one job at a time at low priority,
  writes files as the acacia user, never leaves partial files in the library, and resumes after restarts.
- `install.sh` (install, update, uninstall) and `reload.sh`.
