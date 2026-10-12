# Acacia Radio with the Tracker plugin baked in (for CI, Kubernetes or immutable hosts).
#   docker build -t my-acacia:0.23.8-tracker --build-arg ACACIA_VERSION=0.23.8 .
# Then use it as the image of the "web" service in Acacia Radio's docker-compose.yml.
ARG ACACIA_VERSION=0.23.8
FROM ghcr.io/azuracast/azuracast:${ACACIA_VERSION}
COPY --chown=acacia:acacia . /var/acacia/www/plugins/branch-tracker
