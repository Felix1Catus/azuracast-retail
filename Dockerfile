# syntax=docker/dockerfile:1

#
# MariaDB dependencies build step
#
FROM mariadb:lts-noble AS mariadb

#

#
# Icecast-KH with Acacia Radio customizations build step
#
FROM ghcr.io/azuracast/icecast-ac:2025-12-29 AS icecast

#
# PHP Extension Installer build step
#
FROM mlocati/php-extension-installer AS php-extension-installer

# ---

FROM scratch AS dependencies

# Add PHP extension installer tool
COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Add MariaDB dependencies
COPY --from=mariadb /usr/local/bin/healthcheck.sh /usr/local/bin/db_healthcheck.sh
COPY --from=mariadb /usr/local/bin/docker-entrypoint.sh /usr/local/bin/db_entrypoint.sh

# Add Icecast
COPY --from=icecast /usr/local/bin/icecast /usr/local/bin/icecast
COPY --from=icecast /usr/local/share/icecast /usr/local/share/icecast

#
# Final build image
#
FROM php:8.5-fpm-trixie AS pre-final

ENV TZ="UTC" \
    LANGUAGE="en_US.UTF-8" \
    LC_ALL="en_US.UTF-8" \
    LANG="en_US.UTF-8" \
    LC_TYPE="en_US.UTF-8"

COPY --link --from=dependencies / /

# Run base build process
RUN --mount=type=bind,source=./util/docker/common,target=/bd_build,rw \
    bash /bd_build/prepare.sh && \
    bash /bd_build/add_user.sh && \
    bash /bd_build/cleanup.sh

# Build each set of dependencies in their own step for cacheability.
RUN --mount=type=bind,source=./util/docker/common,target=/bd_build,rw \
    --mount=type=bind,source=./util/docker/supervisor,target=/bd_build/supervisor,rw \
    bash /bd_build/supervisor/setup.sh && \
    bash /bd_build/cleanup.sh

RUN --mount=type=bind,source=./util/docker/common,target=/bd_build,rw \
    --mount=type=bind,source=./util/docker/stations,target=/bd_build/stations,rw \
    bash /bd_build/stations/setup.sh && \
    bash /bd_build/cleanup.sh

RUN --mount=type=bind,source=./util/docker/common,target=/bd_build,rw \
    --mount=type=bind,source=./util/docker/web,target=/bd_build/web,rw \
    bash /bd_build/web/setup.sh && \
    bash /bd_build/cleanup.sh

RUN --mount=type=bind,source=./util/docker/common,target=/bd_build,rw \
    --mount=type=bind,source=./util/docker/mariadb,target=/bd_build/mariadb,rw \
    bash /bd_build/mariadb/setup.sh && \
    bash /bd_build/cleanup.sh

RUN --mount=type=bind,source=./util/docker/common,target=/bd_build,rw \
    --mount=type=bind,source=./util/docker/redis,target=/bd_build/redis,rw \
    bash /bd_build/redis/setup.sh && \
    bash /bd_build/cleanup.sh

RUN --mount=type=bind,source=./util/docker/common,target=/bd_build,rw \
    bash /bd_build/chown_dirs.sh


USER acacia

RUN touch /var/acacia/.docker

USER root

VOLUME "/var/acacia/stations"
VOLUME "/var/acacia/backups"
VOLUME "/var/lib/mysql"
VOLUME "/var/acacia/storage/acme"
VOLUME "/var/acacia/storage/geoip"
VOLUME "/var/acacia/storage/rsas"
VOLUME "/var/acacia/storage/sftpgo"
VOLUME "/var/acacia/storage/shoutcast2"
VOLUME "/var/acacia/storage/stereo_tool"
VOLUME "/var/acacia/storage/uploads"

EXPOSE 80 443

# Sensible default environment variables.
ENV LANG="en_US.UTF-8" \
    PATH="${PATH}:/var/acacia/storage/shoutcast2" \
    APPLICATION_ENV="production" \
    MYSQL_HOST="localhost" \
    MYSQL_PORT=3306 \
    MYSQL_USER="acacia" \
    MYSQL_PASSWORD="azur4c457" \
    MYSQL_DATABASE="acacia" \
    ENABLE_REDIS="true" \
    REDIS_HOST="localhost" \
    REDIS_PORT=6379 \
    REDIS_DB=0 \
    NGINX_RADIO_PORTS="default" \
    NGINX_WEBDJ_PORTS="default" \
    COMPOSER_PLUGIN_MODE="false" \
    ADDITIONAL_MEDIA_SYNC_WORKER_COUNT=0 \
    PROFILING_EXTENSION_ENABLED=0 \
    PROFILING_EXTENSION_ALWAYS_ON=0 \
    PROFILING_EXTENSION_HTTP_KEY=dev \
    PROFILING_EXTENSION_HTTP_IP_WHITELIST=* \
    ENABLE_WEB_UPDATER="false"

#
# Development Build
#
FROM pre-final AS development

# Dev build step
COPY ./util/docker/common /bd_build/
COPY ./util/docker/dev /bd_build/dev

RUN bash /bd_build/dev/setup.sh \
    && bash /bd_build/cleanup.sh \
    && rm -rf /bd_build

USER acacia

WORKDIR /var/acacia/www

COPY --chown=acacia:acacia . .

RUN composer install --no-ansi --no-interaction \
    && composer clear-cache

RUN npm ci --include=dev \
    && npm cache clean --force

USER root

# Sensible default environment variables.
ENV APPLICATION_ENV="development" \
    PROFILING_EXTENSION_ENABLED=1 \
    ENABLE_WEB_UPDATER="false"

# Entrypoint and default command
ENTRYPOINT ["tini", "--", "/usr/local/bin/my_init"]
CMD ["--no-main-command"]

#
# Static assets build step (retail fork).
# Upstream builds the frontend, translations, API docs and .gitinfo in GitHub Actions and drops them
# into the build context. Building them here makes `docker build` self-contained.
#
FROM pre-final AS assets

COPY --from=node:24-trixie-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-trixie-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

USER acacia

WORKDIR /var/acacia/www

COPY --chown=acacia:acacia . .

# The asset commands (acacia:locale:import, acacia:api:docs) are dev commands: they are only
# registered outside production and need composer dev packages. Nothing from this stage ships except
# the files copied into "final". No Redis or database runs during the build.
ENV APPLICATION_ENV="development" \
    ENABLE_REDIS="false"

# .git is not in the build context; pass these from the host (see util/build_image.sh).
ARG GIT_COMMIT=""
ARG GIT_COMMIT_DATE=""
ARG GIT_BRANCH=""
# Parallel downloads for composer (its default is 12); lower it on unreliable networks.
ARG COMPOSER_MAX_PARALLEL_HTTP=12

RUN composer install --no-ansi --no-interaction \
    && npm ci --include=dev \
    && chmod a+x backend/bin/* \
    && backend/bin/console acacia:locale:import \
    && npm run build \
    && backend/bin/console acacia:api:docs \
    && if [ -n "$GIT_COMMIT" ]; then \
        printf 'COMMIT_LONG="%s"\nCOMMIT_DATE="%s"\nBRANCH="%s"\n' "$GIT_COMMIT" "$GIT_COMMIT_DATE" "$GIT_BRANCH" > .gitinfo; \
    else \
        touch .gitinfo; \
    fi

#
# Final build (Just environment vars and squishing the FS)
#
FROM pre-final AS final

USER acacia

WORKDIR /var/acacia/www

COPY --chown=acacia:acacia . .

ARG COMPOSER_MAX_PARALLEL_HTTP=12

RUN composer install --no-dev --no-ansi --no-autoloader --no-interaction \
    && composer dump-autoload --optimize --classmap-authoritative \
    && composer clear-cache

COPY --from=assets --chown=acacia:acacia /var/acacia/www/web/static/vite_dist ./web/static/vite_dist
COPY --from=assets --chown=acacia:acacia /var/acacia/www/web/static/openapi.yml ./web/static/openapi.yml
COPY --from=assets --chown=acacia:acacia /var/acacia/www/translations ./translations
COPY --from=assets --chown=acacia:acacia /var/acacia/www/.gitinfo ./.gitinfo

USER root

# Acacia Radio fallback jingle in place of the upstream one, in every format/bitrate Icecast falls back to.
RUN sh /var/acacia/www/util/branding/build_fallback_audio.sh \
        /var/acacia/www/resources/error.mp3 /usr/local/share/icecast/web

# Entrypoint and default command
ENTRYPOINT ["tini", "--", "/usr/local/bin/my_init"]
CMD ["--no-main-command"]
