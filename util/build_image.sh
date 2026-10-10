#!/usr/bin/env bash
# Build the production image with version info taken from the local git checkout.
#   util/build_image.sh [tag]        (default tag: azuracast-retail:dev)
#
# Environment:
#   COMPOSER_MAX_PARALLEL_HTTP  parallel composer downloads (default 12; try 2 on a flaky network)
#   BUILD_ATTEMPTS              retries on failure; finished layers are cached (default 1)
#   BUILDX_BUILDER              buildx builder to use (default: the current one)
set -euo pipefail
cd "$( dirname "${BASH_SOURCE[0]}" )/.."

TAG="${1:-azuracast-retail:dev}"
ATTEMPTS="${BUILD_ATTEMPTS:-1}"

for ((i = 1; i <= ATTEMPTS; i++)); do
    if docker buildx build --load --target final -t "$TAG" \
        --build-arg GIT_COMMIT="$(git log --pretty=%H -n1 HEAD)" \
        --build-arg GIT_COMMIT_DATE="$(git log -n1 --pretty=%ci HEAD)" \
        --build-arg GIT_BRANCH="$(git rev-parse --abbrev-ref HEAD)" \
        --build-arg COMPOSER_MAX_PARALLEL_HTTP="${COMPOSER_MAX_PARALLEL_HTTP:-12}" \
        .; then
        echo "Built $TAG"
        exit 0
    fi
    echo "Build attempt $i of $ATTEMPTS failed." >&2
    sleep 5
done
exit 1
