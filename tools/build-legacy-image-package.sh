#!/usr/bin/env sh
# Build dist/legacy-images-upload.tar.gz from the reviewed, already-extracted
# legacy Spezi images.
#
# The images are content-addressed (filename = SHA-256 of the bytes), which is
# exactly what drink_images.storage_path stores as
# `legacy/<run-id>/<sha>.<ext>`. This script copies the validated files
# verbatim — no rename, no re-hash, no recompression — and verifies every file
# against its own name and against the import plan.
#
# The archive extracts to `legacy/<run-id>/...` with NO wrapper directory, so it
# is uploaded into and extracted inside  spezitest/var/legacy-images/ .
#
# Usage:  sh tools/build-legacy-image-package.sh

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

SRC_BASE="var/legacy-images/legacy"
PLAN="var/legacy-import-output/current/import-plan.json"
OUT="dist"
ARCHIVE="$OUT/legacy-images-upload.tar.gz"
MANIFEST="$OUT/legacy-images-upload.MANIFEST.txt"

[ -d "$SRC_BASE" ] || { echo "No $SRC_BASE — run 'composer dev:load-legacy' (or the import) first." >&2; exit 1; }
[ -f "$PLAN" ] || { echo "No $PLAN — run 'composer legacy-import:plan' first." >&2; exit 1; }

RUN_ID_COUNT=$(find "$SRC_BASE" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d ' ')
if [ "$RUN_ID_COUNT" != "1" ]; then
    echo "Expected exactly one run-id directory under $SRC_BASE, found $RUN_ID_COUNT:" >&2
    ls "$SRC_BASE" >&2
    exit 1
fi
RUN_ID=$(find "$SRC_BASE" -mindepth 1 -maxdepth 1 -type d -exec basename {} \;)
SRC="$SRC_BASE/$RUN_ID"
mkdir -p "$OUT"

echo "==> Verifying $SRC against $PLAN"
php -r '
    $root = getcwd();
    $src = $argv[1];
    $runId = $argv[2];
    $plan = json_decode(file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);

    $expected = [];
    foreach ($plan["drinks"] as $d) {
        foreach ($d["images"] ?? [] as $im) {
            $ext = $im["mime_type"] === "image/jpeg" ? "jpg" : ($im["mime_type"] === "image/png" ? "png" : null);
            if ($ext === null) { fwrite(STDERR, "Unexpected mime {$im["mime_type"]}\n"); exit(1); }
            $expected[$im["sha256"] . "." . $ext] = $im["sha256"];
        }
    }

    $files = array_values(array_filter(scandir($src), fn($f) => $f !== "." && $f !== ".."));
    sort($files);

    $problems = 0;
    foreach ($files as $f) {
        $path = "$src/$f";
        if (!is_file($path)) { echo "  not a file: $f\n"; $problems++; continue; }
        [$stem, $ext] = array_pad(explode(".", $f, 2), 2, "");
        $hash = hash_file("sha256", $path);
        if ($hash !== $stem) { echo "  HASH != NAME: $f (content $hash)\n"; $problems++; }
        if (!isset($expected[$f])) { echo "  not referenced by any drink in the plan: $f\n"; $problems++; }
        if (!in_array($ext, ["png", "jpg"], true)) { echo "  unexpected extension: $f\n"; $problems++; }
    }
    $onDisk = array_flip($files);
    foreach ($expected as $name => $_) {
        if (!isset($onDisk[$name])) { echo "  MISSING from disk: $name\n"; $problems++; }
    }

    $missing = $plan["missing_images"] ?? [];
    printf("  files on disk:              %d\n", count($files));
    printf("  images referenced by plan:  %d\n", count($expected));
    printf("  hash/name/plan problems:    %d\n", $problems);
    printf("  known missing (no image):   %s\n", $missing ? implode(", ", array_map(fn($m) => $m["name"] . " (" . $m["source"] . ")", $missing)) : "none");

    if ($problems !== 0) { fwrite(STDERR, "Verification failed.\n"); exit(1); }
    file_put_contents("php://stderr", "");
' "$SRC" "$RUN_ID" "$PLAN"

COUNT=$(find "$SRC" -type f | wc -l | tr -d ' ')

echo "==> Creating $ARCHIVE (root = legacy/<run-id>/..., image files only, no wrapper)"
rm -f "$ARCHIVE"
tar -C var/legacy-images -czf "$ARCHIVE" "legacy/$RUN_ID"

SHA=$(shasum -a 256 "$ARCHIVE" | cut -d' ' -f1)
SIZE=$(du -h "$ARCHIVE" | cut -f1 | tr -d ' ')
BYTES=$(wc -c < "$ARCHIVE" | tr -d ' ')

cat > "$MANIFEST" <<EOF
Spezitest — legacy image upload package
=======================================

archive:             $(basename "$ARCHIVE")
archive size:        $SIZE ($BYTES bytes)
archive SHA-256:     $SHA
image count:         $COUNT
run ID:              $RUN_ID
source workbooks:    primaerliste.xlsx  e426d6ae93ca1f754455772664ffb5ed0175c65d6a6138397dec50532e9b262d
                     beschaffungsliste.xlsx  60811823c90651fe44ba77bb7bcc978672c3344fb3ef433f824993b38f58957c
known missing image: Endlich Cola-Mix (primaerliste:85) — intentionally has no image

Upload the archive to:
    spezitest/var/legacy-images/

Extract it there (it has NO wrapper directory). Resulting structure:

    spezitest/
    └── var/
        └── legacy-images/
            └── legacy/
                └── $RUN_ID/
                    ├── <sha256>.png
                    ├── <sha256>.jpg
                    └── ...   ($COUNT files)

The database already stores the relative paths
    legacy/$RUN_ID/<sha256>.<ext>
so filenames must not be renamed or re-hashed. Files are the validated
originals — no resize, no recompression.

Verify after upload (any shell with sha256 tooling):
    cd spezitest/var/legacy-images/legacy/$RUN_ID
    for f in *; do [ "\$(shasum -a 256 "\$f" | cut -d' ' -f1)" = "\${f%.*}" ] || echo "MISMATCH \$f"; done
    ls | wc -l        # -> $COUNT
EOF

echo
echo "Archive:   $ARCHIVE   ($SIZE)"
echo "SHA-256:   $SHA"
echo "Images:    $COUNT"
echo "Manifest:  $MANIFEST"
echo
echo "Archive root entries:"
tar -tzf "$ARCHIVE" | head -3 | sed 's/^/  /'
echo "  ..."
tar -tzf "$ARCHIVE" | grep -c . | sed 's/^/  total paths: /'
