#!/usr/bin/env bash
#
# Acacia Radio installer for a fresh Ubuntu or Debian server.
#
#   curl -fsSL https://raw.githubusercontent.com/Felix1Catus/azuracast-retail/main/util/install_retail.sh -o install.sh
#   sudo bash install.sh --domain radio.example.com
#
# Every step is skipped when it's already done, so running it again is safe (and is how you repair an install).
# The terminal shows one line per step; the output of every command goes to /var/log/acacia-install.log.
#
set -euo pipefail

INSTALL_DIR=/var/acacia
SRC_DIR=/opt/acacia-radio
SOURCE=https://github.com/Felix1Catus/azuracast-retail.git
REGISTRY="${ACACIA_IMAGE_REGISTRY:-ghcr.io/felix1catus}"
REF=""
DOMAIN=""
EMAIL=""
HTTP_PORT=80
HTTPS_PORT=443
ASSUME_YES=0
FORCE_BUILD=0
LOG=/var/log/acacia-install.log

usage() {
  cat <<'EOF'
Acacia Radio installer

Usage: sudo bash install.sh [options]

  --domain NAMES     Public domain(s), comma-separated; the first one is the site address.
                     Gets a free Let's Encrypt certificate (DNS must already point here).
  --email ADDRESS    Contact address for Let's Encrypt (default: admin@<domain>)
  --ref REF          Release to install, e.g. v0.23.8-retail.17 (default: the newest release)
  --dir PATH         Install directory (default: /var/acacia)
  --src-dir PATH     Source checkout used for updates (default: /opt/acacia-radio)
  --http-port N      HTTP port (default: 80)
  --https-port N     HTTPS port (default: 443)
  --build            Build the images on this server instead of downloading them (15-40 min)
  -y, --yes          Don't ask for confirmation
  -h, --help         Show this help

Environment:
  ACACIA_IMAGE_REGISTRY   where the prebuilt images come from (default ghcr.io/felix1catus)
  RETAIL_GIT_TOKEN        read-only GitHub token, only needed for a private copy of the repository
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dir) INSTALL_DIR="$2"; shift 2 ;;
    --src-dir) SRC_DIR="$2"; shift 2 ;;
    --source) SOURCE="$2"; shift 2 ;;
    --ref) REF="$2"; shift 2 ;;
    --domain) DOMAIN="$2"; shift 2 ;;
    --email) EMAIL="$2"; shift 2 ;;
    --http-port) HTTP_PORT="$2"; shift 2 ;;
    --https-port) HTTPS_PORT="$2"; shift 2 ;;
    --build) FORCE_BUILD=1; shift ;;
    --yes|-y) ASSUME_YES=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'Unknown option: %s\n\n' "$1"; usage; exit 1 ;;
  esac
done

# ------------------------------------------------------------------------------------------------ output

if [[ -t 1 ]]; then
  TTY=1
  B=$'\033[1m'; D=$'\033[2m'; R=$'\033[0m'
  GREEN=$'\033[32m'; RED=$'\033[31m'; YELLOW=$'\033[33m'; CYAN=$'\033[36m'
else
  TTY=0; B=""; D=""; R=""; GREEN=""; RED=""; YELLOW=""; CYAN=""
fi
SPIN=(⠋ ⠙ ⠹ ⠸ ⠼ ⠴ ⠦ ⠧ ⠇ ⠏)
START_ALL=$SECONDS

took() { # time since $1 (a $SECONDS value), as "42s" or "3m 05s"
  local s=$((SECONDS - $1))
  if ((s >= 60)); then printf '%dm %02ds' $((s / 60)) $((s % 60)); else printf '%ds' "$s"; fi
}
ok()      { printf '  %s✓%s %s%s\n' "$GREEN" "$R" "$1" "${2:+  ${D}$2${R}}"; }
note()    { printf '    %s%s%s\n' "$D" "$1" "$R"; }
warn()    { printf '  %s!%s %s\n' "$YELLOW" "$R" "$1"; }
section() { printf '\n%s%s%s\n' "$B" "$1" "$R"; }
die() {
  printf '  %s✗%s %s\n' "$RED" "$R" "$1"
  if [[ -n "${2:-}" ]]; then printf '    %s\n' "$2"; fi
  exit 1
}

# run LABEL CMD...: run a command or shell function, its output going to the log, with a spinner and the time it
# took. DONE=... sets the success line. SOFT=1 returns the error instead of stopping (for steps with a fallback).
run() {
  local label="$1" start=$SECONDS rc=0 i=0 pid done_label="${DONE:-$1}" soft="${SOFT:-0}"
  shift
  printf '\n### %s  (%s)\n$ %s\n' "$label" "$(date '+%F %T')" "$*" >> "$LOG"
  if ((TTY)); then
    ("$@") >> "$LOG" 2>&1 &
    pid=$!
    while kill -0 "$pid" 2> /dev/null; do
      printf '\r  %s%s%s %s %s%s%s ' "$CYAN" "${SPIN[i++ % ${#SPIN[@]}]}" "$R" "$label" "$D" "$(took "$start")" "$R"
      sleep 0.12
    done
    wait "$pid" || rc=$?
    printf '\r\033[K'
  else
    printf '  … %s\n' "$label"
    ("$@") >> "$LOG" 2>&1 &
    wait $! || rc=$?
  fi
  if ((rc == 0)); then
    ok "$done_label" "$(took "$start")"
    return 0
  fi
  printf '  %s✗%s %s  %s(failed after %s)%s\n' "$RED" "$R" "$label" "$D" "$(took "$start")" "$R"
  ((soft)) && return "$rc"
  printf '\n  %sLast lines of %s:%s\n' "$D" "$LOG" "$R"
  tail -n 20 "$LOG" | sed 's/^/    /'
  printf '\n  Fix the problem and run the installer again; finished steps are skipped.\n\n'
  exit "$rc"
}

# ------------------------------------------------------------------------------------------------ banner

printf '\n  %sAcacia Radio%s %sinstaller%s\n' "$B$GREEN" "$R" "$D" "$R"
printf '  %s──────────────────────%s\n' "$D" "$R"

[[ $EUID -eq 0 ]] || die "Run the installer as root:" "sudo bash $0 $*"
mkdir -p "$(dirname "$LOG")"
printf '\n=========== Acacia Radio install, %s ===========\n' "$(date '+%F %T')" >> "$LOG"

# ------------------------------------------------------------------------------------------------ 1. checks

section "Checking this server"
# shellcheck disable=SC1091
. /etc/os-release
case "${ID:-}" in
  ubuntu|debian) ;;
  *) die "${PRETTY_NAME:-This system} isn't supported." "Acacia Radio installs on Ubuntu or Debian." ;;
esac
ARCH=$(dpkg --print-architecture)
[[ "$ARCH" == amd64 || "$ARCH" == arm64 ]] || die "The $ARCH architecture isn't supported (amd64 and arm64 are)."
CPUS=$(nproc 2> /dev/null || echo 1)
MEM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
mkdir -p "$(dirname "$SRC_DIR")" "$INSTALL_DIR"
FREE_GB=$(df -Pk /var/lib 2> /dev/null | awk 'NR==2 {print int($4/1024/1024)}')
ok "$PRETTY_NAME · $ARCH · $CPUS CPU · $((MEM_MB / 1024)).$(((MEM_MB % 1024) * 10 / 1024)) GB RAM · $FREE_GB GB free"

# Prebuilt images are published for amd64; other machines build them here.
if ((FORCE_BUILD == 0)) && [[ "$ARCH" == amd64 && "$REGISTRY" != none ]]; then
  MODE=pull; NEED_GB=8
else
  MODE=build; NEED_GB=14
fi
EXISTING=0
if [[ -f "$INSTALL_DIR/docker-compose.yml" ]]; then EXISTING=1; fi
if ((FREE_GB < NEED_GB)); then
  if ((EXISTING)); then
    warn "Only $FREE_GB GB of disk is free; new images need about $NEED_GB GB."
  else
    die "Only $FREE_GB GB of disk is free; Acacia Radio needs about $NEED_GB GB."
  fi
fi
if ((MEM_MB < 1900)); then warn "Only $MEM_MB MB of RAM; 2 GB or more is recommended."; fi

if ! docker ps --format '{{.Names}}' 2> /dev/null | grep -qx acacia; then
  for p in "$HTTP_PORT" "$HTTPS_PORT"; do
    if ss -Hltn "sport = :$p" 2> /dev/null | grep -q .; then
      die "Port $p is already in use by $(ss -Hltnp "sport = :$p" | grep -o 'users:(("[^"]*' | cut -d'"' -f2 | head -1)." \
        "Stop that service, or install on other ports with --http-port and --https-port."
    fi
  done
fi
ok "Ports $HTTP_PORT and $HTTPS_PORT are available"

PRIMARY_DOMAIN="${DOMAIN%%,*}"
if [[ -z "$EMAIL" && -n "$DOMAIN" ]]; then EMAIL="admin@$PRIMARY_DOMAIN"; fi

section "Plan"
note "Install directory   $INSTALL_DIR"
note "Release             ${REF:-the newest one}"
if [[ $MODE == pull ]]; then
  note "Images              download (a few minutes)"
else
  note "Images              build on this server (15-40 min)"
fi
note "Ports               HTTP $HTTP_PORT, HTTPS $HTTPS_PORT"
NO_DOMAIN="this server's IP address (add a domain later)"
note "Address             ${DOMAIN:-$NO_DOMAIN}"
if ((ASSUME_YES == 0)) && [[ -t 0 ]]; then
  printf '\n  Continue? [Y/n] '
  read -r answer
  [[ -z "$answer" || "$answer" =~ ^[Yy] ]] || { printf '  Cancelled.\n\n'; exit 0; }
fi

# ------------------------------------------------------------------------------------------------ 2. docker

section "Preparing the server"
export DEBIAN_FRONTEND=noninteractive

install_docker() {
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl gnupg git iproute2 openssl
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL "https://download.docker.com/linux/$ID/gpg" -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  echo "deb [arch=$ARCH signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/$ID ${VERSION_CODENAME} stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -qq
  apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
}
start_docker() {
  if command -v systemctl > /dev/null && [[ -d /run/systemd/system ]]; then
    systemctl enable --now docker
  else
    # No systemd (containers, some minimal images): run the daemon detached from this session.
    setsid nohup dockerd > /var/log/dockerd.log 2>&1 < /dev/null &
  fi
  for _ in $(seq 1 30); do
    if docker info > /dev/null 2>&1; then return 0; fi
    sleep 1
  done
  echo "The Docker daemon didn't start; see journalctl -u docker or /var/log/dockerd.log." >&2
  return 1
}

if command -v docker > /dev/null && docker compose version > /dev/null 2>&1 && docker buildx version > /dev/null 2>&1; then
  ok "Docker $(docker --version | awk '{print $3}' | tr -d ,) is installed"
else
  DONE="Installed Docker" run "Installing Docker" install_docker
fi
if ! command -v git > /dev/null; then run "Installing git" apt-get install -y -qq git; fi
if ! command -v openssl > /dev/null; then run "Installing openssl" apt-get install -y -qq openssl; fi
if ! docker info > /dev/null 2>&1; then DONE="Docker is running" run "Starting Docker" start_docker; fi

# docker-compose.yml uses optional env files, which need Compose 2.24 or newer.
COMPOSE_VERSION=$(docker compose version --short 2> /dev/null | sed 's/^v//')
if [[ "$(printf '%s\n2.24.0\n' "$COMPOSE_VERSION" | sort -V | head -1)" != 2.24.0 ]]; then
  DONE="Updated Docker Compose" run "Updating Docker Compose ($COMPOSE_VERSION is too old)" \
    bash -c 'apt-get update -qq && apt-get install -y -qq docker-compose-plugin'
fi

# ------------------------------------------------------------------------------------------------ 3. source

GIT=(git)
if [[ -n "${RETAIL_GIT_TOKEN:-}" ]]; then
  # The token goes through git's credential protocol, never into a URL.
  GIT=(git -c credential.helper= -c "credential.helper=!f() { echo username=x-access-token; echo password=\$RETAIL_GIT_TOKEN; }; f")
fi
get_source() {
  if [[ -d "$SRC_DIR/.git" ]]; then
    "${GIT[@]}" -C "$SRC_DIR" fetch --quiet --tags --force origin
  else
    # Partial clone: the history and tags (for updates), but file contents only for the release checked out.
    "${GIT[@]}" clone --quiet --filter=blob:none --no-checkout "$SOURCE" "$SRC_DIR"
  fi
  if [[ -n "${RETAIL_GIT_TOKEN:-}" ]]; then
    local cred="$SRC_DIR/.git/retail-credentials"
    (umask 077; printf 'https://x-access-token:%s@github.com\n' "$RETAIL_GIT_TOKEN" > "$cred")
    git -C "$SRC_DIR" config credential.helper "store --file=$cred"
  fi
}
DONE="Downloaded the source" run "Downloading Acacia Radio" get_source

if [[ -z "$REF" ]]; then
  REF=$(git -C "$SRC_DIR" tag -l 'v*-retail.*' --sort=-v:refname | head -n 1)
  [[ -n "$REF" ]] || die "No release found in $SOURCE." "Pass one with --ref."
fi
if git -C "$SRC_DIR" rev-parse --quiet --verify "refs/tags/$REF" > /dev/null; then
  IMAGE="acacia-radio:${REF#v}"; CHECKOUT="$REF"
elif git -C "$SRC_DIR" rev-parse --quiet --verify "origin/$REF" > /dev/null; then
  # A branch: no prebuilt images.
  CHECKOUT="origin/$REF"; IMAGE="acacia-radio:$(git -C "$SRC_DIR" rev-parse --short "$CHECKOUT")"; MODE=build
else
  die "Unknown release: $REF"
fi
git -C "$SRC_DIR" checkout --quiet --detach "$CHECKOUT" >> "$LOG" 2>&1 || die "Couldn't check out $REF; see $LOG."
VERSION_LABEL="${REF#v}"
ok "Release $VERSION_LABEL" "$(git -C "$SRC_DIR" log -1 --format=%cd --date=short)"

# ------------------------------------------------------------------------------------------------ 4. images

section "Getting the images"
WORKER_IMAGE="$("$SRC_DIR/util/music_downloader_tag.sh" "$IMAGE")"
# Chained with &&: this runs inside an "if", where bash ignores set -e.
pull_images() {
  local version="${IMAGE##*:}"
  docker pull "$REGISTRY/acacia-radio:$version" \
    && docker pull "$REGISTRY/acacia-radio-music-downloader:$version" \
    && docker tag "$REGISTRY/acacia-radio:$version" "$IMAGE" \
    && docker tag "$REGISTRY/acacia-radio-music-downloader:$version" "$WORKER_IMAGE" \
    && { docker rmi "$REGISTRY/acacia-radio:$version" "$REGISTRY/acacia-radio-music-downloader:$version" > /dev/null || true; }
}
build_images() {
  BUILD_ATTEMPTS="${BUILD_ATTEMPTS:-3}" "$SRC_DIR/util/build_image.sh" "$IMAGE"
}

if docker image inspect "$IMAGE" > /dev/null 2>&1 && docker image inspect "$WORKER_IMAGE" > /dev/null 2>&1; then
  ok "The images for $VERSION_LABEL are already here"
elif [[ $MODE == pull ]] && SOFT=1 DONE="Downloaded the images" run "Downloading the images (about 1.5 GB)" pull_images; then
  :
else
  if [[ $MODE == pull ]]; then warn "No prebuilt images for $VERSION_LABEL; building them here instead."; fi
  DONE="Built the images" run "Building the images (15-40 min)" build_images
fi

# ------------------------------------------------------------------------------------------------ 5. files

section "Setting up $INSTALL_DIR"
cd "$INSTALL_DIR"
set_env() { # FILE KEY VALUE: replace or append KEY=VALUE
  if grep -q "^$2=" "$1"; then sed -i "s#^$2=.*#$2=$3#" "$1"; else echo "$2=$3" >> "$1"; fi
}
secret() { openssl rand -hex "$1"; }

for f in docker-compose.yml docker.sh; do
  src="$SRC_DIR/$f"
  if [[ $f == docker-compose.yml ]]; then src="$SRC_DIR/docker-compose.retail.yml"; fi
  if [[ -f "$f" ]] && ! cmp -s "$f" "$src"; then
    cp "$f" "$f.bak-$(date +%Y%m%d%H%M%S)"
    note "Kept your previous $f as $f.bak-*"
  fi
done
cp "$SRC_DIR/docker-compose.retail.yml" docker-compose.yml
cp "$SRC_DIR/docker.sh" docker.sh && chmod +x docker.sh

if [[ ! -f .env ]]; then cp "$SRC_DIR/sample.env" .env; fi
set_env .env ACACIA_HTTP_PORT "$HTTP_PORT"
set_env .env ACACIA_HTTPS_PORT "$HTTPS_PORT"
set_env .env ACACIA_IMAGE "$IMAGE"
set_env .env MUSIC_DOWNLOADER_IMAGE "$WORKER_IMAGE"
set_env .env RETAIL_SOURCE_DIR "$SRC_DIR"
if [[ ! -f acacia.env ]]; then
  # A new acacia.env gets a new database password, which an existing database won't accept.
  PROJECT=$(sed -n 's/^COMPOSE_PROJECT_NAME=//p' .env | tail -1)
  PROJECT="${PROJECT:-acacia}"
  if docker volume inspect "${PROJECT}_db_data" > /dev/null 2>&1; then
    die "There's a database from an earlier install (volume ${PROJECT}_db_data), but no acacia.env with its password." \
      "Put the earlier acacia.env back in $INSTALL_DIR, or remove the old data first: docker volume rm ${PROJECT}_db_data"
  fi
  cp "$SRC_DIR/acacia.sample.env" acacia.env
  set_env acacia.env MYSQL_PASSWORD "$(secret 16)"
  chmod 600 acacia.env
fi
if [[ ! -s music-downloader.env ]]; then
  MD_TOKEN="$(secret 32)"
  (umask 077; printf '# Shared secret between Acacia Radio and the Music Downloader worker.\nMUSIC_DOWNLOADER_WORKER_TOKEN=%s\nMD_WORKER_TOKEN=%s\n' "$MD_TOKEN" "$MD_TOKEN" > music-downloader.env)
fi
docker compose config -q >> "$LOG" 2>&1 || die "The generated docker-compose.yml is invalid; see $LOG."
ok "Configuration written" "docker-compose.yml · .env · acacia.env · music-downloader.env"

# ------------------------------------------------------------------------------------------------ 6. start

section "Starting Acacia Radio"
start_stack() { docker compose up -d --remove-orphans; }
wait_ready() {
  local status=""
  for _ in $(seq 1 120); do
    status=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$HTTP_PORT/api/status" || true)
    if [[ "$status" == 200 ]]; then return 0; fi
    sleep 3
  done
  echo "Acacia Radio didn't answer on port $HTTP_PORT (last HTTP status: ${status:-none})."
  docker compose logs --tail 60 web
  return 1
}
DONE="Containers started" run "Starting the containers" start_stack
DONE="Acacia Radio is up" run "Waiting for the first start (sets up the database, 1-3 min)" wait_ready

CLI=(docker compose exec -T --user=acacia web acacia_cli)
# Only HTTP and HTTPS are published, so players reach the streams through /listen/.
"${CLI[@]}" acacia:settings:set use_radio_proxy true >> "$LOG" 2>&1 || warn "Couldn't turn on the radio proxy; see $LOG."

# ------------------------------------------------------------------------------------------------ 7. domain

if [[ -n "$DOMAIN" ]]; then
  section "Domain and HTTPS"
  {
    "${CLI[@]}" acacia:settings:set base_url "https://$PRIMARY_DOMAIN"
    "${CLI[@]}" acacia:settings:set acme_domains "$DOMAIN"
    "${CLI[@]}" acacia:settings:set acme_email "$EMAIL"
  } >> "$LOG" 2>&1 || warn "Couldn't save the domain settings; see $LOG."
  get_certificate() { "${CLI[@]}" acacia:acme:get-certificate; }
  if SOFT=1 DONE="HTTPS certificate for $DOMAIN" run "Getting an HTTPS certificate" get_certificate; then
    URL="https://$PRIMARY_DOMAIN"
  else
    URL="http://$PRIMARY_DOMAIN"
    note "Check that $PRIMARY_DOMAIN points to this server and port 80 is open, then run:"
    note "cd $INSTALL_DIR && docker compose exec --user=acacia web acacia_cli acacia:acme:get-certificate"
  fi
else
  IP=$(curl -s -m 5 https://api.ipify.org 2> /dev/null || true)
  [[ "$IP" =~ ^[0-9.]+$ ]] || IP=$(hostname -I 2> /dev/null | awk '{print $1}')
  URL="http://$IP"
  if [[ "$HTTP_PORT" != 80 ]]; then URL="$URL:$HTTP_PORT"; fi
fi

# ------------------------------------------------------------------------------------------------ done

box() { printf '  %s│%s  %-58s%s│%s\n' "$GREEN" "$R" "$1" "$GREEN" "$R"; }
APP_SIZE=$(docker image inspect "$IMAGE" --format '{{.Size}}' | awk '{printf "%.1f GB", $1 / 1e9}')
printf '\n  %s╭────────────────────────────────────────────────────────────╮%s\n' "$GREEN" "$R"
box "Acacia Radio $VERSION_LABEL is ready"
box ""
box "Open $URL"
box "and create the administrator account and first station."
box ""
box "Installed in $(took "$START_ALL"), image $APP_SIZE"
printf '  %s╰────────────────────────────────────────────────────────────╯%s\n\n' "$GREEN" "$R"
printf '  %sIn %s:%s\n' "$D" "$INSTALL_DIR" "$R"
printf '    ./docker.sh update        update to the newest release\n'
printf '    ./docker.sh backup        back up the database, settings and media\n'
printf '    ./docker.sh rollback      go back to the version before the last update\n'
printf '    docker compose logs -f    follow the logs\n\n'
printf '  %sInstall log: %s%s\n\n' "$D" "$LOG" "$R"
