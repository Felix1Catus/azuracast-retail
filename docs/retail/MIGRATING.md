# Moving an existing install to Acacia Radio

This applies to an existing AzuraCast 0.23.8 Docker install. The database stays compatible both ways, so a rollback is just a matter of switching the compose file back. For installs that run an earlier Acacia Radio release, follow the release notes ([FIXLOG.md](FIXLOG.md)) of the release you update to. <!-- rebrand:keep -->

First get the Acacia Radio images and the release's files (the compose file and `docker.sh`):

```bash
VERSION=0.23.8-retail.17                                # the release to move to
git clone --filter=blob:none https://github.com/Felix1Catus/azuracast-retail.git ~/acacia-radio
git -C ~/acacia-radio checkout --quiet v$VERSION
docker pull ghcr.io/felix1catus/acacia-radio:$VERSION
docker pull ghcr.io/felix1catus/acacia-radio-music-downloader:$VERSION
docker tag ghcr.io/felix1catus/acacia-radio:$VERSION acacia-radio:latest
docker tag ghcr.io/felix1catus/acacia-radio-music-downloader:$VERSION acacia-radio-music-downloader:latest
```

Then switch:

```bash
cd /var/acacia                                      # or wherever docker-compose.yml lives
./docker.sh backup                                     # 1. backup first
docker compose exec web acacia_cli acacia:settings:list | grep use_radio_proxy   # must be 1
cp docker-compose.yml docker-compose.yml.upstream      # 2. keep the old file for rollback
cp ~/acacia-radio/docker-compose.retail.yml docker-compose.yml
docker compose up -d --remove-orphans                  # 3. recreates "acacia" on the Acacia Radio image and removes the updater
```

Keep `docker-compose.override.yml` as it is: it holds plugin mounts such as the Tracker plugin.

**Before you switch:** if any player connects to `http://SERVER:8000/...` directly instead of `/listen/...`, it stops working, because only 80/443 are published. To check, open a player and look at the stream URL.

**Check after the switch:**
1. `https://SERVER/source` opens the source code on GitHub.
2. The panel footer shows **Source code**.
3. **Administration → System Maintenance** shows no update prompts.
4. Players are playing.

**Roll back:**

```bash
cp docker-compose.yml.upstream docker-compose.yml && docker compose up -d --remove-orphans
```
