# Plan: "Connect YouTube account" for the Music Downloader

Status: design agreed with the user 2026-10-12; not implemented yet (branch `feature/youtube-account`).

## Goal
A button in Music Downloader settings that signs a throwaway Google account into YouTube automatically,
captures the youtube.com cookies, keeps them fresh, and alerts admins when the session needs a human.

## Containers
- New compose service `music-downloader-browser` (container `acacia_music_downloader_browser`), same image as
  the worker (`${MUSIC_DOWNLOADER_IMAGE}`), different command: `xvfb-run -a -s "-screen 0 1280x800x24"
  python -m uvicorn app.browser_api:app --port 8001`. Own volume `music_downloader_browser:/data` (Chromium
  profile), `mem_limit: 1g`, `cpu_shares: 256`, internal only, same env file (MD_WORKER_TOKEN).
- Worker Dockerfile: add Debian `chromium`, `xvfb`, `fonts-liberation`; requirements: `playwright` (only for
  `connect_over_cdp`, no bundled browsers) and `pyotp`.
- Also add the service to docker-compose.retail.yml and plugins/music-downloader/install.sh (standalone).

## Browser service (app/browser_api.py + app/browser.py)
- Chromium launched on demand as a plain subprocess (not Playwright launch, to avoid automation flags):
  `--user-data-dir=/data/profile --remote-debugging-port=9222 --remote-debugging-address=127.0.0.1
  --no-first-run --no-default-browser-check --no-sandbox --disable-dev-shm-usage --window-size=1280,800
  --lang=en-US`; Playwright `connect_over_cdp`. All Playwright calls run on one dedicated thread (job queue).
  Graceful close via CDP so cookies flush; idle auto-close after 10 min.
- Endpoints (Bearer token): `POST /refresh` (import cookies given, open youtube.com, check login via
  LOGIN_INFO/SID + avatar, return Netscape youtube.com cookies, close), `POST /login` {email, password,
  totp_secret} (accounts.google.com ServiceLogin?service=youtube&continue=youtube.com; fill email, password,
  TOTP if asked; detect "Wrong password" -> `wrong_password` (no auto retry), "Verify it's you"/"browser may
  not be secure"/other challenge -> `needs_attention`, browser kept open), remote view: `GET /view`
  (base64 JPEG + url), `POST /view/input` {click x,y | type text | key name | scroll dy}, `POST /view/finish`,
  `POST /view/cancel`, `DELETE /profile`.

## Worker (app/youtube_session.py, main.py, runner.py)
- Settings keys: yt_email, yt_password, yt_totp, yt_state (json: status connected|connecting|expired|
  needs_attention|wrong_password|error|disconnected, message, last_refresh, last_login, alert_pending),
  cookie_source (account|manual; manual cookies are not imported into the browser).
- fcntl file lock so API, keeper thread and runner processes never drive the browser at once.
- Keeper thread: every 10 min; refresh when last_refresh > 6 h and no job running; if logged out, auto
  login once (never after wrong_password; back off 6 h after needs_attention).
- Scheduler.launch: refresh first if last_refresh > 30 min.
- Runner: on "Sign in to confirm" / "no longer valid", force refresh/login, rebuild the spotDL Downloader
  (it loads the cookie file at creation) before the retry pass.
- After a successful login: save cookies (cookies.save) and run cookies.test.
- Endpoints: GET/PUT/DELETE `/youtube/account`, POST `/youtube/connect`, POST `/youtube/refresh`,
  `/youtube/view*` relayed to the browser, GET `/youtube/alert` + POST `/youtube/alert/ack`.
  `public_settings` gains `youtube` (state, masked email, has_password, has_totp).

## PHP plugin
- SettingsController + routes under /api/music-downloader/settings/youtube/... (GlobalPermissions::Settings).
- Event\GetNotifications listener: dashboard notification for settings admins when state is bad
  (2 s timeout, action URL = downloader page of the first station).
- Event\GetSyncTasks task (every 5 min): if alert_pending and mail enabled, email users with
  'administer all', then ack.

## Page (templates/page.html)
- "YouTube account" block above manual cookies: email, password, optional 2FA secret, Connect /
  Refresh now / Disconnect, status line with last refresh.
- Remote view dialog: polls `/view` ~1/s, scales clicks to 1280x800, text box + Enter/Tab/Backspace keys,
  "Done" -> finish.
- Banner for everyone when the session is expired/needs attention (non-admins: "ask an administrator").

## Release
Worker VERSION 0.2.0 + CHANGELOG, FIXLOG entry, retail.13. Deploy only after the user says so.
