#!/usr/bin/env bash
#
# The one and only FTPS deployment implementation.
#
# It builds the lftp command script and runs it as `lftp --norc -f "$script"`.
# Used by:
#   * .github/workflows/deploy.yml       - production deploy
#   * tools/ci/check-deploy-workflow.sh  - static check (via --print-script)
#
# Configuration (environment):
#   DEPLOY_HOST        required  hostname or IP only - no scheme, no port
#   DEPLOY_PORT        required  numeric (21 for explicit FTPS)
#   DEPLOY_USERNAME    required
#   LFTP_PASSWORD      required  read by lftp via --env-password; never logged,
#                                never written to the script or a command line
#   DEPLOY_TLS_VERIFY  optional  "true" (default) or "false"
#   DEPLOY_LOCAL_DIR   optional  local build to upload (default "deploy-root")
#
# Argument:
#   --print-script    write the generated lftp script to stdout and exit
#
# Explicit FTPS only: `ftp://` + `set ftp:ssl-force true` makes lftp refuse a
# plaintext session. Only the paths below are ever touched; the remote root is
# never listed or mirrored, and `.env` / `var/` are never referenced.

set -eu

ALLOW_DIRS="public src config bin database vendor"
ALLOW_FILES="composer.json composer.lock README.md .env.production.example"

die() { echo "::error::lftp-deploy: $*" >&2; exit 1; }

host=$(printf '%s' "${DEPLOY_HOST:-}"            | tr -d '[:space:]')
port=$(printf '%s' "${DEPLOY_PORT:-}"            | tr -d '[:space:]')
user=$(printf '%s' "${DEPLOY_USERNAME:-}"        | tr -d '[:space:]')
verify=$(printf '%s' "${DEPLOY_TLS_VERIFY:-true}" | tr -d '[:space:]')
local_dir="${DEPLOY_LOCAL_DIR:-deploy-root}"

[ -n "$host" ]                 || die "DEPLOY_HOST is empty"
[ -n "$port" ]                 || die "DEPLOY_PORT is empty"
[ -n "$user" ]                 || die "DEPLOY_USERNAME is empty"
[ -n "${LFTP_PASSWORD:-}" ]    || die "LFTP_PASSWORD is empty"
case "$host"   in *[!A-Za-z0-9.-]*)   die "DEPLOY_HOST has invalid characters" ;; esac
case "$port"   in *[!0-9]*)           die "DEPLOY_PORT must be numeric" ;; esac
case "$user"   in *[!A-Za-z0-9._@-]*) die "DEPLOY_USERNAME has invalid characters" ;; esac
case "$verify" in true|false) ;;      *) die "DEPLOY_TLS_VERIFY must be true or false" ;; esac

# Build the lftp script. Every token below is a fixed literal or one of the
# character-whitelisted values above, so no line can contain a quote, a space,
# a newline, or an lftp command separator (';', '&&', '||').
generate() {
  echo "set cmd:fail-exit yes"
  echo "set net:max-retries 2"
  echo "set net:timeout 20"
  echo "set ftp:ssl-force true"
  echo "set ftp:ssl-protect-data true"
  echo "set ftp:ssl-protect-list true"
  echo "set ssl:verify-certificate ${verify}"
  echo "open -u \"${user}\" --env-password \"ftp://${host}:${port}\""
  echo "pwd"
  echo "echo == FTPS session established - starting uploads =="
  for d in $ALLOW_DIRS; do
    echo "mirror -R --delete --no-perms --verbose --exclude-glob .DS_Store \"${local_dir}/${d}/\" \"./${d}/\""
  done
  for f in $ALLOW_FILES; do
    echo "put -O \"./\" \"${local_dir}/${f}\""
  done
  echo "echo == all uploads completed =="
  echo "bye"
}

if [ "${1:-}" = "--print-script" ]; then
  generate
  exit 0
fi

[ -d "$local_dir" ] || die "local build directory not found: $local_dir"
command -v lftp >/dev/null 2>&1 || die "lftp is not installed"

script=$(mktemp)
trap 'rm -f "$script"' EXIT
generate > "$script"

echo "Deploying over explicit FTPS to port ${port} (verify-certificate=${verify})"
lftp --norc -f "$script"
echo "FTPS deploy OK."
