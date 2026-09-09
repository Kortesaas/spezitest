#!/usr/bin/env bash
#
# Single source of truth for the FTPS production deployment.
#
# It (a) generates the lftp command script and (b) runs it with the exact
# invocation used in production:  lftp --norc -f "$script"
#
# The same file is used by:
#   * .github/workflows/deploy.yml        - production deploy
#   * tools/ci/ftps-deploy-e2e.sh         - end-to-end test vs a disposable server
#   * tools/ci/check-deploy-workflow.sh   - static checks (via --print-script)
#
# There is intentionally no second implementation.
#
# Configuration (environment variables):
#   DEPLOY_HOST         required  FTP host, hostname or IP only (no scheme/port)
#   DEPLOY_PORT         required  numeric
#   DEPLOY_USERNAME     required  [A-Za-z0-9._@-]
#   LFTP_PASSWORD       required  read by lftp via --env-password; never printed,
#                                 never written to the script or a command line
#   DEPLOY_TLS_VERIFY  optional  boolean, default "true" (strict cert check)
#   DEPLOY_TLS_CA_FILE optional  extra CA bundle to trust. UNSET in production
#                                 (system trust store). Used only by the CI test
#                                 to trust its self-signed certificate while
#                                 keeping verify-certificate = true.
#   DEPLOY_LOCAL_DIR   optional  local build to upload, default "deploy-root"
#   DEPLOY_REMOTE_BASE optional  remote base directory, default "."
#
# Modes:
#   (none)          generate the script to a temp file and run lftp against it
#   --print-script  write the generated script to stdout and exit (no lftp run)
#
# Explicit FTPS only: ftp:// + `set ftp:ssl-force true` makes lftp refuse a
# plaintext session rather than downgrade. The remote root is never listed or
# mirrored; only the allowlisted directories/files below are touched.

set -euo pipefail

# --- the deployment allowlist - the only paths this deploy ever touches -------
ALLOW_DIRS="public src config bin database vendor"
ALLOW_FILES="composer.json composer.lock README.md .env.production.example"

MODE="${1:-run}"
[ -n "$MODE" ] || MODE=run   # tolerate an empty first argument

err() { echo "::error::lftp-deploy: $*" >&2; exit 1; }

# --- sanitise + character-whitelist every value interpolated into the script --
# Trailing newlines/CR in a pasted secret previously broke the URL; whitespace
# is stripped and the character sets below cannot carry a quote, space, newline,
# ';', '&&', '||' or any other lftp control token into the generated script.
host="$(printf '%s' "${DEPLOY_HOST:-}"     | tr -d '[:space:]')"
port="$(printf '%s' "${DEPLOY_PORT:-}"     | tr -d '[:space:]')"
user="$(printf '%s' "${DEPLOY_USERNAME:-}" | tr -d '[:space:]')"
verify="$(printf '%s' "${DEPLOY_TLS_VERIFY:-true}" | tr -d '[:space:]')"
ca_file="${DEPLOY_TLS_CA_FILE:-}"
local_dir="${DEPLOY_LOCAL_DIR:-deploy-root}"
remote_base="${DEPLOY_REMOTE_BASE:-.}"

: "${host:?DEPLOY_HOST is empty}"
: "${port:?DEPLOY_PORT is empty}"
: "${user:?DEPLOY_USERNAME is empty}"
: "${LFTP_PASSWORD:?LFTP_PASSWORD is empty}"

case "$host"        in *[!A-Za-z0-9.-]*)    err "DEPLOY_HOST has unexpected characters after trimming" ;; esac
case "$port"        in ''|*[!0-9]*)         err "DEPLOY_PORT must be numeric" ;; esac
case "$user"        in *[!A-Za-z0-9._@-]*)  err "DEPLOY_USERNAME has unexpected characters after trimming" ;; esac
case "$verify"      in true|false|yes|no|on|off) ;; *) err "DEPLOY_TLS_VERIFY must be a boolean" ;; esac
case "$local_dir"   in *[!A-Za-z0-9._/-]*)  err "DEPLOY_LOCAL_DIR has unexpected characters" ;; esac
case "$remote_base" in *[!A-Za-z0-9._/-]*)  err "DEPLOY_REMOTE_BASE has unexpected characters" ;; esac
if [ -n "$ca_file" ]; then
  case "$ca_file" in *[!A-Za-z0-9._/-]*) err "DEPLOY_TLS_CA_FILE has unexpected characters" ;; esac
fi

url="ftp://${host}:${port}"

emit_script() {
  echo "set cmd:fail-exit yes"
  echo "set net:max-retries 2"
  echo "set net:timeout 20"
  echo "set net:reconnect-interval-base 5"
  echo "set ftp:ssl-force true"
  echo "set ftp:ssl-protect-data true"
  echo "set ftp:ssl-protect-list true"
  echo "set ssl:verify-certificate ${verify}"
  if [ -n "$ca_file" ]; then
    echo "set ssl:ca-file \"${ca_file}\""
  fi
  # Connect in script mode. The password comes from $LFTP_PASSWORD via
  # --env-password and is never written here.
  echo "open -u \"${user}\" --env-password \"${url}\""
  # Connection + auth + TLS check before any transfer. 'pwd' opens the control
  # connection; it does not list or mirror the remote root.
  echo "pwd"
  # Status line: plain text only. No ';' '&&' '||' - ';' is an lftp separator.
  echo "echo == FTPS session established - starting uploads =="
  # Managed code directories only: upload and prune stale files INSIDE each
  # named directory (--delete). The remote root itself is never mirrored.
  for d in $ALLOW_DIRS; do
    echo "mirror -R --delete --no-perms --verbose --exclude-glob .DS_Store \"${local_dir}/${d}/\" \"${remote_base}/${d}/\""
  done
  # Individual top-level runtime files: upload only, never delete anything.
  for f in $ALLOW_FILES; do
    echo "put -O \"${remote_base}/\" \"${local_dir}/${f}\""
  done
  echo "echo == all uploads completed =="
  echo "bye"
}

if [ "$MODE" = "--print-script" ]; then
  emit_script
  exit 0
fi
[ "$MODE" = "run" ] || err "unknown mode: $MODE (use no arg, or --print-script)"

# --- run mode ----------------------------------------------------------------
[ -d "$local_dir" ] || err "local build directory not found: $local_dir"
if [ -n "$ca_file" ] && [ ! -f "$ca_file" ]; then
  err "DEPLOY_TLS_CA_FILE not found: $ca_file"
fi
command -v lftp >/dev/null 2>&1 || err "lftp is not installed"

script="$(mktemp)"
trap 'rm -f "$script"' EXIT
emit_script > "$script"

echo "Deploying over explicit FTPS to port ${port} (verify-certificate=${verify}${ca_file:+, custom CA bundle})"
lftp --norc -f "$script"
echo "FTPS deploy finished OK."
