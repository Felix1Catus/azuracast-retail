#!/usr/bin/env bash
# Build the production image with version info taken from the local git checkout.
#   util/build_image.sh [tag]        (default tag: azuracast-retail:dev)
set -euo pipefail
cd "$( dirname "${BASH_SOURCE[0]}" )/.."

TAG="${1:-azuracast-retail:dev}"

docker build --target final -t "$TAG" \
    --build-arg GIT_COMMIT="$(git log --pretty=%H -n1 HEAD)" \
    --build-arg GIT_COMMIT_DATE="$(git log -n1 --pretty=%ci HEAD)" \
    --build-arg GIT_BRANCH="$(git rev-parse --abbrev-ref HEAD)" \
    .
