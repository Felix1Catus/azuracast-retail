#!/usr/bin/env bash
# Render the Acacia Radio logo (util/branding/acacia-logo.svg, the Inkscape master) into every icon the app ships:
#   web/static/icons/{production,development}/<size>.png and original.png, web/favicon.ico,
#   resources/icon.png, resources/icon-{api,dev}.png, util/branding/icon.png (1024 px master)
# The drawing is cropped to its content and centred on a cream rounded tile, so it reads on light and dark
# backgrounds. Replace acacia-logo.svg and run this again to change the logo. Needs Docker.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
TILE="${ICON_TILE_COLOR:-#fbf6e9}"
docker run --rm -v "$PWD:/src" -w /src debian:trixie-slim sh -euc '
  apt-get update -qq >/dev/null && apt-get install -y -qq librsvg2-bin imagemagick icoutils >/dev/null 2>&1
  T=$(mktemp -d)
  rsvg-convert -w 2048 -h 2048 util/branding/acacia-logo.svg -o $T/full.png
  convert $T/full.png -trim +repage -resize 1640x1640 -background none -gravity center -extent 2048x2048 $T/mark.png
  convert -size 2048x2048 xc:none -fill "'"$TILE"'" -draw "roundrectangle 0,0 2047,2047 440,440" $T/tile.png
  convert $T/tile.png $T/mark.png -compose over -composite $T/master.png
  convert $T/master.png -resize 1024x1024 util/branding/icon.png
  for env in production development; do
    for f in web/static/icons/$env/*.png; do
      size=$(basename "$f" .png); [ "$size" = original ] && size=256
      convert $T/master.png -resize ${size}x${size} "$f"
    done
  done
  for s in 16 32 48; do convert $T/master.png -resize ${s}x${s} $T/fav-$s.png; done
  icotool -c -o web/favicon.ico $T/fav-16.png $T/fav-32.png $T/fav-48.png
  for f in resources/icon.png resources/icon-api.png resources/icon-dev.png; do convert $T/master.png -resize 300x300 "$f"; done
  chown -R '"$(id -u):$(id -g)"' web/static/icons web/favicon.ico resources util/branding
'
echo "Icons rendered from util/branding/acacia-logo.svg"
