#!/usr/bin/env sh
# Static safety checks for the FTPS deploy step in
# .github/workflows/deploy.yml. No network and no lftp binary required.
#
# It re-generates the lftp script exactly as the workflow does (with sample,
# already-sanitised host/port/user) and asserts:
#   1. the script contains exactly one valid `open` command
#   2. no password / password reference is present in the script
#   3. the workflow invokes lftp ONLY as `lftp --norc -f "$script"`
#      (no host/user/site/password on the command line)
#   4. every mirror/put target is on the allowlist and nothing touches the
#      remote root, `.env`, `var/`, or a parent directory
#   5. the `production-deployed` tag step and the summary step are gated on
#      `success() && env.UPLOAD_OK == '1'`, and UPLOAD_OK is set only after
#      the lftp call
#
# Exit non-zero on the first failed assertion.

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
WF="$ROOT/.github/workflows/deploy.yml"
[ -f "$WF" ] || { echo "FAIL: $WF not found" >&2; exit 1; }

fail() { echo "FAIL: $*" >&2; exit 1; }
ok()   { echo "ok  : $*"; }

ALLOW_DIRS="public src config bin database vendor"
ALLOW_FILES="composer.json composer.lock README.md .env.production.example"

# --- reproduce the generated lftp script (sanitised sample inputs) ----------
host="web01.st-srv.eu"; port="21"; user="spezitest-deploy"; verify="true"
url="ftp://${host}:${port}"
# A stand-in password value: it must NOT end up anywhere in the script.
LFTP_PASSWORD='PLACEHOLDER-not-a-real-secret-9Zx'
export LFTP_PASSWORD

script=$(mktemp)
trap 'rm -f "$script"' EXIT

{
  echo "set cmd:fail-exit yes"
  echo "set net:max-retries 2"
  echo "set net:timeout 20"
  echo "set net:reconnect-interval-base 5"
  echo "set ftp:ssl-force true"
  echo "set ftp:ssl-protect-data true"
  echo "set ftp:ssl-protect-list true"
  echo "set ssl:verify-certificate ${verify}"
  echo "open -u \"${user}\" --env-password \"${url}\""
  echo "pwd"
  echo "echo == FTPS session established; starting uploads =="
  for d in $ALLOW_DIRS; do
    echo "mirror -R --delete --no-perms --verbose --exclude-glob .DS_Store deploy-root/${d}/ ./${d}/"
  done
  for f in $ALLOW_FILES; do
    echo "put -O ./ deploy-root/${f}"
  done
  echo "echo == all uploads completed =="
  echo "bye"
} > "$script"

# 1. exactly one valid `open`
opens=$(grep -c '^open ' "$script" || true)
[ "$opens" = "1" ] || fail "expected exactly one 'open' line, found $opens"
grep -Eq '^open -u "[A-Za-z0-9._@-]+" --env-password "ftp://[A-Za-z0-9.-]+:[0-9]+"$' "$script" \
  || fail "open line not in the expected safe form: $(grep '^open ' "$script")"
ok "one valid open command: $(grep '^open ' "$script")"

# 2. password absent from the script
grep -Fq "$LFTP_PASSWORD" "$script" && fail "password VALUE present in generated script"
grep -Eq 'LFTP_PASSWORD[ =]' "$script" && fail "LFTP_PASSWORD assigned/expanded in generated script"
grep -Eq 'open[^\n]* -u +"?[^" ]+,' "$script" && fail "password embedded in 'open -u user,pass'"
# the only 'pass'-ish token allowed is the --env-password mechanism itself
if grep -oE '[A-Za-z_-]*[Pp]ass[A-Za-z_-]*' "$script" | grep -qxv -- '--env-password'; then
  fail "unexpected password-related token in generated script"
fi
ok "no password value, no password assignment; only --env-password mechanism"

# 3. lftp invoked only in script mode
#    Look at non-comment lines whose first token (after trimming) is `lftp`.
noncomment=$(grep -vE '^[[:space:]]*#' "$WF")
inv=$(printf '%s\n' "$noncomment" | sed 's/^[[:space:]]*//' | grep -E '^lftp[[:space:]]')
[ "$(printf '%s\n' "$inv" | grep -c .)" = "1" ] || fail "expected exactly one lftp invocation, got:
$inv"
printf '%s\n' "$inv" | grep -Eq '^lftp --norc -f "\$script"$' \
  || fail "lftp invocation is not 'lftp --norc -f \"\$script\"': $inv"
printf '%s\n' "$inv" | grep -Eq -- '(-u |--user|--env-password|ftp://|,)' \
  && fail "lftp command line carries host/user/site/password: $inv"
printf '%s\n' "$noncomment" | sed 's/^[[:space:]]*//' | grep -E '^lftp[[:space:]]' \
  | grep -Eq -- '(-u |--env-password|ftp://)' \
  && fail "an lftp invocation carries connection args on the command line"
ok "lftp invoked only as: $inv"

# 4. allowlist only; never the remote root, .env, var/, or a parent
mdirs=$(grep -oE 'deploy-root/[a-z]+/ \./[a-z]+/' "$script" | sed 's#deploy-root/##; s#/ .*##' | sort -u | tr '\n' ' ')
want_d=$(printf '%s\n' $ALLOW_DIRS | sort -u | tr '\n' ' ')
[ "$mdirs" = "$want_d" ] || fail "mirror dirs [$mdirs] != allowlist [$want_d]"
pfiles=$(grep -oE 'put -O \./ deploy-root/[^ ]+$' "$script" | sed 's#.*deploy-root/##' | sort -u | tr '\n' ' ')
want_f=$(printf '%s\n' $ALLOW_FILES | sort -u | tr '\n' ' ')
[ "$pfiles" = "$want_f" ] || fail "put files [$pfiles] != allowlist [$want_f]"
grep -Eq '(mirror|put|get|rm|mput|mget)[^\n]*(\.\./|/\.env( |$)|/var/)' "$script" \
  && fail "a transfer command references a parent, .env, or var/"
grep -Eq '^(cls|ls|find|mirror|nlist|du)[^-]* \.?/?$' "$script" \
  && fail "a command enumerates or mirrors the remote root"
# same guarantees hold in the real workflow text
grep -Eq 'for d in public src config bin database vendor; do' "$WF" \
  || fail "workflow mirror allowlist changed"
grep -Eq 'for f in composer.json composer.lock README.md .env.production.example; do' "$WF" \
  || fail "workflow file allowlist changed"
ok "mirrors + puts strictly allowlisted; no root/.env/var/ access"

# 5. tag + summary steps gated on UPLOAD_OK, set only after lftp
gated=$(grep -cE "if: success\(\) && env\.UPLOAD_OK == '1'" "$WF" || true)
[ "$gated" -ge 2 ] || fail "expected >=2 steps gated on UPLOAD_OK (tag + summary), found $gated"
grep -Fq 'echo "UPLOAD_OK=1" >> "$GITHUB_ENV"' "$WF" || fail "UPLOAD_OK is never set"
awk '
  /lftp --norc -f "\$script"/ { seen_lftp = 1 }
  /UPLOAD_OK=1/ { if (!seen_lftp) { print "UPLOAD_OK set before the lftp call"; exit 1 } }
' "$WF" || fail "UPLOAD_OK is set before the lftp call"
grep -Eq 'git (tag|push).*production-deployed' "$WF" || fail "production-deployed tag step missing"
ok "production-deployed tag + summary gated on UPLOAD_OK (set only after all transfers)"

echo "ALL DEPLOY-WORKFLOW STATIC CHECKS PASSED"
