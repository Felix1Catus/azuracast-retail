#!/usr/bin/env bash
# Apply plugin code changes to a running Acacia Radio container (local dev, and the prod deploy runbook).
#   ./reload.sh [container]   (default: $TRACKER_CONTAINER, else the running Acacia Radio container)
# Why each step matters (all learned the hard way in phase 0/1):
#   lint          - a PHP error in plugin wiring used to break container startup
#   php restarts  - opcache only re-reads files every 60 s
#   route cache   - production mode caches routes in www_tmp/app_routes*.cache.php, keyed on events.php's mtime. A
#                   request in the 60 s opcache window can cache the OLD routes under the NEW key (new routes then
#                   405), so every route cache is removed after PHP restarts.
set -euo pipefail
C=${1:-${TRACKER_CONTAINER:-$(docker ps --filter label=com.docker.compose.service=web --format '{{.Names}}' | head -n 1)}}
[ -n "$C" ] || { echo "Acacia Radio container not found; pass its name: ./reload.sh <container>"; exit 1; }
P=/var/acacia/www/plugins/branch-tracker

echo "==> lint"
docker exec "$C" sh -c "find $P -name '*.php' -print0 | xargs -0 -n1 php -l" | grep -v '^No syntax errors' && exit 1 || true

echo "==> restart PHP workers + clear route caches"
docker exec "$C" supervisorctl restart php-fpm php-nowplaying php-worker >/dev/null
docker exec "$C" sh -c 'rm -f /var/acacia/www_tmp/app_routes*.cache.php'

echo "==> migrations"
docker exec -u acacia "$C" acacia_cli migrations:migrate --no-interaction --allow-no-migration 2>&1 | grep -E 'OK|migrat|Error' || true

echo "==> health"
docker exec "$C" curl -fsS http://127.0.0.1:6010/api/branch-tracker/ping && echo
# supervisorctl exits non-zero whenever anything isn't RUNNING (the one-shot "startup" never is), so capture first.
status=$(docker exec "$C" supervisorctl status || true)
awk '$2 != "RUNNING" && $2 != "STARTING" && $1 != "startup" {bad=1; print "  NOT RUNNING:", $0} END {exit bad}' <<<"$status"
sync=$(docker exec -u acacia "$C" acacia_cli acacia:settings:list 2>/dev/null | awk '$1=="sync_disabled"{print $2}')
[ -z "$sync" ] || [ "$sync" = "0" ] || [ "$sync" = "false" ] || { echo "  sync_disabled is ON"; exit 1; }
echo "==> ok"
