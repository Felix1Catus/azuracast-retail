#!/usr/bin/env bash
#
# AzuraCast Retail: one-command install on a fresh Ubuntu/Debian server.
#
#   sudo bash install_retail.sh [options]
#
# What it does (each step is skipped when already done, so it is safe to run again):
#   1. checks the machine (root, OS, disk, memory, ports)
#   2. installs Docker Engine, the Compose and Buildx plugins and git (Docker's official apt repository)
#   3. clones or updates the source checkout and checks out a release
#   4. builds the image (util/build_image.sh)
#   5. writes the install directory (docker-compose.yml, docker.sh, .env, azuracast.env, music-downloader.env)
#   6. starts AzuraCast and waits until it answers
#   7. with --domain: sets the site URL and gets a Let's Encrypt certificate
#
# Options:
#   --dir PATH          install directory (default /var/azuracast)
#   --src-dir PATH      source checkout (default /opt/azuracast-retail)
#   --source URL|PATH   git source (default https://github.com/Felix1Catus/azuracast-retail.git)
#   --ref REF           release tag, tag or branch (default: newest v*-retail.* tag)
#   --domain NAMES      public domain(s), comma-separated (first = site URL); enables HTTPS via Let's Encrypt
#                       (DNS must already point here), e.g. --domain example.com,www.example.com
#   --email ADDRESS     Let's Encrypt contact address (default admin@<domain>)
#   --http-port N       HTTP port (default 80)
#   --https-port N      HTTPS port (default 443)
#   --yes               don't ask for confirmation
#
# The repository is public; no credentials are needed. For a private copy, set RETAIL_GIT_TOKEN to a read-only
# GitHub token before running; it is stored for `./docker.sh update` in <src-dir>/.git/retail-credentials (mode 600).
#
set -euo pipefail

INSTALL_DIR=/var/azuracast
SRC_DIR=/opt/azuracast-retail
SOURCE=https://github.com/Felix1Catus/azuracast-retail.git
REF=""
DOMAIN=""
EMAIL=""
HTTP_PORT=80
HTTPS_PORT=443
ASSUME_YES=0

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
    --yes|-y) ASSUME_YES=1; shift ;;
    -h|--help) sed -n '2,32p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1 (see --help)"; exit 1 ;;
  esac
done

STEP=0
step() { STEP=$((STEP + 1)); printf '\n\033[1;36m[%d/7] %s\033[0m\n' "$STEP" "$*"; }
info() { printf '      %s\n' "$*"; }
warn() { printf '\033[1;33m      ! %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31m      x %s\033[0m\n' "$*"; exit 1; }
ok()   { printf '\033[1;32m      ✓ %s\033[0m\n' "$*"; }

# ------------------------------------------------------------------------------------------------ 1. checks
step "Checking this machine"
[[ $EUID -eq 0 ]] || fail "Run as root (sudo bash $0)."
# shellcheck disable=SC1091
. /etc/os-release
case "${ID:-}" in
  ubuntu|debian) ok "$PRETTY_NAME" ;;
  *) fail "Unsupported OS '${PRETTY_NAME:-unknown}'. Ubuntu or Debian is required." ;;
esac
ARCH=$(dpkg --print-architecture)
[[ "$ARCH" == amd64 || "$ARCH" == arm64 ]] || fail "Unsupported architecture: $ARCH"
ok "Architecture $ARCH"

mkdir -p "$(dirname "$SRC_DIR")" "$INSTALL_DIR"
FREE_GB=$(df -Pk "$(dirname "$SRC_DIR")" | awk 'NR==2 {print int($4/1024/1024)}')
if (( FREE_GB < 12 )); then
  if [[ -f "$INSTALL_DIR/docker-compose.yml" ]]; then
    # Re-run on an existing install: the image is usually built already.
    warn "Only ${FREE_GB} GB free; building a new image needs about 12 GB (an existing image is reused)."
  else
    fail "Only ${FREE_GB} GB free; the image build needs about 12 GB."
  fi
else
  ok "${FREE_GB} GB free disk"
fi
MEM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
(( MEM_MB >= 1900 )) || warn "Only ${MEM_MB} MB RAM; 2 GB or more is recommended."
ok "${MEM_MB} MB RAM"

if ! docker ps --format '{{.Names}}' 2>/dev/null | grep -qx azuracast; then
  for p in "$HTTP_PORT" "$HTTPS_PORT"; do
    if ss -Hltn "sport = :$p" 2>/dev/null | grep -q .; then
      fail "Port $p is already in use ($(ss -Hltnp "sport = :$p" | awk '{print $NF}' | head -1)). Stop that service or use --http-port/--https-port."
    fi
  done
  ok "Ports $HTTP_PORT and $HTTPS_PORT are free"
fi

PRIMARY_DOMAIN="${DOMAIN%%,*}"
if [[ -z "$EMAIL" && -n "$DOMAIN" ]]; then
  EMAIL="admin@$PRIMARY_DOMAIN"
fi
echo
info "Install directory : $INSTALL_DIR"
info "Source checkout   : $SRC_DIR  (from $SOURCE)"
info "Release           : ${REF:-newest release}"
info "Ports             : HTTP $HTTP_PORT, HTTPS $HTTPS_PORT"
info "Domain            : ${DOMAIN:-none (set it later in Administration > System Settings)}"
if [[ $ASSUME_YES -ne 1 ]]; then
  read -r -p "      Continue? [Y/n] " answer
  [[ -z "$answer" || "$answer" =~ ^[Yy] ]] || exit 0
fi

# ------------------------------------------------------------------------------------------------ 2. docker
step "Installing Docker"
export DEBIAN_FRONTEND=noninteractive
if command -v docker >/dev/null && docker compose version >/dev/null 2>&1 && docker buildx version >/dev/null 2>&1; then
  ok "$(docker --version | cut -d, -f1) is already installed"
else
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl gnupg git iproute2 >/dev/null
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL "https://download.docker.com/linux/$ID/gpg" -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  echo "deb [arch=$ARCH signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/$ID ${VERSION_CODENAME} stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -qq
  apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin >/dev/null
  ok "Docker installed"
fi
if ! command -v git >/dev/null; then
  apt-get install -y -qq git >/dev/null
  ok "git installed"
fi

if ! docker info >/dev/null 2>&1; then
  if command -v systemctl >/dev/null && systemctl list-unit-files docker.service >/dev/null 2>&1 && [[ -d /run/systemd/system ]]; then
    systemctl enable --now docker >/dev/null
  else
    # No systemd (containers, some minimal images): run the daemon directly, detached from this session so a
    # closed terminal or Ctrl-C can't signal it.
    setsid nohup dockerd > /var/log/dockerd.log 2>&1 < /dev/null &
  fi
  for _ in $(seq 1 30); do docker info >/dev/null 2>&1 && break; sleep 1; done
fi
docker info >/dev/null 2>&1 || fail "The Docker daemon is not running (see /var/log/dockerd.log or journalctl -u docker)."
ok "Docker daemon is running"

# ------------------------------------------------------------------------------------------------ 3. source
step "Getting the AzuraCast Retail source"
GIT=(git)
if [[ -n "${RETAIL_GIT_TOKEN:-}" ]]; then
  # The token is passed through git's credential protocol, never put in a URL.
  GIT=(git -c credential.helper= -c "credential.helper=!f() { echo username=x-access-token; echo password=\$RETAIL_GIT_TOKEN; }; f")
fi

if [[ -d "$SRC_DIR/.git" ]]; then
  "${GIT[@]}" -C "$SRC_DIR" fetch --quiet --tags --force origin
  ok "Updated $SRC_DIR"
else
  "${GIT[@]}" clone --quiet "$SOURCE" "$SRC_DIR"
  ok "Cloned into $SRC_DIR"
fi

if [[ -n "${RETAIL_GIT_TOKEN:-}" ]]; then
  CRED="$SRC_DIR/.git/retail-credentials"
  umask 077
  printf 'https://x-access-token:%s@github.com\n' "$RETAIL_GIT_TOKEN" > "$CRED"
  git -C "$SRC_DIR" config credential.helper "store --file=$CRED"
  ok "Saved read access for later updates ($CRED, mode 600)"
fi

if [[ -z "$REF" ]]; then
  REF=$(git -C "$SRC_DIR" tag -l 'v*-retail.*' --sort=-v:refname | head -n 1)
  [[ -n "$REF" ]] || fail "No v*-retail.* release found; pass --ref."
fi
if git -C "$SRC_DIR" rev-parse --quiet --verify "refs/tags/$REF" >/dev/null; then
  IMAGE="azuracast-retail:${REF#v}"
  CHECKOUT="$REF"
elif git -C "$SRC_DIR" rev-parse --quiet --verify "origin/$REF" >/dev/null; then
  CHECKOUT="origin/$REF"
  IMAGE="azuracast-retail:$(git -C "$SRC_DIR" rev-parse --short "$CHECKOUT")"
else
  fail "Unknown release, tag or branch: $REF"
fi
git -C "$SRC_DIR" checkout --quiet --detach "$CHECKOUT"
ok "Checked out $REF ($(git -C "$SRC_DIR" rev-parse --short HEAD))"

# ------------------------------------------------------------------------------------------------ 4. image
step "Building the image $IMAGE (first build: 15-40 min)"
WORKER_IMAGE="$("$SRC_DIR/util/music_downloader_tag.sh" "$IMAGE")"
if docker image inspect "$IMAGE" >/dev/null 2>&1 && docker image inspect "$WORKER_IMAGE" >/dev/null 2>&1; then
  ok "$IMAGE already exists"
else
  BUILD_ATTEMPTS="${BUILD_ATTEMPTS:-3}" "$SRC_DIR/util/build_image.sh" "$IMAGE"
  ok "Built $IMAGE"
fi

# ------------------------------------------------------------------------------------------------ 5. files
step "Writing $INSTALL_DIR"
cd "$INSTALL_DIR"
for f in docker-compose.yml docker.sh; do
  if [[ -f "$f" ]] && ! cmp -s "$f" "$SRC_DIR/${f/docker-compose.yml/docker-compose.retail.yml}"; then
    cp "$f" "$f.bak-$(date +%Y%m%d%H%M%S)"
    info "Kept the previous $f as $f.bak-*"
  fi
done
cp "$SRC_DIR/docker-compose.retail.yml" docker-compose.yml
cp "$SRC_DIR/docker.sh" docker.sh && chmod +x docker.sh

set_env() { # file key value: replace or append KEY=value
  if grep -q "^$2=" "$1"; then sed -i "s#^$2=.*#$2=$3#" "$1"; else echo "$2=$3" >> "$1"; fi
}
[[ -f .env ]] || cp "$SRC_DIR/sample.env" .env
set_env .env AZURACAST_HTTP_PORT "$HTTP_PORT"
set_env .env AZURACAST_HTTPS_PORT "$HTTPS_PORT"
set_env .env AZURACAST_RETAIL_IMAGE "$IMAGE"
set_env .env RETAIL_SOURCE_DIR "$SRC_DIR"
if [[ ! -f azuracast.env ]]; then
  cp "$SRC_DIR/azuracast.sample.env" azuracast.env
  set_env azuracast.env MYSQL_PASSWORD "$(openssl rand -hex 16 2>/dev/null || head -c16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  chmod 600 azuracast.env
  ok "Created azuracast.env with a random database password"
fi
# Music Downloader: worker image and the secret shared by AzuraCast and the worker (docker-compose.retail.yml).
set_env .env MUSIC_DOWNLOADER_IMAGE "$WORKER_IMAGE"
if [[ ! -s music-downloader.env ]]; then
  MD_TOKEN="$(openssl rand -hex 32 2>/dev/null || head -c32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  (umask 077; printf '# Shared secret between AzuraCast and the Music Downloader worker.\nMUSIC_DOWNLOADER_WORKER_TOKEN=%s\nMD_WORKER_TOKEN=%s\n' "$MD_TOKEN" "$MD_TOKEN" > music-downloader.env)
  ok "Created music-downloader.env"
fi
docker compose config -q
ok "docker-compose.yml, docker.sh, .env, azuracast.env and music-downloader.env are in place"

# ------------------------------------------------------------------------------------------------ 6. start
step "Starting AzuraCast"
docker compose up -d --remove-orphans
info "Waiting for AzuraCast to answer (first start sets up the database, about 1-3 min)..."
STATUS=""
for _ in $(seq 1 90); do
  STATUS=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$HTTP_PORT/api/status" || true)
  [[ "$STATUS" == 200 ]] && break
  sleep 4
done
[[ "$STATUS" == 200 ]] || fail "AzuraCast did not answer on port $HTTP_PORT (see: docker compose logs web)."
ok "AzuraCast is running"

CLI=(docker compose exec -T --user=azuracast web azuracast_cli)
# Only HTTP/HTTPS are published, so players must use the /listen/ proxy.
"${CLI[@]}" azuracast:settings:set use_radio_proxy true >/dev/null && ok "Radio proxy (/listen/) on"

# ------------------------------------------------------------------------------------------------ 7. domain
step "Domain and HTTPS"
if [[ -n "$DOMAIN" ]]; then
  "${CLI[@]}" azuracast:settings:set base_url "https://$PRIMARY_DOMAIN" >/dev/null
  "${CLI[@]}" azuracast:settings:set acme_domains "$DOMAIN" >/dev/null
  "${CLI[@]}" azuracast:settings:set acme_email "$EMAIL" >/dev/null
  if "${CLI[@]}" azuracast:acme:get-certificate; then
    ok "Certificate issued for $DOMAIN"
  else
    warn "No certificate yet. Check that $DOMAIN points to this server and port 80 is reachable, then run:"
    warn "  cd $INSTALL_DIR && docker compose exec --user=azuracast web azuracast_cli azuracast:acme:get-certificate"
  fi
else
  info "Skipped (no --domain). Set it later: Administration > System Settings > Services > LetsEncrypt."
fi

if [[ -n "$DOMAIN" ]]; then
  URL="https://$PRIMARY_DOMAIN"
else
  # Public address if the server can find it, else its first local address.
  IP=$(curl -s -m 5 https://api.ipify.org 2>/dev/null || true)
  [[ "$IP" =~ ^[0-9.]+$ ]] || IP=$(hostname -I 2>/dev/null | awk '{print $1}')
  URL="http://$IP"
  [[ "$HTTP_PORT" != 80 ]] && URL="$URL:$HTTP_PORT"
fi
printf '\n\033[1;32mAzuraCast Retail %s is installed.\033[0m\n' "$REF"
info "Open $URL (on a new install: create the admin account and the first station there)."
info "Updates: cd $INSTALL_DIR && ./docker.sh backup && ./docker.sh update"
