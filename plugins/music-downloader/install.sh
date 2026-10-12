#!/usr/bin/env bash
# Music Downloader plugin installer for AzuraCast / AzuraCast Retail (Docker installs).
#
#   sudo bash plugins/music-downloader/install.sh              # install or update, from a checkout of the fork
#   sudo bash install.sh --ref main                            # or download the plugin from GitHub
#   sudo bash plugins/music-downloader/install.sh --uninstall
#
# What it sets up (see README.md):
#   - the PHP plugin, mounted read-only into AzuraCast's "web" container
#   - a "music-downloader" worker container (spotDL, yt-dlp, ffmpeg, Deno), built locally from worker/,
#     sharing the station_data volume and reachable only on the internal Docker network
#   - music-downloader.env: the shared secret between the two (root-only file)
#
# Options:
#   --dir PATH     AzuraCast directory, the one with docker-compose.yml (default: auto-detect)
#   --ref REF      Download this branch or tag of the fork instead of using the files next to this script
#   --yes, -y      Don't ask for confirmation
#   --uninstall    Remove the plugin mount and the worker container (its data volume is kept)
set -euo pipefail

REPO="Felix1Catus/azuracast-retail"
PLUGIN="music-downloader"       # folder name: defines the PHP namespace Plugin\MusicDownloader
SERVICE="music-downloader"      # compose service name = the worker's hostname on the Docker network
POT_SERVICE="music-downloader-pot"   # YouTube PO-token server used by yt-dlp (see worker/app/ytdlp.py)
POT_IMAGE="brainicism/bgutil-ytdlp-pot-provider:2.0.2-deno"   # same version as bgutil-ytdlp-pot-provider in worker/requirements.txt
MARK="# managed-by: azuracast plugin installers"
ENV_FILE="music-downloader.env"

DIR=""; REF=""; YES=0; ACTION=install
while [ $# -gt 0 ]; do
  case "$1" in
    --dir) DIR="$2"; shift 2 ;;
    --ref) REF="$2"; shift 2 ;;
    -y|--yes) YES=1; shift ;;
    --uninstall) ACTION=uninstall; shift ;;
    -h|--help) sed -n '2,21p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1 (see --help)"; exit 2 ;;
  esac
done

say()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!!  %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31mxx  %s\033[0m\n' "$*" >&2; exit 1; }
confirm() {
  [ "$YES" = 1 ] && return 0
  [ -r /dev/tty ] || die "No terminal to confirm on; re-run with --yes."
  local a; read -r -p "$1 [y/N] " a </dev/tty; [[ "$a" =~ ^[Yy] ]]
}

# ---------------------------------------------------------------- environment
command -v docker >/dev/null 2>&1 || die "Docker not found. This installer is for Docker installs of AzuraCast."
docker compose version >/dev/null 2>&1 || die "Docker Compose v2 (docker compose) not found."
DC=(docker compose)
docker info >/dev/null 2>&1 || die "Cannot talk to Docker. Run as root (sudo) or as a member of the docker group."

if [ -z "$DIR" ]; then
  for d in "$PWD" /var/azuracast /opt/azuracast /root /home/*/azuracast; do
    if [ -f "$d/docker-compose.yml" ] && grep -qiE 'image:.*azuracast' "$d/docker-compose.yml"; then DIR="$d"; break; fi
  done
fi
[ -n "$DIR" ] && [ -f "$DIR/docker-compose.yml" ] || die "AzuraCast not found. Pass --dir /path/to/azuracast (the folder containing docker-compose.yml)."
DIR=$(cd "$DIR" && pwd)
cd "$DIR"
grep -qiE 'image:.*azuracast' docker-compose.yml || die "$DIR/docker-compose.yml doesn't look like AzuraCast's."
grep -qE '^\s+station_data:' docker-compose.yml || die "docker-compose.yml has no station_data volume; this installer expects AzuraCast's standard layout."
if [ "$ACTION" = install ] && grep -qE '^  music-downloader:' docker-compose.yml; then
  die "Music Downloader is built into this installation (AzuraCast Retail): docker-compose.yml already runs it. Update with ./docker.sh update instead."
fi

container() {
  local id; id=$("${DC[@]}" ps -q web 2>/dev/null | head -1)
  [ -n "$id" ] && docker inspect -f '{{.Name}}' "$id" | sed 's#^/##'
}
C=$(container || true)
[ -n "$C" ] || die "AzuraCast's web container isn't running. Start it first: cd $DIR && ${DC[*]} up -d"

OV="$DIR/docker-compose.override.yml"
VOL_RO=":ro"
if command -v getenforce >/dev/null 2>&1 && [ "$(getenforce 2>/dev/null)" = "Enforcing" ]; then VOL_RO=":ro,z"; fi
VOLUME="./plugins/$PLUGIN:/var/azuracast/www/plugins/$PLUGIN$VOL_RO"

yq() { docker run --rm -u "$(id -u):$(id -g)" -v "$DIR:/workdir" -w /workdir mikefarah/yq:4 "$@"; }

wait_ready() {
  printf 'Waiting for AzuraCast'
  for _ in $(seq 1 120); do
    C=$(container || true)
    if [ -n "$C" ] && docker exec "$C" curl -fsS -o /dev/null http://127.0.0.1:6010/api/status 2>/dev/null; then echo " ready."; return 0; fi
    printf '.'; sleep 3
  done
  echo; die "AzuraCast didn't come back within 6 minutes. Check: ${DC[*]} logs -f web"
}

backup_override() { [ -f "$OV" ] && cp "$OV" "$OV.bak-$(date +%Y%m%d%H%M%S)" || true; }

# ---------------------------------------------------------------- uninstall
if [ "$ACTION" = uninstall ]; then
  echo "This removes the Music Downloader mount and its worker container, and recreates the AzuraCast"
  echo "container (about a minute of downtime). Downloaded music stays in the media library; the worker's"
  echo "data volume (Spotify logins, download history) is kept."
  confirm "Uninstall Music Downloader?" || die "Cancelled."
  if [ -f "$OV" ]; then
    backup_override
    yq -i "
      del(.services.web.volumes[] | select(test(\"plugins/$PLUGIN\"))) |
      del(.services.web.env_file[] | select(. == \"$ENV_FILE\")) |
      del(.services.\"$SERVICE\") |
      del(.services.\"$POT_SERVICE\")
    " docker-compose.override.yml || die "Couldn't edit $OV automatically; remove the music-downloader parts by hand."
    yq -i 'del(.services.web.volumes | select(length == 0)) | del(.services.web.env_file | select(length == 0)) | del(.services.web | select(length == 0))' docker-compose.override.yml || true
  fi
  "${DC[@]}" up -d --remove-orphans
  wait_ready
  docker exec "$C" sh -c 'rm -f /var/azuracast/www_tmp/app_routes*.cache.php' || true
  say "Music Downloader removed. Plugin files left in $DIR/plugins/$PLUGIN; data volume: ${DC[*]} volume ls."
  exit 0
fi

# ---------------------------------------------------------------- install / update
cat <<EOF

This will:
  1. put the Music Downloader plugin in $DIR/plugins/$PLUGIN (an existing copy is kept as a .bak folder)
  2. build the worker image (spotDL + yt-dlp + ffmpeg + Deno; a few minutes the first time)
  3. add the plugin mount, the "$SERVICE" worker and the "$POT_SERVICE" YouTube token server
     ($POT_IMAGE) to $(basename "$OV")
  4. recreate the AzuraCast container (stations restart; about a minute of downtime)
EOF
confirm "Continue?" || die "Cancelled."

say "Getting the plugin"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
SRC_DIR=$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || true)
if [ -z "$REF" ] && [ -n "$SRC_DIR" ] && [ -f "$SRC_DIR/events.php" ] && [ -f "$SRC_DIR/worker/Dockerfile" ]; then
  echo "Using the plugin files next to this script ($SRC_DIR)."
  (cd "$SRC_DIR" && tar --exclude=.git --exclude='*.bak*' -cf - .) | tar -xf - -C "$TMP"
else
  command -v curl >/dev/null 2>&1 || die "curl not found."
  REF=${REF:-main}
  URL="https://codeload.github.com/$REPO/tar.gz/$REF"
  curl -fsSL "$URL" | tar -xz -C "$TMP" --strip-components=3 --wildcards "*/plugins/$PLUGIN/*" \
    || die "Download failed: $URL (plugins/$PLUGIN not found in $REF?)"
fi
[ -f "$TMP/events.php" ] && [ -f "$TMP/worker/Dockerfile" ] || die "The files don't look like the Music Downloader plugin."
NEWV=$(cat "$TMP/VERSION" 2>/dev/null || echo dev)

mkdir -p "$DIR/plugins"
DEST="$DIR/plugins/$PLUGIN"
if [ -d "$DEST" ]; then
  OLDV=$(cat "$DEST/VERSION" 2>/dev/null || echo "?")
  cp -a "$DEST" "$DEST.bak-$(date +%Y%m%d%H%M%S)"
  echo "Previous copy ($OLDV) backed up."
  # Replace the contents but keep the folder itself: a running container's bind mount points at this folder.
  find "$DEST" -mindepth 1 -delete
fi
mkdir -p "$DEST"
(cd "$TMP" && tar -cf - .) | tar -xf - -C "$DEST"
chmod -R a+rX "$DEST"
chmod +x "$DEST"/*.sh 2>/dev/null || true
echo "Music Downloader $NEWV in $DEST"

IMAGE="azuracast-music-downloader:$NEWV"
say "Building the worker image $IMAGE"
docker build -t "$IMAGE" "$DEST/worker" || die "The worker image didn't build."

if [ ! -s "$DIR/$ENV_FILE" ]; then
  TOKEN=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')
  (umask 077; printf '# Shared secret between AzuraCast and the Music Downloader worker (created by install.sh).\nMUSIC_DOWNLOADER_WORKER_TOKEN=%s\nMD_WORKER_TOKEN=%s\n' \
    "$TOKEN" "$TOKEN" > "$DIR/$ENV_FILE")
  echo "Created $ENV_FILE (root-only)."
fi
chmod 600 "$DIR/$ENV_FILE"

say "Adding the plugin and worker to $(basename "$OV")"
[ -f "$OV" ] || printf '%s\nservices: {}\n' "$MARK" > "$OV"
backup_override
yq -i "
  .services.web.volumes = (((.services.web.volumes // []) | map(select(test(\"plugins/$PLUGIN\") | not))) + [\"$VOLUME\"]) |
  .services.web.env_file = (((.services.web.env_file // []) | map(select(. != \"$ENV_FILE\"))) + [\"$ENV_FILE\"]) |
  .services.\"$SERVICE\" = {
    \"container_name\": \"azuracast_music_downloader\",
    \"image\": \"$IMAGE\",
    \"pull_policy\": \"never\",
    \"env_file\": [\"$ENV_FILE\"],
    \"volumes\": [\"station_data:/var/azuracast/stations\", \"music_downloader_data:/data\"],
    \"restart\": \"unless-stopped\",
    \"cpu_shares\": 256,
    \"mem_limit\": \"1g\",
    \"logging\": {\"options\": {\"max-size\": \"1m\", \"max-file\": \"3\"}}
  } |
  .services.\"$POT_SERVICE\" = {
    \"container_name\": \"azuracast_music_downloader_pot\",
    \"image\": \"$POT_IMAGE\",
    \"restart\": \"unless-stopped\",
    \"cpu_shares\": 256,
    \"mem_limit\": \"512m\",
    \"logging\": {\"options\": {\"max-size\": \"1m\", \"max-file\": \"3\"}}
  } |
  .volumes.music_downloader_data = {}
" docker-compose.override.yml || die "Couldn't update $OV automatically (a backup is next to it)."
"${DC[@]}" config -q || die "docker compose rejects the configuration; check $OV (a backup of the previous file is next to it)."

say "Starting the worker and recreating AzuraCast"
"${DC[@]}" up -d
wait_ready
docker exec "$C" test -f "/var/azuracast/www/plugins/$PLUGIN/events.php" || die "The plugin isn't visible inside the container; check the volume in $OV."

printf 'Waiting for the worker'
for _ in $(seq 1 40); do
  docker exec "$C" curl -fsS -o /dev/null http://music-downloader:8000/health 2>/dev/null && { echo " ready."; break; }
  printf '.'; sleep 3
done

say "Activating (caches, health check)"
"$DEST/reload.sh" "$C"

say "Music Downloader $NEWV is installed"
cat <<EOF

Next steps:
  1. Log in to AzuraCast as an administrator, open a station and click "Music Downloader"
     (or go to /music-downloader/<station id>).
  2. Settings (bottom of that page): enter your Spotify app's Client ID and Secret, and register the
     Redirect URI shown there in the Spotify developer dashboard.
  3. Give other roles the station permission "Download Music" under Administration > Roles.

If downloads fail with "YouTube refused the download", this server's IP is blocked by YouTube: add YouTube
cookies or a proxy in Settings (see README.md).

Update later by re-running this script. Remove with --uninstall.
EOF
