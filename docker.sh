#!/usr/bin/env bash
# shellcheck disable=SC2145,SC2178,SC2120,SC2162

PODMAN_MODE=0

# Docker and Docker Compose aliases
d() {
  if [[ $PODMAN_MODE -ne 0 ]]; then
    podman "$@"
  else
    docker "$@"
  fi
}

dc() {
  if [[ $PODMAN_MODE -ne 0 ]]; then
    podman-compose "$@"
  else
    if [[ $(docker compose version) ]]; then
      docker compose "$@"
    else
      docker-compose "$@"
    fi
  fi
}

# Functions to manage .env files
__dotenv=
__dotenv_file=
__dotenv_cmd=.env

.env() {
  REPLY=()
  [[ $__dotenv_file || ${1-} == -* ]] || .env.--file .env || return
  if declare -F -- ".env.${1-}" >/dev/null; then
    .env."$@"
    return
  fi
  return 64
}

.env.-f() { .env.--file "$@"; }

.env.get() {
  .env::arg "get requires a key" "$@" &&
    [[ "$__dotenv" =~ ^(.*(^|$'\n'))([ ]*)"$1="(.*)$ ]] &&
    REPLY=${BASH_REMATCH[4]%%$'\n'*} && REPLY=${REPLY%"${REPLY##*[![:space:]]}"}
}

.env.parse() {
  local line key
  while IFS= read -r line; do
    line=${line#"${line%%[![:space:]]*}"} # trim leading whitespace
    line=${line%"${line##*[![:space:]]}"} # trim trailing whitespace
    if [[ ! "$line" || "$line" == '#'* ]]; then continue; fi
    if (($#)); then
      for key; do
        if [[ $key == "${line%%=*}" ]]; then
          REPLY+=("$line")
          break
        fi
      done
    else
      REPLY+=("$line")
    fi
  done <<<"$__dotenv"
  ((${#REPLY[@]}))
}

.env.export() { ! .env.parse "$@" || export "${REPLY[@]}"; }

.env.set() {
  .env::file load || return
  local key saved=$__dotenv
  while (($#)); do
    key=${1#+}
    key=${key%%=*}
    if .env.get "$key"; then
      REPLY=()
      if [[ $1 == +* ]]; then
        shift
        continue # skip if already found
      elif [[ $1 == *=* ]]; then
        __dotenv=${BASH_REMATCH[1]}${BASH_REMATCH[3]}$1$'\n'${BASH_REMATCH[4]#*$'\n'}
      else
        __dotenv=${BASH_REMATCH[1]}${BASH_REMATCH[4]#*$'\n'}
        continue # delete all occurrences
      fi
    elif [[ $1 == *=* ]]; then
      __dotenv+="${1#+}"$'\n'
    fi
    shift
  done
  [[ $__dotenv == "$saved" ]] || .env::file save
}

.env.puts() { echo "${1-}" >>"$__dotenv_file" && __dotenv+="$1"$'\n'; }

.env.generate() {
  .env::arg "key required for generate" "$@" || return
  .env.get "$1" && return || REPLY=$("${@:2}") || return
  .env::one "generate: ouptut of '${*:2}' has more than one line" "$REPLY" || return
  .env.puts "$1=$REPLY"
}

.env.--file() {
  .env::arg "filename required for --file" "$@" || return
  __dotenv_file=$1
  .env::file load || return
  (($# < 2)) || .env "${@:2}"
}

.env::arg() { [[ "${2-}" ]] || {
  echo "$__dotenv_cmd: $1" >&2
  return 64
}; }

.env::one() { [[ "$2" != *$'\n'* ]] || .env::arg "$1"; }

.env::file() {
  local REPLY=$__dotenv_file
  case "$1" in
  load)
    __dotenv=
    ! [[ -f "$REPLY" ]] || __dotenv="$(<"$REPLY")"$'\n' || return
    ;;
  save)
    if [[ -L "$REPLY" ]] && declare -F -- realpath.resolved >/dev/null; then
      realpath.resolved "$REPLY"
    fi
    { [[ ! -f "$REPLY" ]] || cp -p "$REPLY" "$REPLY.bak"; } &&
      printf %s "$__dotenv" >"$REPLY.bak" && mv "$REPLY.bak" "$REPLY"
    ;;
  esac
}

# Shortcut to convert semver version (x.yyy.zzz) into a comparable number.
version-number() {
  echo "$@" | awk -F. '{ printf("%03d%03d%03d\n", $1,$2,$3); }'
}

# Get the current release channel for Acacia Radio
get-release-channel() {
  local ACACIA_VERSION="latest"
  if [[ -f .env ]]; then
    .env --file .env get ACACIA_VERSION
    ACACIA_VERSION="${REPLY:-latest}"
  fi

  echo "$ACACIA_VERSION"
}

get-release-branch-name() {
  if [[ $(get-release-channel) == "stable" ]]; then
    echo "stable"
  else
    echo "main"
  fi
}

# This is a general-purpose function to ask Yes/No questions in Bash, either
# with or without a default answer. It keeps repeating the question until it
# gets a valid answer.
ask() {
  # https://djm.me/ask
  local prompt default reply

  while true; do

    if [[ "${2:-}" == "Y" ]]; then
      prompt="Y/n"
      default=Y
    elif [[ "${2:-}" == "N" ]]; then
      prompt="y/N"
      default=N
    else
      prompt="y/n"
      default=
    fi

    # Ask the question (not using "read -p" as it uses stderr not stdout)
    echo -n "$1 [$prompt] "

    read reply

    # Default?
    if [[ -z "$reply" ]]; then
      reply=${default}
    fi

    # Check if the reply is valid
    case "$reply" in
    Y* | y*) return 0 ;;
    N* | n*) return 1 ;;
    esac

  done
}

# Generate a prompt to set an environment file value.
envfile-set() {
  local VALUE INPUT

  .env --file .env

  .env get "$1"
  VALUE=${REPLY:-$2}

  echo -n "$3 [$VALUE]: "
  read INPUT

  VALUE=${INPUT:-$VALUE}

  .env set "${1}=${VALUE}"
}

#
# Configure the ports used by Acacia Radio.
#
setup-ports() {
  envfile-set "ACACIA_HTTP_PORT" "80" "Port to use for HTTP connections"
  envfile-set "ACACIA_HTTPS_PORT" "443" "Port to use for HTTPS connections"
  envfile-set "ACACIA_SFTP_PORT" "2022" "Port to use for SFTP connections"
}

#
# Configure release mode settings.
#
setup-release() {
  if [[ ! -f .env ]]; then
    curl -fsSL https://raw.githubusercontent.com/AzuraCast/AzuraCast/main/sample.env -o .env
  fi

  local OLD_RELEASE_CHANNEL
  .env --file .env get ACACIA_VERSION
  OLD_RELEASE_CHANNEL="${REPLY:-latest}"

  local ACACIA_VERSION="${OLD_RELEASE_CHANNEL}"

  if [[ ! -z "${1}" ]]; then
    echo "Setting release channel to the specific value: ${1}"
    ACACIA_VERSION="${1}"
  elif [[ $ACACIA_VERSION == "latest" ]]; then
    if ask "Your current release channel is 'Rolling Release'. Switch to 'Stable' release channel?" N; then
      ACACIA_VERSION="stable"
    fi
  elif [[ $ACACIA_VERSION == "stable" ]]; then
    if ask "Your current release channel is 'Stable'. Switch to 'Rolling Release' release channel?" N; then
      ACACIA_VERSION="latest"
    fi
  else
    if ask "Your current release channel is locked to a stable release, version '${OLD_RELEASE_CHANNEL}'. Switch to the 'Stable' release channel?" N; then
      ACACIA_VERSION="stable"
    fi
  fi

  .env --file .env set ACACIA_VERSION=${ACACIA_VERSION}

  if [[ $ACACIA_VERSION != $OLD_RELEASE_CHANNEL ]]; then
    if ask "You should update the Docker Utility Script after changing release channels. Automatically update it now?" Y; then
      update-self
    fi
  fi
}

check-install-requirements() {
  local CURRENT_OS CURRENT_ARCH REQUIRED_COMMANDS SCRIPT_DIR

  set -e

  echo "Checking installation requirements for Acacia Radio..."

  CURRENT_OS=$(uname -s)
  if [[ $CURRENT_OS == "Linux" ]]; then
    echo -en "\e[32m[PASS]\e[0m Operating System: ${CURRENT_OS}\n"
  elif [[ $CURRENT_OS == "Darwin" ]]; then
    echo -en "\e[32m[PASS]\e[0m Operating System: ${CURRENT_OS}\n"
  else
    echo -en "\e[41m[FAIL]\e[0m Operating System: ${CURRENT_OS}\n"

    echo "       You are running an unsupported operating system."
    echo "       Automated Acacia Radio installation is not currently supported on this"
    echo "       operating system."
    exit 1
  fi

  CURRENT_ARCH=$(uname -m)
  if [[ $CURRENT_ARCH == "x86_64" ]]; then
    echo -en "\e[32m[PASS]\e[0m Architecture: ${CURRENT_ARCH}\n"
  elif [[ $CURRENT_ARCH == "aarch64" ]]; then
    echo -en "\e[32m[PASS]\e[0m Architecture: ${CURRENT_ARCH}\n"
  else
    echo -en "\e[41m[FAIL]\e[0m Architecture: ${CURRENT_ARCH}\n"

    echo "       You are running an unsupported processor architecture."
    echo "       Automated Acacia Radio installation is not currently supported on this "
    echo "       operating system."
    exit 1
  fi

  REQUIRED_COMMANDS=(curl awk)
  for COMMAND in "${REQUIRED_COMMANDS[@]}" ; do
    if [[ $(command -v "$COMMAND") ]]; then
      echo -en "\e[32m[PASS]\e[0m Command Present: ${COMMAND}\n"
    else
      echo -en "\e[41m[FAIL]\e[0m Command Present: ${COMMAND}\n"

      echo "       ${COMMAND} does not appear to be installed."
      echo "       Install ${COMMAND} using your host's package manager,"
      echo "       then continue installing using this script."
      exit 1
    fi
  done

  if [[ $EUID -ne 0 ]]; then
    if [[ $(command -v sudo) ]]; then
      echo -en "\e[32m[PASS]\e[0m User Permissions\n"
    else
      echo -en "\e[41m[FAIL]\e[0m User Permissions\n"

      echo "       You are not currently the root user, and "
      echo "       'sudo' does not appear to be installed."
      echo "       Install sudo using your host's package manager,"
      echo "       then continue installing using this script."
      exit 1
    fi
  else
    echo -en "\e[32m[PASS]\e[0m User Permissions\n"
  fi

  SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
  if [[ $SCRIPT_DIR == "/var/acacia" ]]; then
    echo -en "\e[32m[PASS]\e[0m Installation Directory\n"
  else
    echo -en "\e[93m[WARN]\e[0m Installation Directory\n"
    echo "       Acacia Radio is not installed in /var/acacia, as is recommended"
    echo "       for most installations. This will not prevent Acacia Radio from"
    echo "       working, but you will need to update any instructions in our"
    echo "       documentation to reflect your current directory:"
    echo "       $SCRIPT_DIR"
  fi

  echo -en "\e[32m[PASS]\e[0m All requirements met!\n"

  set +e
}

install-docker() {
  set -e

  CURRENT_OS=$(uname -s)
  if [[ $CURRENT_OS != "Linux" ]]; then
    echo "The automatic Docker installation can only take place on Linux."
    echo "Install Docker Desktop for your operating system."
    exit 1
  fi

  curl -fsSL get.docker.com -o get-docker.sh
  sh get-docker.sh
  rm get-docker.sh

  if [[ $EUID -ne 0 ]]; then
    sudo usermod -aG docker "$(whoami)"

    echo "You must log out or restart to apply necessary Docker permissions changes."
    echo "Restart, then continue installing using this script."
    exit 1
  fi

  set +e
}

install-docker-compose() {
  set -e
  local DOCKER_COMPOSE_VERSION
  echo "Installing Docker Compose..."

  CURRENT_OS=$(uname -s)
  if [[ $CURRENT_OS != "Linux" ]]; then
    echo "The automatic Docker installation can only take place on Linux."
    echo "Install Docker Desktop for your operating system."
    exit 1
  fi

  DOCKER_COMPOSE_VERSION=$(version-number $(docker compose version --short || echo "0.0.0"))
  if (( $DOCKER_COMPOSE_VERSION >= $(version-number "5.0.0") )); then
    echo "Docker Compose is already installed and newer than version 5.0.0. No update needed."
    set +e
  else
    curl -fsSL -o docker-compose https://github.com/docker/compose/releases/download/v5.0.0/docker-compose-linux-$(uname -m)

    ARCHITECTURE=amd64
    if [ "$(uname -m)" = "aarch64" ]; then
      ARCHITECTURE=arm64
    fi
    curl -fsSL -o docker-compose-switch https://github.com/docker/compose-switch/releases/download/v1.0.5/docker-compose-linux-${ARCHITECTURE}

    if [[ $EUID -ne 0 ]]; then
      sudo chmod a+x ./docker-compose
      sudo chmod a+x ./docker-compose-switch

      sudo mv ./docker-compose /usr/libexec/docker/cli-plugins/docker-compose
      sudo mv ./docker-compose-switch /usr/local/bin/docker-compose
    else
      chmod a+x ./docker-compose
      chmod a+x ./docker-compose-switch

      mv ./docker-compose /usr/libexec/docker/cli-plugins/docker-compose
      mv ./docker-compose-switch /usr/local/bin/docker-compose
    fi

    echo "Docker Compose updated!"
    set +e
  fi
}

run-installer() {
  local ACACIA_RELEASE_BRANCH
  ACACIA_RELEASE_BRANCH=$(get-release-branch-name)

  if [[ ! -f .env ]]; then
    curl -fsSL https://raw.githubusercontent.com/AzuraCast/AzuraCast/$ACACIA_RELEASE_BRANCH/sample.env -o .env
  fi
  if [[ ! -f acacia.env ]]; then
    curl -fsSL https://raw.githubusercontent.com/AzuraCast/AzuraCast/$ACACIA_RELEASE_BRANCH/acacia.sample.env -o acacia.env
  fi
  if [[ ! -f docker-compose.yml ]]; then
    curl -fsSL https://raw.githubusercontent.com/AzuraCast/AzuraCast/$ACACIA_RELEASE_BRANCH/docker-compose.sample.yml -o docker-compose.yml
  fi

  touch docker-compose.new.yml

  local dc_config_test=$(dc -f docker-compose.new.yml config 2>/dev/null)
  if [ $? -ne 0 ]; then
    if ask "Docker Compose needs to be updated to continue. Update to latest version?" Y; then
      install-docker-compose
    fi
  fi

  curl -fsSL https://raw.githubusercontent.com/AzuraCast/AzuraCast/$ACACIA_RELEASE_BRANCH/docker-compose.installer.yml -o docker-compose.installer.yml

  dc -p acacia_installer -f docker-compose.installer.yml pull
  dc -p acacia_installer -f docker-compose.installer.yml run --rm installer install "$@"

  rm docker-compose.installer.yml
}

#
# Run the initial installer of Docker and Acacia Radio.
# Usage: ./docker.sh install
#
install() {
  check-install-requirements

  if [[ $PODMAN_MODE -ne 0 ]]; then
    echo "Podman was detected and will be used instead of Docker..."

    if [[ $(command -v podman-compose) ]]; then
      echo "Podman-compose is installed!"
    else
      echo "Podman mode is active, but podman-compose is not found."
      echo "Install it by following the instructions on this page:"
      echo "https://github.com/containers/podman-compose"
      exit 1
    fi
  else
    if [[ $(command -v docker) && $(docker --version) ]]; then
      echo "Docker is already installed! Continuing..."
    else
      if ask "Docker does not appear to be installed. Install Docker now?" Y; then
        install-docker
      fi
    fi

    if [[ $(docker compose version) ]]; then
      echo "Docker Compose v2 is already installed. Continuing..."
    else
      if [[ $(command -v docker-compose) ]]; then
        echo "Docker Compose is already installed. Continuing..."
      else
        if ask "Docker Compose does not appear to be installed. Install Docker Compose now?" Y; then
          install-docker-compose
        fi
      fi
    fi
  fi

  setup-release

  run-installer "$@"

  # Installer creates a file at docker-compose.new.yml; copy it to the main spot.
  if [[ -s docker-compose.new.yml ]]; then
    if [[ -f docker-compose.yml ]]; then
      rm docker-compose.yml
    fi

    mv docker-compose.new.yml docker-compose.yml
  fi

  # If this script is running as a non-root user, set the PUID/PGID in the environment vars appropriately.
  if [[ $EUID -ne 0 ]]; then
    .env --file .env set ACACIA_PUID="$(id -u)"
    .env --file .env set ACACIA_PGID="$(id -g)"
  fi

  if [[ $PODMAN_MODE -ne 0 ]]; then
    .env --file .env set ACACIA_PODMAN_MODE=true
  fi

  dc pull

  dc run --rm web -- acacia_install "$@"
  dc up -d
  exit
}

install-dev() {
  if [[ $(command -v docker) && $(docker --version) ]]; then
    echo "Docker is already installed! Continuing..."
  else
    if ask "Docker does not appear to be installed. Install Docker now?" Y; then
      install-docker
    fi
  fi

  if [[ $(docker compose version) ]]; then
    echo "Docker Compose v2 is already installed. Continuing..."
  else
    if [[ $(command -v docker-compose) ]]; then
      echo "Docker Compose is already installed. Continuing..."
    else
      if ask "Docker Compose does not appear to be installed. Install Docker Compose now?" Y; then
        install-docker-compose
      fi
    fi
  fi

  if [[ ! -f docker-compose.yml ]]; then
    cp docker-compose.sample.yml docker-compose.yml
  fi
  if [[ ! -f docker-compose.override.yml ]]; then
    cp docker-compose.dev.yml docker-compose.override.yml
  fi
  if [[ ! -f .env ]]; then
    cp dev.env .env
  fi
  if [[ ! -f acacia.env ]]; then
    cp acacia.dev.env acacia.env

    echo "Customize acacia.env file now before continuing. Re-run this command to continue installation."
    exit
  fi

  # If this script is running as a non-root user, set the PUID/PGID in the environment vars appropriately.
  if [[ $EUID -ne 0 ]]; then
    .env --file .env set ACACIA_PUID="$(id -u)"
    .env --file .env set ACACIA_PGID="$(id -g)"
  fi

  if [[ $PODMAN_MODE -ne 0 ]]; then
    .env --file .env set ACACIA_PODMAN_MODE=true
  fi

  dc build
  dc run --rm web -- acacia_dev_install "$@"
  dc up -d
  exit
}

#
# Update the Docker images and codebase.
# Usage: ./docker.sh update
#
#
# Acacia Radio updates come from the fork's own repository, never from upstream.
# A retail install keeps a checkout of the source (RETAIL_SOURCE_DIR in .env, default /opt/acacia-radio);
# `update` checks out a release there, builds the image locally and restarts on it. See docs/retail/INSTALL.md.
#
is-retail() {
  # Installs from before the rebrand still have the old image name in docker-compose.yml until `update` migrates them.
  grep -qsE "acacia-radio|azuracast-retail" docker-compose.yml  # rebrand:keep
}

retail-source-dir() {
  .env --file .env
  .env get RETAIL_SOURCE_DIR
  echo "${REPLY:-/opt/acacia-radio}"
}

# Music Downloader (built-in module): point .env at the worker image built with IMAGE, create the shared
# secret if missing, and bring docker-compose.yml up to the template that runs the worker containers.
# Rebrand migration for installs from before it (idempotent; run by `update` before the new version starts):
#  - env file and .env keys get the new names
#  - the database keeps its old name and user, now written down instead of relying on the image default
#  - COMPOSE_PROJECT_NAME stays as it is, so the Docker volumes (and all data) stay where they are
# The old files are kept as *.bak-<date>; rolling back to a version from before the rebrand needs them
# (docs/retail/REBRAND.md).
retail-migrate-rebrand() {
  local OLD=azuracast  # rebrand:keep
  local STAMP
  STAMP=$(date +%Y%m%d%H%M%S)

  if [[ -f "${OLD}.env" && ! -f acacia.env ]]; then
    cp "${OLD}.env" "${OLD}.env.bak-${STAMP}"
    mv "${OLD}.env" acacia.env
    chmod 600 acacia.env
    echo "Renamed ${OLD}.env to acacia.env (backup: ${OLD}.env.bak-${STAMP})."
    if ! grep -qE '^MYSQL_DATABASE=' acacia.env; then
      printf '\n# Database from before the rebrand (kept by ./docker.sh update).\nMYSQL_USER=%s\nMYSQL_DATABASE=%s\n' "$OLD" "$OLD" >> acacia.env
    fi
  fi

  local UPPER_OLD
  UPPER_OLD=$(echo "$OLD" | tr '[:lower:]' '[:upper:]')
  if grep -qE "^${UPPER_OLD}_" .env; then
    cp .env ".env.bak-${STAMP}"
    sed -i -E \
      -e "s/^${UPPER_OLD}_RETAIL_PREVIOUS_IMAGE=/ACACIA_PREVIOUS_IMAGE=/" \
      -e "s/^${UPPER_OLD}_RETAIL_IMAGE=/ACACIA_IMAGE=/" \
      -e "s/^${UPPER_OLD}_/ACACIA_/" .env
    echo "Renamed the ${UPPER_OLD}_* settings in .env to ACACIA_* (backup: .env.bak-${STAMP})."
  fi
}

# Docker volume name prefix = compose project name. Installs from before the rebrand keep
# COMPOSE_PROJECT_NAME=acacia in .env, so their volumes (and data) stay where they are.
volume-prefix() {
  .env --file .env
  .env get COMPOSE_PROJECT_NAME
  echo "${REPLY:-acacia}"
}

retail-music-downloader-setup() {
  local SRC="$1" IMAGE="$2" TOKEN

  .env --file .env set MUSIC_DOWNLOADER_IMAGE="$("$SRC/util/music_downloader_tag.sh" "$IMAGE")"

  if [[ ! -s music-downloader.env ]]; then
    TOKEN="$(openssl rand -hex 32 2>/dev/null || head -c32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    (umask 077; printf '# Shared secret between Acacia Radio and the Music Downloader worker.\nMUSIC_DOWNLOADER_WORKER_TOKEN=%s\nMD_WORKER_TOKEN=%s\n' "$TOKEN" "$TOKEN" > music-downloader.env)
    echo "Created music-downloader.env."
  fi

  if ! cmp -s docker-compose.yml "$SRC/docker-compose.retail.yml"; then
    cp docker-compose.yml "docker-compose.yml.bak-$(date +%Y%m%d%H%M%S)"
    cp "$SRC/docker-compose.retail.yml" docker-compose.yml
    echo "docker-compose.yml updated to this version's template (previous copy kept as docker-compose.yml.bak-*;"
    echo "put local changes in docker-compose.override.yml)."
  fi
}

retail-update() {
  local SRC TARGET IMAGE CURRENT

  SRC=$(retail-source-dir)
  if [[ ! -d "$SRC/.git" ]]; then
    echo "Acacia Radio source not found at $SRC."
    echo "Clone the repository there, or set RETAIL_SOURCE_DIR in .env:"
    echo "  git clone https://github.com/Felix1Catus/azuracast-retail.git $SRC"
    exit 1
  fi

  echo "Fetching releases..."
  git -C "$SRC" fetch --quiet --tags --force origin || exit 1

  # Default: the newest release tag (v<upstream version>-retail.<n>). A tag or branch can be given instead.
  TARGET="${1:-$(git -C "$SRC" tag -l 'v*-retail.*' --sort=-v:refname | head -n 1)}"
  if [[ -z "$TARGET" ]]; then
    echo "No release found. Name a tag or branch: ./docker.sh update main"
    exit 1
  fi

  if git -C "$SRC" rev-parse --quiet --verify "refs/tags/$TARGET" > /dev/null; then
    IMAGE="acacia-radio:${TARGET#v}"
  else
    git -C "$SRC" rev-parse --quiet --verify "origin/$TARGET" > /dev/null || {
      echo "Unknown release, tag or branch: $TARGET"
      exit 1
    }
    TARGET="origin/$TARGET"
    IMAGE="acacia-radio:$(git -C "$SRC" rev-parse --short "$TARGET")"
  fi

  .env --file .env
  .env get ACACIA_IMAGE
  if [[ -z "$REPLY" ]]; then
    .env get AZURACAST_RETAIL_IMAGE  # rebrand:keep (installs from before the rebrand)
  fi
  CURRENT="${REPLY:-acacia-radio:latest}"

  echo "Installed: $CURRENT"
  echo "Update to: $TARGET (image $IMAGE)"
  echo "[NOTICE] Before you continue, please make sure you have a recent backup (./docker.sh backup)."
  if ! ask "Build and switch to this version now?" Y; then
    exit
  fi

  git -C "$SRC" checkout --quiet --detach "$TARGET" || exit 1
  "$SRC/util/build_image.sh" "$IMAGE" || {
    echo "The build failed; the running installation was not changed."
    exit 1
  }

  # Settings from before the rebrand get their new names first, so the keys below aren't written twice.
  retail-migrate-rebrand

  # Re-running the installed version keeps the existing rollback target.
  if [[ "$CURRENT" != "$IMAGE" ]]; then
    .env --file .env set ACACIA_PREVIOUS_IMAGE="$CURRENT"
  fi
  .env --file .env set ACACIA_IMAGE="$IMAGE"
  retail-music-downloader-setup "$SRC" "$IMAGE"
  dc up -d --remove-orphans

  # Keep only the running image and the rollback target; older builds pile up quickly (about 3 GB each).
  local KEEP_PREVIOUS
  .env --file .env
  .env get ACACIA_PREVIOUS_IMAGE
  KEEP_PREVIOUS="$REPLY"
  local LEGACY_REPO=azuracast-retail  # rebrand:keep (images built before the rebrand)
  for OLD in $(d images --format '{{.Repository}}:{{.Tag}}' acacia-radio; d images --format '{{.Repository}}:{{.Tag}}' "$LEGACY_REPO"); do
    if [[ "$OLD" != "$IMAGE" && "$OLD" != "$KEEP_PREVIOUS" ]]; then
      d rmi "$OLD" > /dev/null 2>&1 && echo "Removed old image $OLD"
    fi
  done
  for OLD in $(d images --format '{{.Repository}}:{{.Tag}}' acacia-radio-music-downloader); do
    if [[ "${OLD##*:}" != "${IMAGE##*:}" && "${OLD##*:}" != "${KEEP_PREVIOUS##*:}" ]]; then
      d rmi "$OLD" > /dev/null 2>&1 && echo "Removed old image $OLD"
    fi
  done
  d builder prune -f --keep-storage 4gb > /dev/null 2>&1 || true

  echo "Updated to $TARGET. Database migrations run as the container starts."
  echo "To go back: ./docker.sh rollback"
  exit
}

retail-rollback() {
  local CURRENT PREVIOUS

  .env --file .env
  .env get ACACIA_PREVIOUS_IMAGE
  PREVIOUS="$REPLY"
  .env get ACACIA_IMAGE
  CURRENT="${REPLY:-acacia-radio:latest}"

  if [[ -z "$PREVIOUS" ]]; then
    echo "No previous image recorded; nothing to roll back to."
    exit 1
  fi

  if ask "Switch from $CURRENT back to $PREVIOUS?" Y; then
    .env --file .env set ACACIA_PREVIOUS_IMAGE="$CURRENT"
    .env --file .env set ACACIA_IMAGE="$PREVIOUS"
    # The worker built with the previous version, when it exists (versions before it had none).
    if d image inspect "acacia-radio-music-downloader:${PREVIOUS##*:}" > /dev/null 2>&1; then
      .env --file .env set MUSIC_DOWNLOADER_IMAGE="acacia-radio-music-downloader:${PREVIOUS##*:}"
    fi
    dc up -d
    echo "Now running $PREVIOUS."
  fi
  exit
}

retail-update-self() {
  local SRC
  SRC=$(retail-source-dir)
  cp "$SRC/docker.sh" ./docker.sh.new && chmod a+x ./docker.sh.new && mv ./docker.sh.new ./docker.sh
  echo "docker.sh updated from $SRC."
  exit
}

update() {
  if is-retail; then retail-update "$@"; fi
  echo "[NOTICE] Before you continue, please make sure you have a recent snapshot of your system and or backed it up."
  if ask "Are you ready to continue with the update?" Y; then

    # Check for a new Docker Utility Script.
    local ACACIA_RELEASE_BRANCH
    ACACIA_RELEASE_BRANCH=$(get-release-branch-name)

    curl -fsSL https://raw.githubusercontent.com/AzuraCast/AzuraCast/$ACACIA_RELEASE_BRANCH/docker.sh -o docker.new.sh

    local UTILITY_FILES_MATCH
    UTILITY_FILES_MATCH="$(
      cmp --silent docker.sh docker.new.sh
      echo $?
    )"

    local UPDATE_UTILITY=0
    if [[ ${UTILITY_FILES_MATCH} -ne 0 ]]; then
      if ask "The Docker Utility Script has changed since your version. Update to latest version?" Y; then
        UPDATE_UTILITY=1
      fi
    fi

    if [[ ${UPDATE_UTILITY} -ne 0 ]]; then
      mv docker.new.sh docker.sh
      chmod a+x docker.sh

      echo "A new Docker Utility Script has been downloaded."
      echo "Please re-run the update process to continue."
      exit
    else
      rm docker.new.sh
    fi

    # Check Docker version.
    if [[ $PODMAN_MODE -eq 0 ]]; then
      DOCKER_VERSION=$(docker version -f "{{.Server.Version}}")
      DOCKER_VERSION_MAJOR=$(echo "$DOCKER_VERSION"| cut -d'.' -f 1)

      if [ "${DOCKER_VERSION_MAJOR}" -ge 20 ]; then
        echo "Docker server (version ${DOCKER_VERSION}) meets minimum version requirements."
      else
        if ask "Docker is out of date on this server. Attempt automatic upgrade?" Y; then
          install-docker
          install-docker-compose
        fi
      fi
    fi

    run-installer --update "$@"

    # Check for updated Docker Compose config.
    local COMPOSE_FILES_MATCH

    if [[ ! -s docker-compose.new.yml ]]; then
      curl -fsSL https://raw.githubusercontent.com/AzuraCast/AzuraCast/$ACACIA_RELEASE_BRANCH/docker-compose.sample.yml -o docker-compose.new.yml
    fi

    COMPOSE_FILES_MATCH="$(
      cmp --silent docker-compose.yml docker-compose.new.yml
      echo $?
    )"

    if [[ ${COMPOSE_FILES_MATCH} -ne 0 ]]; then
      dc -f docker-compose.new.yml pull
      dc down --timeout 60

      cp docker-compose.yml docker-compose.backup.yml
      mv docker-compose.new.yml docker-compose.yml
    else
      rm docker-compose.new.yml

      dc pull
      dc down --timeout 60
    fi

    dc run --rm web -- acacia_update "$@"
    dc up -d

    if ask "Clean up all stopped Docker containers and images to save space?" Y; then
      d system prune -f
    fi

    echo "Update complete!"
  fi
  exit
}

#
# Update this Docker utility script.
# Usage: ./docker.sh update-self
#
update-self() {
  if is-retail; then retail-update-self; fi
  local ACACIA_RELEASE_BRANCH
  ACACIA_RELEASE_BRANCH=$(get-release-branch-name)

  curl -H 'Cache-Control: no-cache, no-store' -fsSL \
    https://raw.githubusercontent.com/AzuraCast/AzuraCast/$ACACIA_RELEASE_BRANCH/docker.sh?$(date +%s) \
    -o docker.sh
  chmod a+x docker.sh

  echo "New Docker utility script downloaded."
  echo "You can now re-run any previous command with the updated utility script."
  exit
}

#
# Run a CLI command inside the Docker container.
# Usage: ./docker.sh cli [command]
#
cli() {
  dc exec --user="acacia" web acacia_cli "$@"
  exit
}

#
# Enter the bash terminal of the running web container.
# Usage: ./docker.sh bash
#
bash() {
  dc exec --user="acacia" web bash
  exit
}

#
# Enter the MariaDB database management terminal with the correct credentials.
#
db() {
  dc exec web acacia_db
  exit
}

#
# Back up the Docker volumes to a .tar.gz file.
# Usage:
# ./docker.sh backup [/custom/backup/dir/custombackupname.zip]
#
backup() {
  local BACKUP_PATH BACKUP_DIR BACKUP_FILENAME BACKUP_EXT
  BACKUP_PATH=$(readlink -f ${1:-"./backup.tar.gz"})
  BACKUP_DIR=$(dirname -- "$BACKUP_PATH")
  BACKUP_FILENAME=$(basename -- "$BACKUP_PATH")
  BACKUP_EXT="${BACKUP_FILENAME##*.}"
  shift

  # Prepare permissions
  if [[ $EUID -ne 0 ]]; then
    .env --file .env set ACACIA_PUID="$(id -u)"
    .env --file .env set ACACIA_PGID="$(id -g)"
  fi

  dc exec --user="acacia" web acacia_cli acacia:backup "/var/acacia/backups/${BACKUP_FILENAME}" "$@"

  # Move from Docker volume to local filesystem
  d run --rm -v "$(volume-prefix)_backups:/backup_src" \
    -v "$BACKUP_DIR:/backup_dest" \
    busybox mv "/backup_src/${BACKUP_FILENAME}" "/backup_dest/${BACKUP_FILENAME}"

  echo "Backup completed."
  exit
}

#
# Restore an Acacia Radio backup into Docker.
# Usage:
# ./docker.sh restore [/custom/backup/dir/custombackupname.zip]
#
restore() {
  if [[ ! -f .env ]] || [[ ! -f acacia.env && ! -f azuracast.env ]]; then  # rebrand:keep (env file from before the rebrand)
    echo "Acacia Radio hasn't been installed yet on this server."
    echo "You should run './docker.sh install' first before restoring."
    exit 1
  fi

  if ask "Restoring will remove any existing Acacia Radio installation data, replacing it with your backup. Continue?" Y; then
    if [[ $1 != "" ]]; then
      local BACKUP_PATH BACKUP_DIR BACKUP_FILENAME BACKUP_EXT
      BACKUP_PATH=$(readlink -f ${1:-"./backup.tar.gz"})
      BACKUP_DIR=$(dirname -- "$BACKUP_PATH")
      BACKUP_FILENAME=$(basename -- "$BACKUP_PATH")
      BACKUP_EXT="${BACKUP_FILENAME##*.}"
      shift

      if [[ ! -f ${BACKUP_PATH} ]]; then
        echo "File '${BACKUP_PATH}' does not exist. Nothing to restore."
        exit 1
      fi

      dc down

      local VP
      VP=$(volume-prefix)

      # Remove most Acacia Radio volumes but preserve some essential ones.
      d volume rm -f $(d volume ls -q | grep "^${VP}_" | grep -v 'station\|install')
      d volume create "${VP}_backups"

      # Move from local filesystem to Docker volume
      d run --rm -v "$BACKUP_DIR:/backup_src" \
        -v "${VP}_backups:/backup_dest" \
        busybox mv "/backup_src/${BACKUP_FILENAME}" "/backup_dest/${BACKUP_FILENAME}"

      # Prepare permissions
      if [[ $EUID -ne 0 ]]; then
        .env --file .env set ACACIA_PUID="$(id -u)"
        .env --file .env set ACACIA_PGID="$(id -g)"
      fi

      dc run --rm web -- acacia_restore "/var/acacia/backups/${BACKUP_FILENAME}" "$@"

      # Move file back from volume to local filesystem
      d run --rm -v "${VP}_backups:/backup_src" \
        -v "$BACKUP_DIR:/backup_dest" \
        busybox mv "/backup_src/${BACKUP_FILENAME}" "/backup_dest/${BACKUP_FILENAME}"

      dc down --timeout 30
      dc up -d
    else
      dc down

      # Remove most Acacia Radio volumes but preserve some essential ones.
      d volume rm -f $(d volume ls -q | grep "^$(volume-prefix)_" | grep -v 'station\|backups\|install')

      dc run --rm web -- acacia_restore "$@"

      dc down --timeout 30
      dc up -d
    fi
  fi
  exit
}

#
# Restore the Docker volumes from a legacy backup format .tar.gz file.
# Usage:
# ./docker.sh restore [/custom/backup/dir/custombackupname.tar.gz]
#
restore-legacy() {
  local APP_BASE_DIR BACKUP_PATH BACKUP_DIR BACKUP_FILENAME

  APP_BASE_DIR=$(pwd)

  BACKUP_PATH=${1:-"./backup.tar.gz"}
  BACKUP_DIR=$(cd "$(dirname "$BACKUP_PATH")" && pwd)
  BACKUP_FILENAME=$(basename "$BACKUP_PATH")

  cd "$APP_BASE_DIR" || exit

  if [ -f "$BACKUP_PATH" ]; then
    dc down --timeout 30

    local VP
    VP=$(volume-prefix)
    d volume rm "${VP}_db_data" "${VP}_station_data"
    d volume create "${VP}_db_data"
    d volume create "${VP}_station_data"

    # Legacy archives hold the original project's top folder, so the mount points keep its name.
    local LEGACY_ROOT=/azuracast  # rebrand:keep
    d run --rm -v "$BACKUP_DIR:/backup" \
      -v "${VP}_db_data:${LEGACY_ROOT}/db" \
      -v "${VP}_station_data:${LEGACY_ROOT}/stations" \
      busybox tar zxvf "/backup/$BACKUP_FILENAME"

    dc up -d
  else
    echo "File $BACKUP_PATH does not exist in this directory. Nothing to restore."
    exit 1
  fi

  exit
}

#
# Stop all Docker containers and remove related volumes.
# Usage: ./docker.sh uninstall
#
uninstall() {
  if ask "This operation is destructive and will wipe your existing Docker containers. Continue?" N; then

    dc down -v
    dc rm -f
    d volume prune -f

    echo "All Acacia Radio Docker containers and volumes were removed."
    echo "To remove *all* Docker containers and volumes, run:"
    echo "  docker stop \$(docker ps -a -q)"
    echo "  docker rm \$(docker ps -a -q)"
    echo "  docker volume prune -f"
    echo ""
  fi

  exit
}

#
# Roll back to a specific stable release version.
#
rollback() {
  if is-retail; then retail-rollback; fi

  local ACACIA_ROLLBACK_VERSION
  ACACIA_ROLLBACK_VERSION="$1"

  if [[ -z "$ACACIA_ROLLBACK_VERSION" ]]; then
    echo "No version specified. Specify a version, like 0.19.0."
    exit 1
  fi

  echo "[NOTICE] Before you continue, please make sure you have a recent snapshot of your system and or backed it up."
  if ask "Are you ready to continue with the rollback?" Y; then
    if ! dc exec --user="acacia" web acacia_cli acacia:setup:rollback "${ACACIA_ROLLBACK_VERSION}"; then
      echo "The database rollback failed. Acacia Radio has not been switched to version '${ACACIA_ROLLBACK_VERSION}'."
      exit 1
    fi

    dc down --timeout 60

    .env --file .env set ACACIA_VERSION=${ACACIA_ROLLBACK_VERSION}

    dc pull
    dc run --rm web -- acacia_update
    dc up -d

    if ask "Clean up all stopped Docker containers and images to save space?" Y; then
      d system prune -f
    fi

    echo "Rollback complete. Your installation has been returned to stable version '${ACACIA_ROLLBACK_VERSION}'."
    echo "To return to the regular update channels, run:"
    echo "  ./docker.sh setup-release"
    echo " "
  fi
  exit
}

#
# LetsEncrypt: Now managed via the Web UI.
#
setup-letsencrypt() {
  echo "LetsEncrypt is now managed from within the web interface."
  echo "You can manage it via the 'Administration' panel, then 'System Settings'."
  echo "Under 'Services' you will find the LetsEncrypt settings."
}

letsencrypt-create() {
  setup-letsencrypt
  exit
}

#
# Utility script to facilitate switching ports.
# Usage: ./docker.sh change-ports
#
change-ports() {
  setup-ports

  dc down --timeout 60
  dc up -d
}

#
# Helper scripts for basic Docker Compose functions
#
up() {
  echo "Starting up Acacia Radio services..."
  dc up -d
}

down() {
  echo "Shutting down Acacia Radio services..."
  dc down --timeout 60
}

restart() {
  down
  up
}

# Ensure we're in the same directory as this script.
cd "$( dirname "${BASH_SOURCE[0]}" )" || exit

# Podman support
if [[ $(command -v podman) ]]; then
  PODMAN_MODE=1
fi

"$@"
