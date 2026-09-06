#!/usr/bin/env sh
# Build both archives needed for a complete fresh installation.

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

if [ "$#" -ge 1 ]; then
    VERSION="$1"
elif git rev-parse --short HEAD >/dev/null 2>&1; then
    VERSION="$(date +%Y%m%d)-$(git rev-parse --short HEAD)"
    git diff --quiet 2>/dev/null || VERSION="$VERSION-dirty"
else
    VERSION="$(date +%Y%m%d)-manual"
fi

sh tools/build-release.sh "$VERSION"
sh tools/build-initial-data-package.sh "$VERSION"

echo "Complete release built with version: $VERSION"
echo "Upload both dist/spezitest-$VERSION.tar.gz and dist/spezitest-initial-data-$VERSION.tar.gz."
