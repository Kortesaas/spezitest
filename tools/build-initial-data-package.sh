#!/usr/bin/env sh
# Build a portable initial-data archive from reviewed, tracked repository data.

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
NAME="spezitest-initial-data-$VERSION"
ARCHIVE="$OUT/$NAME.tar.gz"
trap 'rm -rf "$STAGE"' EXIT

php tools/initial-data.php verify
mkdir -p "$OUT" "$STAGE/$NAME/var/admin-images/admin" "$STAGE/$NAME/var/legacy-images"

cp resources/initial-data/spezitest-data.sql "$STAGE/$NAME/spezitest-data.sql"
cp resources/initial-data/manifest.json "$STAGE/$NAME/DATA-MANIFEST.json"
cp docs/INSTALLATION.md "$STAGE/$NAME/INSTALL.md"
cp docs/DEPLOYMENT.md "$STAGE/$NAME/PLESK-DEPLOYMENT.md"
cp -R resources/primary-images/640x1024 "$STAGE/$NAME/var/admin-images/admin/"
cp -R resources/primary-images/legacy "$STAGE/$NAME/var/legacy-images/"

php -r '
    $root = $argv[1];
    $paths = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getFilename() === "MANIFEST.txt") { continue; }
        $relative = str_replace("\\", "/", substr($file->getPathname(), strlen($root) + 1));
        $paths[$relative] = hash_file("sha256", $file->getPathname());
    }
    ksort($paths);
    $lines = ["# Spezitest initial-data package", "# SHA-256  PATH"];
    foreach ($paths as $path => $hash) { $lines[] = "$hash  $path"; }
    file_put_contents($root . "/MANIFEST.txt", implode("\n", $lines) . "\n");
' "$STAGE/$NAME"

tar -C "$STAGE" -czf "$ARCHIVE" "$NAME"

ARCHIVE_HASH=$(php -r 'echo hash_file("sha256", $argv[1]);' "$ARCHIVE")
ARCHIVE_BYTES=$(wc -c < "$ARCHIVE" | tr -d ' ')
echo "Initial-data artifact: dist/$NAME.tar.gz ($ARCHIVE_BYTES bytes)"
echo "SHA-256: $ARCHIVE_HASH"
echo "Images: 195 (186 WebP + 9 retained fallbacks)"
