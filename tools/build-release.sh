#!/usr/bin/env sh
# Build a production release artifact for Spezitest.
#
# Produces dist/spezitest-<version>.tar.gz containing the application plus
# production Composer dependencies, and excluding tests, dev tooling, the
# legacy Excel sources, local reports, and any environment/secret files.
#
# Requirements: PHP 8.3, Composer 2, tar. No Node, no Docker, no network access
# beyond Composer's package downloads.
#
# Usage:  sh tools/build-release.sh [version]
#   version defaults to the current git short SHA (or "manual" outside git).

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
STAGE=$(mktemp -d)
OUT="$ROOT/dist"
NAME="spezitest-$VERSION"

trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$OUT"

echo "==> Installing production dependencies (no-dev)"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "==> Staging application files"
mkdir -p "$STAGE/$NAME"
# Tracked application paths only. bin/legacy-import.php is intentionally omitted:
# the legacy importer relies on dev autoloading and is run locally, never on the
# production host.
for path in \
    bin/migrate.php \
    config \
    database/migrations \
    database/README.md \
    docs \
    public \
    src \
    vendor \
    composer.json \
    composer.lock \
    README.md \
    .env.production.example
do
    if [ -e "$path" ]; then
        mkdir -p "$STAGE/$NAME/$(dirname "$path")"
        cp -R "$path" "$STAGE/$NAME/$(dirname "$path")/"
    fi
done

# Writable runtime directories (empty, kept in the archive).
mkdir -p "$STAGE/$NAME/var/admin-images" "$STAGE/$NAME/var/legacy-images"
printf 'Private runtime storage. Must be writable by PHP-FPM and must stay OUTSIDE public/.\n' \
    > "$STAGE/$NAME/var/README.txt"

# Defensive: never ship real secrets or local state. Keep only the *.example
# templates.
find "$STAGE/$NAME" -maxdepth 1 -name '.env*' ! -name '*.example' -delete 2>/dev/null || true
find "$STAGE/$NAME" -name '.DS_Store' -delete 2>/dev/null || true
find "$STAGE/$NAME/vendor" -type d -name 'tests' -prune -exec rm -rf {} + 2>/dev/null || true
find "$STAGE/$NAME/vendor" -type d \( -name 'test' -o -name 'Tests' \) -prune -exec rm -rf {} + 2>/dev/null || true
find "$STAGE/$NAME/vendor" -type f \( -name 'phpunit.xml*' -o -name '.php-cs-fixer*' -o -name 'phpstan*' \) -delete 2>/dev/null || true

echo "==> Creating $OUT/$NAME.tar.gz"
tar -C "$STAGE" -czf "$OUT/$NAME.tar.gz" "$NAME"

echo "==> Restoring development dependencies"
composer install --no-interaction --no-progress >/dev/null

SIZE=$(du -h "$OUT/$NAME.tar.gz" | cut -f1)
echo
echo "Release artifact:  dist/$NAME.tar.gz  ($SIZE)"
echo "Contents (top level):"
tar -tzf "$OUT/$NAME.tar.gz" | awk -F/ '{print $2}' | sort -u | sed 's/^/  /'
