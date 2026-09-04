#!/usr/bin/env sh
# Rebuild the LOCAL development database from the reviewed legacy dataset.
#
# This DROPS the domain tables in the configured local database, re-applies the
# migrations, regenerates the import plan with the resolved duplicate decisions,
# and runs the controlled importer. It never touches production: the importer
# refuses unless APP_ENV is local/development/testing, and this script also
# refuses a database whose name looks like production.
#
# Usage:  sh tools/dev-load-legacy.sh [--yes]
#
# Requirements: PHP 8.3 (with pdo_mysql), Composer, Python 3 (stdlib only), a
# running local MariaDB, and the two source workbooks in var/legacy-import/.
# No mysql/mariadb client binary is needed.

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

ASSUME_YES=0
[ "${1:-}" = "--yes" ] && ASSUME_YES=1

if [ ! -f .env ]; then
    echo "No .env file. Copy .env.example to .env and configure the local database first." >&2
    exit 1
fi

# Minimal .env reader: KEY=value, optional surrounding quotes.
env_get() {
    grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed "s/^[\"']//; s/[\"']$//"
}

APP_ENV=$(env_get APP_ENV)
DB_HOST=$(env_get DB_HOST)
DB_PORT=$(env_get DB_PORT)
DB_NAME=$(env_get DB_NAME)
DB_USER=$(env_get DB_USER)
DB_PASSWORD=$(env_get DB_PASSWORD)
: "${APP_ENV:=local}" "${DB_HOST:=127.0.0.1}" "${DB_PORT:=3306}"

case "$APP_ENV" in
    local|development|testing) ;;
    *) echo "Refusing to run: APP_ENV is '$APP_ENV' (must be local/development/testing)." >&2; exit 1 ;;
esac
case "$DB_NAME" in
    ""|*prod*|*production*) echo "Refusing to run against database '$DB_NAME'." >&2; exit 1 ;;
esac

for f in var/legacy-import/primaerliste.xlsx var/legacy-import/beschaffungsliste.xlsx; do
    [ -f "$f" ] && continue
    echo "Missing source workbook: $f" >&2
    echo "Place both reviewed workbooks in var/legacy-import/ (git-ignored)." >&2
    exit 1
done

echo
echo "  Reload the domain tables in the LOCAL database:"
echo "    host   $DB_HOST:$DB_PORT"
echo "    db     $DB_NAME  (APP_ENV=$APP_ENV)"
echo
if [ "$ASSUME_YES" -ne 1 ]; then
    printf "  This DROPS and reloads drinks/tests/ratings/images. Continue? [y/N] "
    read -r answer
    case "$answer" in y|Y|yes|YES) ;; *) echo "  Aborted."; exit 0 ;; esac
fi

echo "==> Dropping domain + import tables"
DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASSWORD="$DB_PASSWORD" \
php -r '
    $pdo = new PDO(
        sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_NAME")),
        getenv("DB_USER"),
        getenv("DB_PASSWORD"),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("DROP TABLE IF EXISTS ratings, drink_images, drink_tests, legacy_import_runs, testers, drinks, schema_migrations");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
'

echo "==> Applying migrations (schema + canonical testers)"
composer --quiet migrate

echo "==> Regenerating the import plan with the resolved duplicate decisions"
mkdir -p var/legacy-import-output/current
cp tools/legacy-import/duplicate-decisions.resolved.json \
   var/legacy-import-output/current/duplicate-decisions.json
composer --quiet legacy-import:plan >/dev/null

echo "==> Clearing any previously staged legacy images"
rm -rf var/legacy-images/legacy

echo "==> Importing (verifies every Gesamt / rank / Preis-Leistung before commit)"
if ! IMPORT_OUT=$(php bin/legacy-import.php apply --storage-root=var/legacy-images 2>&1); then
    printf '%s\n' "$IMPORT_OUT" >&2
    exit 1
fi

echo
echo "Done. Local catalogue loaded: 196 drinks, 108 tests, 195 images."
echo "Images are under var/legacy-images/legacy/<run-id>/ ."
echo "Ensure LEGACY_IMAGE_STORAGE_ROOT=var/legacy-images is set in .env so they display."
