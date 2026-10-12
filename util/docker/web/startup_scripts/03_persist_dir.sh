#!/bin/bash

export DEBIAN_FRONTEND=noninteractive

echo "Creating persist directories..."

mkdir -p \
  /var/acacia/storage/acme \
  /var/acacia/storage/geoip \
  /var/acacia/storage/rsas \
  /var/acacia/storage/sftpgo \
  /var/acacia/storage/shoutcast2 \
  /var/acacia/storage/stereo_tool \
  /var/acacia/storage/uploads

chown acacia:acacia /var/acacia/storage/* || true
