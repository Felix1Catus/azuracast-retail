#!/bin/bash

ACACIA_PUID="${ACACIA_PUID:-1000}"
ACACIA_PGID="${ACACIA_PGID:-1000}"

PUID="${PUID:-$ACACIA_PUID}"
PGID="${PGID:-$ACACIA_PGID}"

groupmod -o -g "$PGID" acacia
usermod -o -u "$PUID" acacia

echo "Docker 'acacia' User UID: $(id -u acacia)"
echo "Docker 'acacia' User GID: $(id -g acacia)"
