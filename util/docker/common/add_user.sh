#!/bin/bash
set -e
set -x

apt-get install -y --no-install-recommends sudo

# Workaround for sudo errors in containers, see: https://github.com/sudo-project/sudo/issues/42
echo "Set disable_coredump false" >> /etc/sudo.conf

adduser --home /var/acacia --disabled-password --gecos "" acacia

usermod -aG www-data acacia

mkdir -p /var/acacia/www /var/acacia/stations /var/acacia/www_tmp \
  /var/acacia/docs \
  /var/acacia/backups \
  /var/acacia/dbip \
  /var/acacia/storage/uploads \
  /var/acacia/storage/shoutcast2 \
  /var/acacia/storage/stereo_tool \
  /var/acacia/storage/geoip \
  /var/acacia/storage/sftpgo \
  /var/acacia/storage/acme

chown -R acacia:acacia /var/acacia
chmod -R 777 /var/acacia/www_tmp

echo 'acacia ALL=(ALL) NOPASSWD: ALL' >> /etc/sudoers
