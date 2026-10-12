# Music Downloader worker: spotDL behind a small internal API (see ../README.md).
# It runs next to Acacia Radio's "web" container and writes into the same station_data volume.
# The same image also runs the music-downloader-browser service (YouTube account; command in the compose file).
#   docker build -t acacia-music-downloader:dev plugins/music-downloader/worker
ARG DENO_VERSION=2.4.5
FROM denoland/deno:bin-${DENO_VERSION} AS deno

FROM python:3.13-slim-trixie

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    HOME=/data/home \
    MD_DATA_DIR=/data

# chromium, xvfb, xauth and fonts are for the music-downloader-browser service (same image, see app/browser.py).
RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg ca-certificates tini \
        chromium xvfb xauth fonts-liberation fonts-noto-color-emoji \
    && rm -rf /var/lib/apt/lists/*

# yt-dlp needs a JavaScript runtime for some YouTube videos; without it those tracks fail.
COPY --from=deno /deno /usr/local/bin/deno

COPY requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt

COPY app /app/app

# Same UID/GID as Acacia Radio's "acacia" user, so downloaded files belong to it.
RUN groupadd -g 1000 acacia && useradd -u 1000 -g 1000 -M -d /data/home acacia \
    && mkdir -p /data/home && chown -R 1000:1000 /data

USER 1000:1000
WORKDIR /app
VOLUME ["/data"]
EXPOSE 8000

HEALTHCHECK --interval=60s --timeout=5s --start-period=20s \
    CMD python -c "import urllib.request,sys; urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=4)" || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["python", "-m", "uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--no-access-log"]
