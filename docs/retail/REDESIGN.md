# AzuraCast Retail: redesign plan

## What the product is

In-store music for a chain of branches, run from one server.

| Today (TLRadio) | Target |
|---|---|
| One AzuraCast station ("Store") plays to every branch | One or more channels (stations), each with its own music, schedule and announcements |
| Each branch device opens the public player and logs in with a 4-digit PIN (Tracker plugin) | Same, native in the panel |
| Admins see a Branch Board: online, offline, volume, last seen | Branch Board is a first-class page, with history and alerts |
| AzuraCast panel exposes radio-station features nobody uses | Panel shows only what a retail operator needs |

**Users:**
- **Operator / admin:** manages music, schedules, announcements and branches.
- **Branch device:** a browser or kiosk player. It never sees the panel.

**Not goals:** public internet radio, podcasts, listener requests, live DJs for an audience, relays, directory listings.

## Guiding rules

1. **Hide first, delete later.** Gate features through `StationFeatures` (REVIEW R-14) so upstream merges stay cheap. Delete code only once a feature has been hidden for a release with no complaints.
2. **One commit per logical change**, with upstream fixes merged regularly (`git fetch upstream && git merge upstream/main`).
3. **Keep the AGPL obligations:** license headers, `LICENSE.md`, and the `/source` endpoint on every deployment.
4. **The live server only gets tagged releases** built with `util/build_image.sh`, and only after local testing.

## Keep / hide / remove

| Area | Decision | How |
|---|---|---|
| Media library, folders, metadata, waveform and cue editor | **Keep** | |
| Playlists (general, scheduled, once-per-X, sequential, jingles via "advanced") | **Keep**; rename UI terms (playlist → "music rotation", jingle → "announcement") later | |
| Schedules (`StationSchedule`) | **Keep**; core for opening hours and day-parting | |
| Mount points (Icecast) | **Keep** one default; hide the editor behind "advanced" | feature flag |
| Liquidsoap, AutoDJ, crossfade, normalization | **Keep** | |
| Custom Liquidsoap config | **Hide** (admin "advanced" only) | `StationFeatures::CustomLiquidsoapConfig` |
| Now Playing, Centrifugo, static NP | **Keep** | |
| Listeners and analytics | **Keep**; always record listeners for presence (R-6) | |
| Reports (overview, timeline, listeners, SoundExchange) | Keep overview and listeners; **hide** SoundExchange and royalty reports | menu |
| Streamers / live DJ / WebDJ | **Hide** (possible future use: live announcements) | `StationFeatures::Streamers` |
| Requests | **Remove** | `StationFeatures::Requests` → then delete |
| On-demand | **Remove** | `StationFeatures::OnDemand` |
| Podcasts | **Remove** | `StationFeatures::Podcasts` |
| Remote relays and AzuraRelay | **Remove** | `StationFeatures::RemoteRelays` |
| HLS | **Hide** (MP3/AAC over HTTPS is enough for in-store players) | `StationFeatures::HlsStreams` |
| Webhooks | **Hide**; keep generic HTTP only, for alerts | `StationFeatures::Webhooks` (fixed in R-1) |
| SFTP and SFTPGo | **Remove** (process, port 2022, entity) | `StationFeatures::Sftp`, drop supervisor conf |
| Shoutcast / RSAS / Stereo Tool installers | **Remove** | admin menu and routes, Dockerfile volumes |
| GeoLite / MaxMind | **Remove** | sync task, admin page, volume |
| Built-in web updater | **Disable** (R-8) | `ENABLE_WEB_UPDATER=false` |
| Public pages (history, schedule, embed, oEmbed) | **Hide**; keep the player only | routes |
| Branding (custom CSS/JS) | **Keep**; ship the TLRadio theme as the default | |
| Users, roles, API keys, 2FA, passkeys | **Keep** | |
| Backups | **Keep** (with the R-3 fix) | |

## Native retail features (new)

1. **Branches as entities:** `Branch` (name, code, PIN hash, station, active), `BranchDevice` (device id, last seen, user agent, volume) and `BranchSession`. These move from the Tracker plugin into `backend/src/Entity`, with a migration that imports the plugin tables as they are.
2. **Branch Board** as a station menu item (`frontend/components/Stations/menu.ts`) and a Vue page. It replaces the injected button (R-13) and the plugin's standalone page. It keeps:
   - the optional public read-only link
   - "log out all"
   - auto-refresh
   - the text status
3. **Branch player:** the public player plus the PIN login, with the daily 00:30 reset and one device per branch. It is served by a native controller and works without the plugin.
4. **Alerts (later):** a branch offline during opening hours sends an email or webhook, using the existing `Notification/` and the schedule.
5. **Per-branch overrides (later):** a branch can be pinned to a different station or volume, for regional music and announcements.

## Phases

| Phase | Scope | Exit criteria |
|---|---|---|
| **0: Foundation** (this branch) | Fork baseline, `/source` + footer link, R-1/R-2/R-3 fixes, self-contained image build, docs | Image builds locally from a clean checkout, `/source` downloads, panel works |
| **1: Sync and harden** | Merge upstream (R-4), disable web updater (R-8), compose with 80/443 only (R-10), route-cache fix (R-7), always record listeners (R-6), source link on public pages (R-12) | Local stack on `azuracast-retail:dev` with Tracker plugin passes a smoke test |
| **2: Retail mode** (done, #11) | Features marked Remove are switched off in `StationFeatures` and `Station`, and their menus, pages, routes, tasks and SFTPGo are removed. See [FIXLOG.md](FIXLOG.md). Footer without donation prompts (retail.2) | Panel shows only retail items; upstream merges still clean |
| **3: Branch Board native** | Move Tracker entities, controllers and Vue in-tree; migration from plugin tables; native menu item; retire the plugin | Live data migrated on a copy of production with no PIN re-entry |
| **4: Slim image** (code removed in retail.2, #13) | Remove SFTPGo, installers, GeoLite, relays, podcasts, requests and on-demand code and volumes | Image size and process count measured before and after |
| **5: Production cut-over** | Tagged release, backup, deploy to tradelineradio.com, rollback plan | Branches online on the fork, `/source` live |
