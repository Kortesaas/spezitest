#!/usr/bin/env sh
# Lightweight static checks for the FTPS deploy path. No network, no lftp binary.
#
# Deployment logic lives in one place - tools/deploy/lftp-deploy.sh - which both
# production (.github/workflows/deploy.yml) and this checker call. This renders
# the script with `--print-script` and asserts the essentials:
#
#   1. exactly one valid `open` command
#   2. no password value in the script
#   3. no lftp command separator (';' '&&' '||') or substitution ('`' '$(' ) in
#      ANY generated line
#   4. mirror/put targets are exactly the allowlist; no `.env` / `var/` reference
#   5. explicit FTPS + strict certificate verification are in the script
#   6. deploy.yml calls the shared helper (no inline lftp) and the
#      `production-deployed` tag + summary steps are gated on
#      `success() && env.UPLOAD_OK == '1'`, set only after the helper call
#
# Exit non-zero on the first failed assertion.

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
WF="$ROOT/.github/workflows/deploy.yml"
HELPER="$ROOT/tools/deploy/lftp-deploy.sh"
[ -f "$WF" ]     || { echo "FAIL: $WF missing" >&2; exit 1; }
[ -f "$HELPER" ] || { echo "FAIL: $HELPER missing" >&2; exit 1; }

fail() { echo "FAIL: $*" >&2; exit 1; }
ok()   { echo "ok  : $*"; }

PW='PLACEHOLDER-not-a-real-secret'
s=$(mktemp); trap 'rm -f "$s"' EXIT
DEPLOY_HOST=deploy.example DEPLOY_PORT=21 DEPLOY_USERNAME=spezitest-deploy \
LFTP_PASSWORD="$PW" DEPLOY_TLS_VERIFY=true \
  bash "$HELPER" --print-script > "$s" || fail "helper --print-script failed"

# 1. exactly one valid open
[ "$(grep -c '^open ' "$s")" = 1 ] || fail "expected exactly one 'open' line"
grep -Eq '^open -u "[A-Za-z0-9._@-]+" --env-password "ftp://[A-Za-z0-9.-]+:[0-9]+"$' "$s" \
  || fail "open line not in the safe form: $(grep '^open ' "$s")"
ok "one valid open command: $(grep '^open ' "$s")"

# 2. no password value
grep -Fq "$PW" "$s" && fail "password value present in the generated script"
ok "no password in the generated script"

# 3. no lftp command separators / substitution in any generated line
grep -nE '(;|&&|\|\|)' "$s" && fail "a generated line contains an lftp command separator"
grep -nE '\$\(|`' "$s" && fail "a generated line contains a command substitution"
ok "no ';' '&&' '||' '\$(' '\`' in any generated line"

# 4. allowlist only
mdirs=$(grep '^mirror ' "$s" | sed -E 's#.*"[^"]*/([A-Za-z]+)/" "\./[A-Za-z]+/"$#\1#' | LC_ALL=C sort | tr '\n' ' ')
[ "$mdirs" = "bin config database public src vendor " ] || fail "mirror dirs not the allowlist: [$mdirs]"
pfiles=$(grep '^put ' "$s" | sed -E 's#.*/([^/"]+)"$#\1#' | LC_ALL=C sort | tr '\n' ' ')
[ "$pfiles" = ".env.production.example README.md composer.json composer.lock " ] || fail "put files not the allowlist: [$pfiles]"
grep -Eiq 'mirror[^"]*"[^"]*/(var|\.env)/"' "$s" && fail "a mirror target references .env or var/"
ok "mirror + put targets are exactly the allowlist (6 dirs + 4 files)"

# 5. explicit FTPS + strict verification
grep -q '^set ftp:ssl-force true$' "$s" || fail "explicit FTPS (ssl-force) missing from the script"
grep -q '^set ssl:verify-certificate true$' "$s" || fail "TLS certificate verification missing/disabled in the script"
ok "explicit FTPS + strict certificate verification present"

# 6. workflow uses the helper; UPLOAD_OK gate intact
nc=$(grep -vE '^[[:space:]]*#' "$WF")
printf '%s\n' "$nc" | grep -Eq 'bash tools/deploy/lftp-deploy\.sh' \
  || fail "deploy.yml does not call 'bash tools/deploy/lftp-deploy.sh'"
printf '%s\n' "$nc" | sed 's/^[[:space:]]*//' | grep -Eq '^lftp[[:space:]]' \
  && fail "deploy.yml invokes lftp inline (should only be inside the helper)"
[ "$(grep -cE "if: success\(\) && env\.UPLOAD_OK == '1'" "$WF")" -ge 2 ] \
  || fail "tag/summary steps are not both gated on env.UPLOAD_OK"
grep -Fq 'echo "UPLOAD_OK=1" >> "$GITHUB_ENV"' "$WF" || fail "UPLOAD_OK is never set"
awk '/bash tools\/deploy\/lftp-deploy\.sh/ { s=1 }
     /UPLOAD_OK=1/ { if (!s) { print "UPLOAD_OK set before the helper call"; exit 1 } }' "$WF" \
  || fail "UPLOAD_OK is set before the deploy helper call"
grep -Eq 'git (tag|push).*production-deployed' "$WF" || fail "production-deployed tag step missing"
ok "deploy.yml uses the helper; production-deployed tag + summary gated on UPLOAD_OK after upload"

echo "ALL DEPLOY STATIC CHECKS PASSED"
