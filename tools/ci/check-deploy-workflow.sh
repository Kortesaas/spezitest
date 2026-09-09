#!/usr/bin/env sh
# Static safety checks for the FTPS deployment path. No network, no lftp binary.
#
# Deployment logic lives in ONE place - tools/deploy/lftp-deploy.sh - which both
# production (.github/workflows/deploy.yml) and the end-to-end test
# (tools/ci/ftps-deploy-e2e.sh) call. This checker renders the generated lftp
# script with `--print-script` and asserts:
#
#   1. exactly one valid `open` command
#   2. no password value / no password embedded in `open -u user,pass`
#   3. no lftp command separators (';' '&&' '||') or command substitution
#      ('`' , '$(' ) in ANY generated line - echo/status lines included
#   4. mirror/put targets are exactly the allowlist; no remote-root mirror,
#      no `.env` / `var/` reference
#   5. the workflow calls the shared helper (not an inline lftp), and the helper
#      invokes lftp only as `lftp --norc -f "$script"`
#   6. the `production-deployed` tag + summary steps are gated on
#      `success() && env.UPLOAD_OK == '1'`, set only after the helper call
#
# Exit non-zero on the first failed assertion.

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
WF="$ROOT/.github/workflows/deploy.yml"
HELPER="$ROOT/tools/deploy/lftp-deploy.sh"
[ -f "$WF" ]     || { echo "FAIL: $WF not found" >&2; exit 1; }
[ -f "$HELPER" ] || { echo "FAIL: $HELPER not found" >&2; exit 1; }

fail() { echo "FAIL: $*" >&2; exit 1; }
ok()   { echo "ok  : $*"; }

ALLOW_DIRS="bin config database public src vendor"                       # sorted
ALLOW_FILES=".env.production.example README.md composer.json composer.lock"  # C-sorted

# --- render the generated script exactly as production would ----------------
STANDIN_PW='PLACEHOLDER-not-a-real-secret-9Zx'
script=$(mktemp); trap 'rm -f "$script"' EXIT
DEPLOY_HOST=web01.example.test \
DEPLOY_PORT=21 \
DEPLOY_USERNAME=spezitest-deploy \
LFTP_PASSWORD="$STANDIN_PW" \
DEPLOY_TLS_VERIFY=true \
  bash "$HELPER" --print-script > "$script" || fail "helper --print-script failed"

# 1. exactly one valid open
opens=$(grep -c '^open ' "$script" || true)
[ "$opens" = "1" ] || fail "expected exactly one 'open' line, found $opens"
grep -Eq '^open -u "[A-Za-z0-9._@-]+" --env-password "ftp://[A-Za-z0-9.-]+:[0-9]+"$' "$script" \
  || fail "open line not in the expected safe form: $(grep '^open ' "$script")"
ok "one valid open command: $(grep '^open ' "$script")"

# 2. no password
grep -Fq "$STANDIN_PW" "$script" && fail "password VALUE present in generated script"
grep -Eq 'open[^\n]* -u +"?[^" ]+,' "$script" && fail "password embedded in 'open -u user,pass'"
if grep -oE '[A-Za-z_-]*[Pp]ass[A-Za-z_-]*' "$script" | grep -qxv -- '--env-password'; then
  fail "unexpected password-related token in generated script"
fi
ok "no password value; only the --env-password mechanism"

# 3. no lftp command separators / substitution anywhere in the script
if grep -Eq '(;|&&|\|\|)' "$script"; then
  fail "generated script line contains an lftp command separator: $(grep -nE '(;|&&|\|\|)' "$script" | head -1)"
fi
if grep -Eq '\$\(|`' "$script"; then
  fail "generated script line contains a command substitution: $(grep -nE '\$\(|`' "$script" | head -1)"
fi
if LC_ALL=C grep -q '[^[:print:][:space:]]' "$script"; then
  fail "generated script contains control / non-printable characters"
fi
ok "no ';' '&&' '||' '\`' '\$(' and no control chars in any generated line"

# 4. allowlist only, no root mirror, no .env / var reference
mdirs=$(grep '^mirror ' "$script" | sed -E 's#.*"[^"]*/([A-Za-z]+)/" "\./[A-Za-z]+/".*#\1#' | LC_ALL=C sort | tr '\n' ' ')
[ "$mdirs" = "$(printf '%s ' $ALLOW_DIRS)" ] || fail "mirror dirs [$mdirs] != allowlist [$(printf '%s ' $ALLOW_DIRS)]"
pfiles=$(grep '^put ' "$script" | sed -E 's#.*/([^/"]+)"$#\1#' | LC_ALL=C sort | tr '\n' ' ')
[ "$pfiles" = "$(printf '%s ' $ALLOW_FILES)" ] || fail "put files [$pfiles] != allowlist [$(printf '%s ' $ALLOW_FILES)]"
grep -Eq 'mirror[^"]*"\.?/?" ' "$script" && fail "generated script mirrors the remote root"
grep -Eiq 'mirror[^"]*"[^"]*/(var|\.env)/"' "$script" && fail "a mirror target references .env or var/"
grep -q 'set ftp:ssl-force true' "$script" || fail "explicit FTPS (ssl-force) missing from the script"
grep -Eq 'set ssl:verify-certificate (true|yes|on)' "$script" || fail "certificate verification missing from the script"
ok "mirror + put targets are exactly the allowlist; explicit FTPS + cert verification present"

# 5. workflow uses the shared helper; helper invokes lftp only in script mode
wf_noncomment=$(grep -vE '^[[:space:]]*#' "$WF")
printf '%s\n' "$wf_noncomment" | grep -Eq 'bash tools/deploy/lftp-deploy\.sh( |$)' \
  || fail "deploy.yml does not call the shared helper 'bash tools/deploy/lftp-deploy.sh'"
printf '%s\n' "$wf_noncomment" | sed 's/^[[:space:]]*//' | grep -Eq '^lftp[[:space:]]' \
  && fail "deploy.yml still invokes lftp inline (should be only inside the helper)"
hp_noncomment=$(grep -vE '^[[:space:]]*#' "$HELPER")
inv=$(printf '%s\n' "$hp_noncomment" | sed 's/^[[:space:]]*//' | grep -E '^lftp[[:space:]]')
[ "$(printf '%s\n' "$inv" | grep -c .)" = "1" ] || fail "helper must invoke lftp exactly once, got:
$inv"
printf '%s\n' "$inv" | grep -Eq '^lftp --norc -f "\$script"$' \
  || fail "helper lftp invocation is not 'lftp --norc -f \"\$script\"': $inv"
printf '%s\n' "$inv" | grep -Eq -- '(-u |--user|--env-password|ftp://|,)' \
  && fail "helper lftp command line carries host/user/site/password: $inv"
ok "workflow calls the shared helper; helper runs 'lftp --norc -f \"\$script\"' only"

# 6. tag + summary steps gated on UPLOAD_OK, set only after the helper call
gated=$(grep -cE "if: success\(\) && env\.UPLOAD_OK == '1'" "$WF" || true)
[ "$gated" -ge 2 ] || fail "expected >=2 steps gated on UPLOAD_OK (tag + summary), found $gated"
grep -Fq 'echo "UPLOAD_OK=1" >> "$GITHUB_ENV"' "$WF" || fail "UPLOAD_OK is never set"
awk '
  /bash tools\/deploy\/lftp-deploy\.sh/ { seen = 1 }
  /UPLOAD_OK=1/ { if (!seen) { print "UPLOAD_OK set before the helper call"; exit 1 } }
' "$WF" || fail "UPLOAD_OK is set before the deploy helper call"
grep -Eq 'git (tag|push).*production-deployed' "$WF" || fail "production-deployed tag step missing"
ok "production-deployed tag + summary gated on UPLOAD_OK (set only after a successful deploy)"

echo "ALL DEPLOY-WORKFLOW STATIC CHECKS PASSED"
