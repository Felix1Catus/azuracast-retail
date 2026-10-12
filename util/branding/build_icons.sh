#!/usr/bin/env bash
# Render the Acacia Radio icon (util/branding/icon.svg) into every icon file the app ships:
#   web/static/icons/{production,development}/<size>.png and original.png, web/favicon.ico, resources/icon*.png
# Replace icon.svg (square, 512x512 viewBox) with the final logo and run this again. Needs Docker.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
docker run --rm -v "$PWD:/src" -w /src debian:trixie-slim sh -euc '
  apt-get update -qq >/dev/null && apt-get install -y -qq librsvg2-bin icoutils >/dev/null
  for env in production development; do
    dir=web/static/icons/$env
    for f in "$dir"/*.png; do
      size=$(basename "$f" .png)
      [ "$size" = original ] && size=256
      rsvg-convert -w "$size" -h "$size" util/branding/icon.svg -o "$f"
    done
  done
  for s in 16 32 48; do rsvg-convert -w $s -h $s util/branding/icon.svg -o /tmp/fav-$s.png; done
  icotool -c -o web/favicon.ico /tmp/fav-16.png /tmp/fav-32.png /tmp/fav-48.png
  rsvg-convert -w 300 -h 300 util/branding/icon.svg -o resources/icon.png
  cp util/branding/icon.svg resources/icon.svg
  chown -R '"$(id -u):$(id -g)"' web/static/icons web/favicon.ico resources/icon.png resources/icon.svg
'
echo "Icons rendered from util/branding/icon.svg"
