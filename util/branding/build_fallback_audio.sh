#!/usr/bin/env sh
# Re-encode the Acacia Radio fallback jingle (resources/error.mp3) into every fallback file Icecast ships, so a
# station with nothing to play, or a dropped stream, plays the jingle instead of the upstream one.
#   build_fallback_audio.sh <jingle> <icecast web dir>      (run by the Dockerfile; needs ffmpeg)
# Fallback files are named fallback-[<kbps>].<ext> (Icecast picks the one matching the stream's bitrate).
set -eu
JINGLE="$1"
DIR="$2"

enc() { # out codec-args...
    out="$1"; shift
    # shellcheck disable=SC2086
    ffmpeg -nostdin -loglevel error -y -i "$JINGLE" -vn -map_metadata -1 \
        -metadata title="Acacia Radio" -metadata artist="Acacia Radio" "$@" "$out"
}

cp "$JINGLE" "$DIR/error.mp3"
for f in "$DIR"/fallback-*; do
    name=$(basename "$f")
    kbps=$(echo "$name" | sed -E 's/^fallback-\[([0-9]+)\]\..*$/\1/')
    case "$name" in
        *.mp3)  enc "$f" -c:a libmp3lame -b:a "${kbps}k" -ar 44100 -f mp3 ;;
        *.aac)  enc "$f" -c:a aac -b:a "${kbps}k" -ar 44100 -f adts ;;
        *.ogg)  enc "$f" -c:a libvorbis -b:a "${kbps}k" -ar 44100 -f ogg ;;
        *.opus) enc "$f" -c:a libopus -b:a "${kbps}k" -ar 48000 -f opus ;;
    esac
done
[ -f "$DIR/fallback.flac" ] && enc "$DIR/fallback.flac" -c:a flac -ar 44100 -f flac
echo "Fallback audio rebuilt from $JINGLE"
