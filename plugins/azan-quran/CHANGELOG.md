# Changelog

## 0.2.0 (2026-10-13)

- The pauses and the Quran are cued by the plugin itself (`Sync\CueTask`, every minute): it pushes the file into
  Liquidsoap's interrupting queue at the prayer minute and at the Quran's start. The two playlists only hold the
  audio and stay disabled. 0.1.0 used scheduled interrupting playlists, but Acacia Radio also writes those into
  Liquidsoap's configuration, which is only reloaded on a restart: daily-changing prayer times would have needed
  daily restarts, and a pause could have played twice.
- The fade at opening time is part of the same task; a cue is sent at most once (also when the sync runs late).
- The page always shows the Quran's start time, also before a city is set.
- Tested live on the dev server (Cairo): the Quran cut in on the minute, faded into music 15 s after opening
  time, and a prayer pause gave exactly 3 minutes of silence, then music.

## 0.1.0 (2026-10-12)

- First version: silent pause at prayer times (Aladhan API), Quran before opening with a 15 s fade.
