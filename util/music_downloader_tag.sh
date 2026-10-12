#!/usr/bin/env bash
# Music Downloader worker image tag for an Acacia Radio image tag:
#   util/music_downloader_tag.sh azuracast-retail:0.23.8-retail.7  ->  azuracast-retail-music-downloader:0.23.8-retail.7
set -euo pipefail
TAG="${1:?usage: $0 <image tag>}"
REPO="${TAG%:*}"
VERSION="${TAG##*:}"
[[ "$TAG" == *:* ]] || VERSION="latest"
echo "${REPO}-music-downloader:${VERSION}"
