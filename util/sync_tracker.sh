#!/usr/bin/env bash
# Copy a Tracker checkout (azuracast-tracker-plugin) into the built-in module folder plugins/branch-tracker.
#   util/sync_tracker.sh /path/to/azuracast-tracker-plugin
set -euo pipefail
cd "$( dirname "${BASH_SOURCE[0]}" )/.."

SRC="${1:?usage: util/sync_tracker.sh <path to a Tracker checkout>}"
[[ -f "$SRC/events.php" && -f "$SRC/VERSION" ]] || { echo "$SRC is not a Tracker checkout"; exit 1; }

rsync -a --delete --exclude .git "$SRC/" plugins/branch-tracker/
echo "plugins/branch-tracker is now Tracker $(cat plugins/branch-tracker/VERSION). Review with: git diff --stat plugins/"
