# Changelog

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
