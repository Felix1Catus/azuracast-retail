#!/usr/bin/env bash
# Apply plugin code changes to a running Acacia Radio container (local dev, and after install/update).
#   ./reload.sh [container]   (default: the running Acacia Radio web container)
#   lint          - a PHP error in plugin wiring can break container startup
#   php restarts  - opcache only re-reads files every 60 s
#   route cache   - routes are cached in www_tmp/app_routes*.cache.php, keyed on events.php's mtime. A request in
#                   the 60 s opcache window can cache the OLD routes under the NEW key (new routes then 405), so
#                   every route cache is removed after PHP restarts.
set -euo pipefail
C=${1:-$(docker ps --format '{{.Names}} {{.Image}}' | awk 'tolower($2) ~ /azuracast\/azuracast|\/azuracast:|^azuracast-retail|^azuracast:/ {print $1; exit}')}
[ -n "$C" ] || { echo "Acacia Radio container not found; pass its name: ./reload.sh <container>"; exit 1; }
P=/var/azuracast/www/plugins/music-downloader

echo "==> lint"
docker exec "$C" sh -c "find $P -name '*.php' -print0 | xargs -0 -n1 php -l" | grep -v '^No syntax errors' && exit 1 || true

echo "==> restart PHP workers + clear route caches"
docker exec "$C" supervisorctl restart php-fpm php-nowplaying php-worker >/dev/null
docker exec "$C" sh -c 'rm -f /var/azuracast/www_tmp/app_routes*.cache.php'

echo "==> worker reachable from Acacia Radio"
docker exec "$C" sh -c 'curl -fsS "${MUSIC_DOWNLOADER_WORKER_URL:-http://music-downloader:8000}/health"' && echo

echo "==> token shared"
docker exec "$C" sh -c '[ -n "$MUSIC_DOWNLOADER_WORKER_TOKEN" ]' || { echo "  MUSIC_DOWNLOADER_WORKER_TOKEN is not set in $C"; exit 1; }
docker exec "$C" sh -c 'curl -fsS -o /dev/null -H "Authorization: Bearer $MUSIC_DOWNLOADER_WORKER_TOKEN" "${MUSIC_DOWNLOADER_WORKER_URL:-http://music-downloader:8000}/settings"' \
  || { echo "  the worker rejects Acacia Radio's token"; exit 1; }

# supervisorctl exits non-zero whenever anything isn't RUNNING (the one-shot "startup" never is), so capture first.
status=$(docker exec "$C" supervisorctl status || true)
awk '$2 != "RUNNING" && $2 != "STARTING" && $1 != "startup" {bad=1; print "  NOT RUNNING:", $0} END {exit bad}' <<<"$status"
echo "==> ok"
