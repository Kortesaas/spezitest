#!/usr/bin/env bash
#
# End-to-end test of the production FTPS deployment path.
#
# Brings up a DISPOSABLE local vsftpd server with:
#   * explicit FTPS (AUTH TLS), plaintext refused
#   * a self-signed test certificate (SAN 127.0.0.1 / localhost)
#   * a throw-away username / password (NOT a secret, NOT the production host)
#   * an isolated fake application root pre-populated with .env, var/ and
#     stale files inside managed directories
#
# then runs the SAME generator + invocation used in production
# (tools/deploy/lftp-deploy.sh -> lftp --norc -f "$script") against it and
# asserts the deployment did exactly what it must and nothing else.
#
# Requires: sudo, apt (vsftpd, lftp, openssl). Designed for ubuntu-latest.

set -euo pipefail

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
HELPER="$ROOT/tools/deploy/lftp-deploy.sh"
[ -f "$HELPER" ] || { echo "FAIL: $HELPER missing" >&2; exit 1; }

WORK="$(mktemp -d)"
REMOTE="$WORK/remote"          # fake application root == ftp user home
SRC="$WORK/deploy-root"        # local build to upload
CERT="$WORK/cert.pem"
KEY="$WORK/key.pem"
CONF="$WORK/vsftpd.conf"
GH_ENV="$WORK/github_env"      # stand-in for $GITHUB_ENV
PORT=2121
FTP_USER="spezideployci"
FTP_PASS="e2e-Local-Passw0rd_"   # disposable; only ever used against 127.0.0.1

pass() { echo "  ok  : $*"; }
fail() { echo "ASSERT FAIL: $*" >&2; exit 1; }

cleanup() {
  sudo pkill -f "vsftpd $CONF" 2>/dev/null || true
  sudo userdel "$FTP_USER" 2>/dev/null || true
  sudo rm -rf "$WORK" 2>/dev/null || rm -rf "$WORK" || true
}
trap cleanup EXIT

echo "== install vsftpd / lftp / openssl (same lftp family as production) =="
sudo apt-get update -qq
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq vsftpd lftp openssl >/dev/null
lftp --version 2>&1 | head -1 || true

echo "== self-signed test certificate =="
openssl req -x509 -newkey rsa:2048 -nodes -days 2 \
  -keyout "$KEY" -out "$CERT" \
  -subj "/CN=127.0.0.1" \
  -addext "subjectAltName=IP:127.0.0.1,DNS:localhost" >/dev/null 2>&1
chmod 600 "$KEY"

echo "== fake remote application root (pre-existing production state) =="
mkdir -p "$REMOTE/public" "$REMOTE/src" \
         "$REMOTE/var/admin-images" "$REMOTE/var/legacy-images"
printf 'APP_ENV=production\nDB_PASSWORD=keep-this-exact\nAPP_URL=https://www.spezitest.de\n' > "$REMOTE/.env"
printf 'admin upload - must survive\n'  > "$REMOTE/var/admin-images/test.txt"
printf 'legacy image - must survive\n'  > "$REMOTE/var/legacy-images/test.txt"
printf 'OLD stale public asset\n'       > "$REMOTE/public/stale-file.txt"
printf '<?php // OLD stale source\n'    > "$REMOTE/src/stale-file.php"
printf 'unrelated root file - must survive\n' > "$REMOTE/keep-me.txt"

# checksums of everything that must NOT change
tree_hashes() { ( cd "$1" && find . -type f -print0 | sort -z | xargs -0 sha256sum ); }
ENV_BEFORE="$(sha256sum "$REMOTE/.env" | cut -d' ' -f1)"
VAR_BEFORE="$(tree_hashes "$REMOTE/var")"
KEEP_BEFORE="$(sha256sum "$REMOTE/keep-me.txt" | cut -d' ' -f1)"

echo "== local build to deploy =="
mkdir -p "$SRC"/public "$SRC"/src "$SRC"/config "$SRC"/bin "$SRC"/database "$SRC"/vendor
printf '<?php // NEW index\n'      > "$SRC/public/index.php"
printf 'body{color:#111}\n'       > "$SRC/public/app.css"
printf '<?php // NEW application\n'> "$SRC/src/App.php"
printf '<?php return [];\n'       > "$SRC/config/app.php"
printf '<?php // migrate\n'       > "$SRC/bin/migrate.php"
printf 'CREATE TABLE t (id INT);\n' > "$SRC/database/0001_init.sql"
printf '<?php // autoload\n'      > "$SRC/vendor/autoload.php"
for f in composer.json composer.lock README.md .env.production.example; do
  printf 'NEW %s\n' "$f" > "$SRC/$f"
done

echo "== vsftpd config: explicit FTPS, no chroot (user home == fake root) =="
cat > "$CONF" <<EOF
listen=YES
listen_ipv6=NO
listen_port=$PORT
local_enable=YES
write_enable=YES
dirmessage_enable=NO
xferlog_enable=NO
chroot_local_user=NO
seccomp_sandbox=NO
pasv_enable=YES
pasv_address=127.0.0.1
pasv_min_port=30000
pasv_max_port=30009
ssl_enable=YES
force_local_logins_ssl=YES
force_local_data_ssl=YES
require_ssl_reuse=NO
ssl_ciphers=HIGH
ssl_tlsv1_2=YES
ssl_sslv2=NO
ssl_sslv3=NO
rsa_cert_file=$CERT
rsa_private_key_file=$KEY
secure_chroot_dir=/var/run/vsftpd/empty
pam_service_name=vsftpd
EOF

sudo mkdir -p /var/run/vsftpd/empty
sudo useradd -M -d "$REMOTE" -s /bin/bash "$FTP_USER"
echo "$FTP_USER:$FTP_PASS" | sudo chpasswd
sudo chown -R "$FTP_USER":"$FTP_USER" "$REMOTE"
chmod 755 "$WORK"

echo "== start vsftpd =="
sudo systemctl stop vsftpd 2>/dev/null || true
sudo vsftpd "$CONF" >"$WORK/vsftpd.log" 2>&1 &
for _ in $(seq 1 20); do
  if bash -c ": >/dev/tcp/127.0.0.1/$PORT" 2>/dev/null; then break; fi
  sleep 0.5
done
if ! bash -c ": >/dev/tcp/127.0.0.1/$PORT" 2>/dev/null; then
  echo "--- vsftpd.log ---"; cat "$WORK/vsftpd.log" || true
  fail "vsftpd did not start on port $PORT"
fi
pass "disposable FTPS server listening on 127.0.0.1:$PORT"

# common env for the production helper (127.0.0.1 + test CA, strict verify)
#   run_deploy [password] [mode]
run_deploy() {
  _pw="${1:-$FTP_PASS}"
  _mode="${2:-run}"
  env \
    DEPLOY_HOST=localhost \
    DEPLOY_PORT="$PORT" \
    DEPLOY_USERNAME="$FTP_USER" \
    LFTP_PASSWORD="$_pw" \
    DEPLOY_TLS_VERIFY=true \
    DEPLOY_TLS_CA_FILE="$CERT" \
    DEPLOY_LOCAL_DIR="$SRC" \
    DEPLOY_REMOTE_BASE=. \
    bash "$HELPER" "$_mode"
}

echo
echo "== 4. assertions on the exact generated lftp script =="
GEN="$WORK/generated.lftp"
run_deploy "$FTP_PASS" --print-script > "$GEN"
cat "$GEN" | sed 's/^/    | /'
[ "$(grep -c '^open ' "$GEN")" -eq 1 ] || fail "generated script: expected exactly 1 'open', got $(grep -c '^open ' "$GEN")"
grep -Eq '^open -u "[A-Za-z0-9._@-]+" --env-password "ftp://[A-Za-z0-9.-]+:[0-9]+"$' "$GEN" \
  || fail "generated script: 'open' not in the safe form"
pass "exactly one open command, safe form"
grep -Fq "$FTP_PASS" "$GEN" && fail "generated script contains the password" || pass "no password in the generated script"
if grep -Eq '(;|&&|\|\|)' "$GEN"; then fail "generated script contains an lftp command separator (; && ||)"; fi
pass "no lftp command separators (; && ||) in the generated script"
if LC_ALL=C grep -q '[^[:print:][:space:]]' "$GEN"; then fail "generated script contains control / non-printable characters"; fi
pass "generated script is printable ASCII, one command per line"

# mirror + put targets must be EXACTLY the allowlist
mdirs="$(grep '^mirror ' "$GEN" | sed -E 's#.*"[^"]*/([A-Za-z]+)/" "[^"]+".*#\1#' | LC_ALL=C sort | tr '\n' ' ')"
[ "$mdirs" = "bin config database public src vendor " ] \
  || fail "mirror directories are not exactly the allowlist: [$mdirs]"
pfiles="$(grep '^put ' "$GEN" | sed -E 's#.*/([^/"]+)"$#\1#' | LC_ALL=C sort | tr '\n' ' ')"
[ "$pfiles" = ".env.production.example README.md composer.json composer.lock " ] \
  || fail "put files are not exactly the allowlist: [$pfiles]"
pass "mirror + put targets are exactly the deployment allowlist (6 dirs + 4 files)"

if grep -Eq 'mirror[^"]*"\.?/?" ' "$GEN"; then fail "generated script mirrors the remote root"; fi
pass "no remote-root mirror"
if grep -Eiq 'mirror[^"]*"[^"]*/(var|\.env)/"' "$GEN"; then fail "a mirror target references .env or var/"; fi
pass "no .env / var/ reference in any mirror target"
grep -q 'set ftp:ssl-force true' "$GEN"          || fail "explicit FTPS (ssl-force) missing from the script"
grep -Eq 'set ssl:verify-certificate (true|yes|on)' "$GEN" || fail "certificate verification not enabled in the script"
pass "explicit FTPS + strict certificate verification present in the generated script"

echo
echo "== 3. run the real deployment against the disposable server =="
# deploy_and_gate mirrors deploy.yml exactly:
#   set -e ; bash tools/deploy/lftp-deploy.sh ; echo "UPLOAD_OK=1" >> "$GITHUB_ENV"
# i.e. UPLOAD_OK is written only if the helper exited 0.
deploy_and_gate() {  # $1 = password
  : > "$GH_ENV"
  set +e
  run_deploy "$1"
  _rc=$?
  set -e
  if [ "$_rc" -eq 0 ]; then echo "UPLOAD_OK=1" >> "$GH_ENV"; fi
  return "$_rc"
}

set +e
deploy_and_gate "$FTP_PASS"
RC=$?
set -e
[ "$RC" -eq 0 ] || fail "deployment returned non-zero ($RC)"
pass "connection + authentication + TLS verification succeeded; deploy exit 0"

echo
echo "== post-deployment assertions =="
for d in public src config bin database vendor; do
  [ -d "$REMOTE/$d" ] || fail "managed directory '$d' was not deployed"
done
pass "all 6 managed directories present on the server"
grep -q 'NEW index'       "$REMOTE/public/index.php"  || fail "public/index.php missing or stale"
grep -q 'NEW application'  "$REMOTE/src/App.php"       || fail "src/App.php missing or stale"
[ -f "$REMOTE/public/app.css" ]        || fail "public/app.css not uploaded"
[ -f "$REMOTE/config/app.php" ]        || fail "config/app.php not uploaded"
[ -f "$REMOTE/bin/migrate.php" ]       || fail "bin/migrate.php not uploaded"
[ -f "$REMOTE/database/0001_init.sql" ]|| fail "database/0001_init.sql not uploaded"
[ -f "$REMOTE/vendor/autoload.php" ]   || fail "vendor/autoload.php not uploaded"
pass "managed directory contents uploaded"
for f in composer.json composer.lock README.md .env.production.example; do
  [ -f "$REMOTE/$f" ]        || fail "top-level file '$f' not uploaded"
  grep -q "NEW $f" "$REMOTE/$f" || fail "top-level file '$f' has wrong content"
done
pass "all 4 top-level managed files uploaded"
[ ! -e "$REMOTE/public/stale-file.txt" ] || fail "public/stale-file.txt NOT removed by --delete"
[ ! -e "$REMOTE/src/stale-file.php" ]    || fail "src/stale-file.php NOT removed by --delete"
pass "stale files inside managed directories removed by --delete"
[ -f "$REMOTE/.env" ] || fail ".env disappeared"
[ "$(sha256sum "$REMOTE/.env" | cut -d' ' -f1)" = "$ENV_BEFORE" ] || fail ".env content changed"
pass ".env still present and byte-for-byte unchanged"
[ -d "$REMOTE/var" ] || fail "var/ disappeared"
[ "$(tree_hashes "$REMOTE/var")" = "$VAR_BEFORE" ] || fail "var/ tree changed"
pass "var/ unchanged (incl. admin-images/test.txt, legacy-images/test.txt)"
[ -f "$REMOTE/keep-me.txt" ] || fail "unrelated root file keep-me.txt was deleted"
[ "$(sha256sum "$REMOTE/keep-me.txt" | cut -d' ' -f1)" = "$KEEP_BEFORE" ] || fail "keep-me.txt changed"
pass "no unintended root files/directories touched"
got_root="$(cd "$REMOTE" && LC_ALL=C ls -A | LC_ALL=C sort | tr '\n' ' ')"
want_root="$(printf '%s\n' .env .env.production.example README.md bin composer.json composer.lock config database keep-me.txt public src var vendor | LC_ALL=C sort | tr '\n' ' ')"
[ "$got_root" = "$want_root" ] || fail "remote root layout unexpected:
  got : $got_root
  want: $want_root"
pass "remote root contains exactly the pre-existing + allowlisted entries"

echo
echo "== UPLOAD_OK gate: success path =="
set +e
deploy_and_gate "$FTP_PASS"
RC=$?
set -e
[ "$RC" -eq 0 ] || fail "success-path deploy returned non-zero ($RC)"
grep -qx 'UPLOAD_OK=1' "$GH_ENV" || fail "UPLOAD_OK not written after a successful deploy"
pass "UPLOAD_OK=1 written only after a fully successful deploy"

echo
echo "== failure path A: wrong password -> non-zero, UPLOAD_OK stays unset =="
set +e
deploy_and_gate "definitely-the-wrong-password"
RC=$?
set -e
[ "$RC" -ne 0 ] || fail "deploy with a wrong password returned exit 0"
grep -qx 'UPLOAD_OK=1' "$GH_ENV" && fail "UPLOAD_OK set despite a failed deploy"
pass "auth failure -> non-zero exit, UPLOAD_OK not set"

echo
echo "== failure path B: server unreachable mid-run -> non-zero =="
set +e
env DEPLOY_HOST=localhost DEPLOY_PORT=59991 DEPLOY_USERNAME="$FTP_USER" \
    LFTP_PASSWORD="$FTP_PASS" DEPLOY_TLS_VERIFY=true DEPLOY_TLS_CA_FILE="$CERT" \
    DEPLOY_LOCAL_DIR="$SRC" bash "$HELPER"
RC=$?
set -e
[ "$RC" -ne 0 ] || fail "deploy against a dead port returned exit 0"
pass "unreachable server -> non-zero exit"

echo
echo "== failure path C: a transfer fails partway -> non-zero, no UPLOAD_OK =="
# Make the last managed source directory unreadable so its mirror fails only
# after the earlier directories have already uploaded.
chmod 000 "$SRC/vendor"
set +e
deploy_and_gate "$FTP_PASS"
RC=$?
set -e
chmod 755 "$SRC/vendor"
[ "$RC" -ne 0 ] || fail "a failed mid-transfer returned exit 0"
grep -qx 'UPLOAD_OK=1' "$GH_ENV" && fail "UPLOAD_OK set despite a mid-transfer failure"
pass "mid-transfer failure -> non-zero exit, UPLOAD_OK not set"

echo
echo "ALL FTPS DEPLOY END-TO-END ASSERTIONS PASSED"
