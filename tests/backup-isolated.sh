#!/usr/bin/env bash
# Isolated destructive-safety tests for tools/backup.sh.
#
# Creates a throwaway PostgreSQL cluster under $HOME/.cache (an ext filesystem),
# fake data and a temporary age key. It never reads the project database
# configuration, never touches .local/ and never deletes a real backup.
#
# Usage: bash tests/backup-isolated.sh
#
# Requires: php, initdb/pg_ctl/pg_dump/pg_restore, sha256sum, stat, date, mktemp.
# Optional: age + age-keygen (encryption tests are SKIPPED without them),
#           python3 (interactive-terminal tests are SKIPPED without it),
#           setsid (the piped-confirmation test is SKIPPED without it).
set -u

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
BASE="${RT_BACKUP_TEST_BASE:-$HOME/.cache}"
mkdir -p -- "$BASE" || { echo "cannot create test base"; exit 2; }
chmod 700 -- "$BASE" 2>/dev/null || true
WORK="$(mktemp -d "$BASE/rt-backup-test.XXXXXX")" || { echo "cannot create work dir"; exit 2; }

PASS=0
FAIL=0
SKIP=0
pass() { PASS=$((PASS + 1)); printf 'PASS %s\n' "$*"; }
fail() { FAIL=$((FAIL + 1)); printf 'FAIL %s\n' "$*"; }
skip() { SKIP=$((SKIP + 1)); printf 'SKIP %s\n' "$*"; }
check_eq() {
    local name=$1 expected=$2 actual=$3
    if [[ "$expected" == "$actual" ]]; then
        pass "$name"
    else
        fail "$name (expected '$expected', got '$actual')"
    fi
}
check_mode() {
    local name=$1 path=$2 expected=$3
    if [[ -e "$path" ]]; then
        check_eq "$name" "$expected" "$(stat -c '%a' -- "$path")"
    else
        fail "$name (missing: $path)"
    fi
}
check_count() {
    local name=$1 expected=$2 dir=$3 pattern=${4:-*}
    local actual
    actual="$(find "$dir" -maxdepth 1 -type f -name "$pattern" 2>/dev/null | wc -l)"
    check_eq "$name" "$expected" "$actual"
}
trace_value() {
    local key=$1 file=$2
    sed -n "s/^${key}=//p" "$file" 2>/dev/null | head -1
}

find_pg_bin() {
    if [[ -n "${PG_BIN:-}" && -x "$PG_BIN/initdb" ]]; then
        printf '%s' "$PG_BIN"
        return 0
    fi
    local best
    best="$(ls -d /usr/lib/postgresql/*/bin 2>/dev/null | sort -V | tail -1)"
    if [[ -n "$best" && -x "$best/initdb" ]]; then
        printf '%s' "$best"
        return 0
    fi
    if command -v initdb >/dev/null 2>&1; then
        dirname -- "$(command -v initdb)"
        return 0
    fi
    return 1
}

PG_BIN="$(find_pg_bin)" || { echo "SKIP: PostgreSQL server binaries not found"; exit 0; }
for tool in php pg_dump pg_restore sha256sum stat; do
    command -v "$tool" >/dev/null 2>&1 || { echo "SKIP: missing $tool"; exit 0; }
done

PORT="${RT_BACKUP_TEST_PORT:-$((55000 + RANDOM % 4000))}"
DATA="$WORK/pgdata"
SOCK="$WORK/sock"
CFG="$WORK/db.json"
mkdir -p -- "$SOCK"
chmod 700 -- "$WORK" "$SOCK"

cleanup() {
    "$PG_BIN/pg_ctl" -D "$DATA" -m immediate stop >/dev/null 2>&1 || true
    rm -rf -- "$WORK"
}
trap cleanup EXIT

mkdir -p -- "$WORK/logs"
"$PG_BIN/initdb" -D "$DATA" -A trust -U rt_iso --encoding=UTF8 --locale=C \
    >"$WORK/logs/initdb.log" 2>&1 || { echo "SKIP: initdb failed (see log)"; exit 0; }
# Password auth over TCP (scram) while the unix socket stays trust, so the
# credential tests exercise a real passfile lookup without disturbing the rest.
sed -i 's#^host\([[:space:]]\+all[[:space:]]\+all[[:space:]]\+127\.0\.0\.1/32[[:space:]]\+\)trust#host\1scram-sha-256#' \
    "$DATA/pg_hba.conf" 2>/dev/null || true
"$PG_BIN/pg_ctl" -D "$DATA" -l "$WORK/logs/server.log" \
    -o "-p $PORT -k $SOCK -c listen_addresses=127.0.0.1 -c unix_socket_directories=$SOCK" \
    -w start >"$WORK/logs/start.log" 2>&1 \
    || { echo "SKIP: could not start throwaway cluster"; exit 0; }

"$PG_BIN/createdb" -h "$SOCK" -p "$PORT" -U rt_iso rt_iso_db
"$PG_BIN/psql" -h "$SOCK" -p "$PORT" -U rt_iso -d rt_iso_db -v ON_ERROR_STOP=1 <<'SQL' >/dev/null
CREATE TABLE fake_data(id integer PRIMARY KEY, note text NOT NULL);
INSERT INTO fake_data VALUES (1, 'isolated backup test');
SQL

TCP_PW='Rt:p@ss\w0rd!'
HAVE_SCRAM=0
if grep -qE '^host[[:space:]]+all[[:space:]]+all[[:space:]]+127\.0\.0\.1/32[[:space:]]+scram-sha-256$' "$DATA/pg_hba.conf"; then
    if "$PG_BIN/psql" -h "$SOCK" -p "$PORT" -U rt_iso -d rt_iso_db -v ON_ERROR_STOP=1 \
        -v pw="$TCP_PW" <<'SQL' >/dev/null 2>&1
ALTER ROLE rt_iso PASSWORD :'pw';
SQL
    then
        HAVE_SCRAM=1
    fi
fi
if ((HAVE_SCRAM)); then
    [[ "$(PGPASSWORD="$TCP_PW" "$PG_BIN/psql" -h 127.0.0.1 -p "$PORT" -U rt_iso -d rt_iso_db -tAc 'select 1' 2>/dev/null)" == "1" ]] \
        || HAVE_SCRAM=0
fi

REAL_PG_DUMP="$PG_BIN/pg_dump"
[[ -x "$REAL_PG_DUMP" ]] || REAL_PG_DUMP="$(command -v pg_dump)"
mkdir -p "$WORK/bin"
cat >"$WORK/bin/pg_dump" <<'WRAP'
#!/usr/bin/env bash
set -u
{
    printf 'ARGV_BEGIN\n'
    printf '%s\n' "$@"
    printf 'ARGV_END\n'
    printf 'PGPASSFILE=%s\n' "${PGPASSFILE:-UNSET}"
    printf 'PGPASSWORD_STATE=%s\n' "$([[ -n "${PGPASSWORD+x}" ]] && echo set || echo unset)"
    if [[ -n "${PGPASSFILE:-}" && -f "$PGPASSFILE" ]]; then
        printf 'PASSFILE_MODE=%s\n' "$(stat -c '%a' "$PGPASSFILE")"
        printf 'PASSFILE_DIR_MODE=%s\n' "$(stat -c '%a' "$(dirname "$PGPASSFILE")")"
    else
        printf 'PASSFILE_MODE=missing\n'
    fi
} >>"${RT_PGDUMP_TRACE:?}"
sleep 0.4
exec "$RT_REAL_PG_DUMP" "$@"
WRAP
chmod 700 "$WORK/bin/pg_dump"

RT_TEST_PW="$TCP_PW" RT_TEST_PORT="$PORT" php -r '
echo json_encode([
    "PGHOST" => "127.0.0.1",
    "PGPORT" => getenv("RT_TEST_PORT"),
    "PGDATABASE" => "rt_iso_db",
    "PGUSER" => "rt_iso",
    "PGPASSWORD" => getenv("RT_TEST_PW"),
], JSON_UNESCAPED_SLASHES), "\n";
' >"$WORK/tcp.json"
chmod 600 "$WORK/tcp.json"

cat >"$CFG" <<JSON
{"PGHOST":"$SOCK","PGPORT":"$PORT","PGDATABASE":"rt_iso_db","PGUSER":"rt_iso","PGPASSWORD":""}
JSON
chmod 600 -- "$CFG"

backup() { RT_DB_CONFIG="$CFG" bash "$ROOT/tools/backup.sh" "$@"; }

printf '== plaintext backup ==\n'
backup --dry-run --out "$WORK/dry" >"$WORK/logs/dry.log" 2>&1
check_eq "dry-run exit code" 0 "$?"
if [[ -e "$WORK/dry" ]]; then fail "dry-run must not create the backup dir"; else pass "dry-run did not create the backup dir"; fi

backup --out "$WORK/backups" >"$WORK/logs/plain.log" 2>&1
check_eq "plaintext backup exit code" 0 "$?"
DUMP="$(find "$WORK/backups" -maxdepth 1 -type f -name '*.dump' | head -1)"
if [[ -n "$DUMP" && -s "$DUMP" ]]; then pass "dump exists and is not empty"; else fail "dump missing or empty"; fi
check_mode "backup dir is 700" "$WORK/backups" 700
check_mode "dump is 600" "$DUMP" 600
check_mode "dump checksum file is 600" "${DUMP}.sha256" 600
if [[ -n "$DUMP" ]] && sha256sum -c "${DUMP}.sha256" >/dev/null 2>&1; then
    pass "dump checksum verifies against the file"
else
    fail "dump checksum does not verify"
fi
if [[ -n "$DUMP" ]] && "$PG_BIN/pg_restore" -l "$DUMP" >/dev/null 2>&1; then
    pass "pg_restore --list reads the dump"
else
    fail "pg_restore cannot read the dump"
fi

backup --out "$WORK/backups" >"$WORK/logs/plain2.log" 2>&1
check_eq "second backup exit code" 0 "$?"
check_count "second run does not overwrite the first dump" 2 "$WORK/backups" '*.dump'
check_count "plaintext is kept by default" 2 "$WORK/backups" '*.dump'

printf '== filesystem limits ==\n'
DRVFS=""
for candidate in /mnt/d /mnt/c; do
    if [[ -d "$candidate" ]] && [[ "$(stat -f -c '%T' "$candidate" 2>/dev/null)" == v9fs ]]; then
        DRVFS="$candidate"
        break
    fi
done
if [[ -n "$DRVFS" ]]; then
    backup --out "$DRVFS/rt_backup_should_not_exist" >"$WORK/logs/drvfs.log" 2>&1
    rc=$?
    check_eq "DrvFs path is rejected" "rejected" "$([[ $rc -ne 0 ]] && echo rejected || echo accepted)"
    if [[ -e "$DRVFS/rt_backup_should_not_exist" ]]; then fail "DrvFs path was created"; else pass "nothing was created on DrvFs"; fi
else
    skip "no v9fs mount available"
fi

TMPFS=""
for candidate in /dev/shm /tmp; do
    if [[ -d "$candidate" ]] && [[ "$(stat -f -c '%T' "$candidate" 2>/dev/null)" == tmpfs ]]; then
        TMPFS="$candidate"
        break
    fi
done
if [[ -n "$TMPFS" ]]; then
    backup --out "$TMPFS/rt_backup_should_not_exist" >"$WORK/logs/tmpfs.log" 2>&1
    rc=$?
    check_eq "tmpfs path is rejected" "rejected" "$([[ $rc -ne 0 ]] && echo rejected || echo accepted)"
    if [[ -e "$TMPFS/rt_backup_should_not_exist" ]]; then fail "tmpfs path was created"; else pass "nothing was created on tmpfs"; fi
else
    skip "no tmpfs mount available"
fi

printf '== failure handling ==\n'
cat >"$WORK/closed.json" <<JSON
{"PGHOST":"127.0.0.1","PGPORT":"1","PGDATABASE":"nodb","PGUSER":"nouser","PGPASSWORD":"S3CRET-MARKER-123"}
JSON
chmod 600 -- "$WORK/closed.json"
RT_DB_CONFIG="$WORK/closed.json" bash "$ROOT/tools/backup.sh" --out "$WORK/failed" \
    >"$WORK/logs/fail.log" 2>&1
rc=$?
check_eq "pg_dump failure is reported" "failed" "$([[ $rc -ne 0 ]] && echo failed || echo ignored)"
check_count "no partial dump is left after pg_dump failure" 0 "$WORK/failed" '*'
if grep -q 'S3CRET-MARKER-123' "$WORK/logs/fail.log"; then
    fail "database password leaked into the failure log"
else
    pass "database password is absent from the failure log"
fi
RT_DB_CONFIG="$WORK/closed.json" bash "$ROOT/tools/backup.sh" --dry-run --out "$WORK/dry2" \
    >"$WORK/logs/dry_secret.log" 2>&1
if grep -q 'S3CRET-MARKER-123' "$WORK/logs/dry_secret.log"; then
    fail "database password leaked into dry-run output"
else
    pass "database password is absent from dry-run output"
fi

printf '== verification failure must not delete the dump ==\n'
mkdir -p "$WORK/bin_badrestore"
cat >"$WORK/bin_badrestore/pg_restore" <<'WRAP'
#!/usr/bin/env bash
exit 1
WRAP
chmod 700 "$WORK/bin_badrestore/pg_restore"
env PATH="$WORK/bin_badrestore:$PATH" bash "$ROOT/tools/backup.sh" --out "$WORK/badrestore" \
    >"$WORK/logs/badrestore.log" 2>&1
rc=$?
check_eq "pg_restore failure fails the run" "failed" "$([[ $rc -ne 0 ]] && echo failed || echo ignored)"
check_count "complete dump survives a pg_restore failure" 1 "$WORK/badrestore" '*.dump'
check_count "unverified dump keeps no checksum file" 0 "$WORK/badrestore" '*.sha256'
if grep -q 'PLAINTEXT_UNVERIFIED=' "$WORK/logs/badrestore.log"; then
    pass "unverified dump is reported explicitly"
else
    fail "unverified dump is not reported"
fi

REAL_SHA256="$(command -v sha256sum)"
mkdir -p "$WORK/bin_badsum"
cat >"$WORK/bin_badsum/sha256sum" <<WRAP
#!/usr/bin/env bash
if [[ ! -e "\${RT_SUM_STATE:?}" ]]; then
    : >"\${RT_SUM_STATE}"
    printf '%s  %s\n' "\$(printf '0%.0s' {1..64})" "\${!#}"
    exit 0
fi
exec "$REAL_SHA256" "\$@"
WRAP
chmod 700 "$WORK/bin_badsum/sha256sum"
env PATH="$WORK/bin_badsum:$PATH" RT_SUM_STATE="$WORK/logs/sum-state" \
    bash "$ROOT/tools/backup.sh" --out "$WORK/badsum" >"$WORK/logs/badsum.log" 2>&1
rc=$?
check_eq "checksum mismatch fails the run" "failed" "$([[ $rc -ne 0 ]] && echo failed || echo ignored)"
check_count "complete dump survives a checksum mismatch" 1 "$WORK/badsum" '*.dump'
check_count "mismatched dump keeps no checksum file" 0 "$WORK/badsum" '*.sha256'
if grep -q 'PLAINTEXT_UNVERIFIED=' "$WORK/logs/badsum.log"; then
    pass "checksum mismatch is reported explicitly"
else
    fail "checksum mismatch is not reported"
fi

printf '== temp dir limits for credentials ==\n'
if [[ -n "$DRVFS" ]]; then
    RT_DB_CONFIG="$CFG" RT_BACKUP_TMPDIR="$DRVFS/rt_backup_tmp_probe" \
        bash "$ROOT/tools/backup.sh" --out "$WORK/tmpdir-out" >"$WORK/logs/tmpdir_drvfs.log" 2>&1
    rc=$?
    check_eq "DrvFs credential dir is rejected" "rejected" "$([[ $rc -ne 0 ]] && echo rejected || echo accepted)"
    if [[ -e "$DRVFS/rt_backup_tmp_probe" ]]; then
        fail "credential dir was created on DrvFs"
        rm -rf "$DRVFS/rt_backup_tmp_probe"
    else
        pass "no credential dir is created on DrvFs"
    fi
else
    skip "no v9fs mount for the credential-dir test"
fi
if [[ -n "$TMPFS" ]]; then
    RT_DB_CONFIG="$CFG" RT_BACKUP_TMPDIR="$TMPFS/rt_backup_tmp_probe" \
        bash "$ROOT/tools/backup.sh" --dry-run --out "$WORK/tmpdir-out" >"$WORK/logs/tmpdir_tmpfs.log" 2>&1
    check_eq "tmpfs credential dir is accepted" 0 "$?"
    if [[ -e "$TMPFS/rt_backup_tmp_probe" ]]; then
        fail "dry-run created the credential dir on tmpfs"
        rm -rf "$TMPFS/rt_backup_tmp_probe"
    else
        pass "dry-run does not create the credential dir"
    fi
else
    skip "no tmpfs mount for the credential-dir test"
fi

printf '== credential handling (PGPASSFILE) ==\n'
if ((HAVE_SCRAM)); then
    check_eq "scram password auth works over TCP" "1" \
        "$(PGPASSWORD="$TCP_PW" "$PG_BIN/psql" -h 127.0.0.1 -p "$PORT" -U rt_iso -d rt_iso_db -tAc 'select 1' 2>/dev/null)"
    mkdir -p "$WORK/fakehome" "$WORK/cred-tmp/keep"
    chmod 700 "$WORK/fakehome" "$WORK/cred-tmp" "$WORK/cred-tmp/keep"
    printf 'sentinel\n' >"$WORK/cred-tmp/keep/pgpass"
    sentinel_sum="$(sha256sum "$WORK/cred-tmp/keep/pgpass" | cut -d' ' -f1)"
    TRACE="$WORK/logs/pgdump-trace.log"
    : >"$TRACE"

    env PATH="$WORK/bin:$PATH" HOME="$WORK/fakehome" \
        RT_REAL_PG_DUMP="$REAL_PG_DUMP" RT_PGDUMP_TRACE="$TRACE" \
        RT_BACKUP_TMPDIR="$WORK/cred-tmp" RT_DB_CONFIG="$WORK/tcp.json" \
        bash "$ROOT/tools/backup.sh" --out "$WORK/tcp-backups" \
        >"$WORK/logs/tcp.log" 2>&1
    rc=$?
    check_eq "backup over TCP with a special-character password" 0 "$rc"
    check_count "TCP backup produced a dump" 1 "$WORK/tcp-backups" '*.dump'
    check_eq "pg_dump did not receive PGPASSWORD" "unset" "$(trace_value PGPASSWORD_STATE "$TRACE")"
    check_eq "passfile is 600 while pg_dump runs" 600 "$(trace_value PASSFILE_MODE "$TRACE")"
    check_eq "passfile directory is 700 while pg_dump runs" 700 "$(trace_value PASSFILE_DIR_MODE "$TRACE")"
    passfile_path="$(trace_value PGPASSFILE "$TRACE")"
    case "$passfile_path" in
        "$WORK/cred-tmp"/*/pgpass) pass "passfile lives in the private temp area" ;;
        *) fail "unexpected passfile path: $passfile_path" ;;
    esac
    if [[ -n "$passfile_path" && ! -e "$passfile_path" ]]; then
        pass "passfile is removed after the run"
    else
        fail "passfile still exists after the run"
    fi
    if awk '/^ARGV_BEGIN$/{flag=1;next}/^ARGV_END$/{flag=0}flag' "$TRACE" | grep -qF "$TCP_PW"; then
        fail "password appears in pg_dump argv"
    else
        pass "password does not appear in pg_dump argv"
    fi
    if grep -qF "$TCP_PW" "$WORK/logs/tcp.log" "$TRACE"; then
        fail "password appears in stdout/stderr or trace"
    else
        pass "password does not appear in stdout/stderr or trace"
    fi
    check_eq "no leftover passfile in the temp area" 0 \
        "$(find "$WORK/cred-tmp" -name pgpass -not -path '*/keep/pgpass' | wc -l)"
    check_eq "no leftover private passfile directory" 0 \
        "$(find "$WORK/cred-tmp" -maxdepth 2 -type d -name 'run.*' | wc -l)"
    check_eq "pre-existing passfile-like sentinel is untouched" "$sentinel_sum" \
        "$(sha256sum "$WORK/cred-tmp/keep/pgpass" | cut -d' ' -f1)"
    if [[ -e "$WORK/fakehome/.pgpass" ]]; then
        fail "tool wrote a persistent ~/.pgpass"
    else
        pass "tool did not create or touch ~/.pgpass"
    fi

    printf '== wrong password must fail cleanly ==\n'
    RT_TEST_PW="wrong-$TCP_PW" RT_TEST_PORT="$PORT" php -r '
echo json_encode([
    "PGHOST" => "127.0.0.1",
    "PGPORT" => getenv("RT_TEST_PORT"),
    "PGDATABASE" => "rt_iso_db",
    "PGUSER" => "rt_iso",
    "PGPASSWORD" => getenv("RT_TEST_PW"),
], JSON_UNESCAPED_SLASHES), "\n";
' >"$WORK/tcp-bad.json"
    chmod 600 "$WORK/tcp-bad.json"
    : >"$TRACE"
    env PATH="$WORK/bin:$PATH" HOME="$WORK/fakehome" \
        RT_REAL_PG_DUMP="$REAL_PG_DUMP" RT_PGDUMP_TRACE="$TRACE" \
        RT_BACKUP_TMPDIR="$WORK/cred-tmp" RT_DB_CONFIG="$WORK/tcp-bad.json" \
        bash "$ROOT/tools/backup.sh" --out "$WORK/tcp-bad" \
        >"$WORK/logs/tcp_bad.log" 2>&1
    rc=$?
    check_eq "wrong password is rejected" "failed" "$([[ $rc -ne 0 ]] && echo failed || echo ignored)"
    check_count "no dump after a wrong password" 0 "$WORK/tcp-bad" '*.dump'
    passfile_path="$(trace_value PGPASSFILE "$TRACE")"
    if [[ -n "$passfile_path" && ! -e "$passfile_path" ]]; then
        pass "passfile is removed after a failed pg_dump"
    else
        fail "passfile survives a failed pg_dump"
    fi
    check_eq "no leftover passfile after a failed pg_dump" 0 \
        "$(find "$WORK/cred-tmp" -name pgpass -not -path '*/keep/pgpass' | wc -l)"
    if grep -qF "$TCP_PW" "$WORK/logs/tcp_bad.log"; then
        fail "password appears in the failure log"
    else
        pass "password is absent from the failure log"
    fi

    printf '== newline password must be refused ==\n'
    RT_TEST_PW=$'line1\nline2' RT_TEST_PORT="$PORT" php -r '
echo json_encode([
    "PGHOST" => "127.0.0.1",
    "PGPORT" => getenv("RT_TEST_PORT"),
    "PGDATABASE" => "rt_iso_db",
    "PGUSER" => "rt_iso",
    "PGPASSWORD" => getenv("RT_TEST_PW"),
], JSON_UNESCAPED_SLASHES), "\n";
' >"$WORK/tcp-newline.json"
    chmod 600 "$WORK/tcp-newline.json"
    env HOME="$WORK/fakehome" RT_BACKUP_TMPDIR="$WORK/cred-tmp" \
        RT_DB_CONFIG="$WORK/tcp-newline.json" \
        bash "$ROOT/tools/backup.sh" --out "$WORK/tcp-newline" \
        >"$WORK/logs/tcp_newline.log" 2>&1
    rc=$?
    check_eq "newline password is refused" "refused" "$([[ $rc -ne 0 ]] && echo refused || echo accepted)"
    check_count "no dump for a newline password" 0 "$WORK/tcp-newline" '*.dump'
    if grep -q 'xuống dòng' "$WORK/logs/tcp_newline.log"; then
        pass "newline password refusal is explained"
    else
        fail "newline password refusal message missing"
    fi
    check_eq "no leftover passfile after a newline password" 0 \
        "$(find "$WORK/cred-tmp" -name pgpass -not -path '*/keep/pgpass' | wc -l)"
else
    skip "credential handling tests (scram/TCP unavailable)"
fi

printf '== encryption ==\n'
AGE_BIN="${RT_TEST_AGE_BIN:-$(command -v age 2>/dev/null || true)}"
AGE_KEYGEN="${RT_TEST_AGE_KEYGEN:-}"
if [[ -n "$AGE_BIN" && -z "$AGE_KEYGEN" ]]; then
    if [[ -x "$(dirname -- "$AGE_BIN")/age-keygen" ]]; then
        AGE_KEYGEN="$(dirname -- "$AGE_BIN")/age-keygen"
    fi
fi

printf '== encryption preflight ==\n'
backup --encrypt --out "$WORK/preflight" >"$WORK/logs/no_recipient.log" 2>&1
rc=$?
check_eq "encryption without recipient is refused" "refused" "$([[ $rc -ne 0 ]] && echo refused || echo accepted)"
check_count "no dump is written when the preflight fails" 0 "$WORK/preflight" '*.dump'
backup --delete-plaintext --out "$WORK/delete_no_crypt" >"$WORK/logs/delete_no_crypt.log" 2>&1
rc=$?
check_eq "deletion without encryption is refused" "refused" "$([[ $rc -ne 0 ]] && echo refused || echo accepted)"
check_count "deletion without encryption writes nothing" 0 "$WORK/delete_no_crypt" '*'

if [[ -z "$AGE_BIN" || -z "$AGE_KEYGEN" || ! -x "$AGE_KEYGEN" ]]; then
    skip "age/age-keygen not available: encryption, deletion-confirmation and pty tests"
else
    "$AGE_KEYGEN" -o "$WORK/identity.txt" 2>"$WORK/logs/keygen.log"
    RECIPIENT="$(grep -o 'age1[0-9a-z]*' "$WORK/logs/keygen.log" | head -1)"
    "$AGE_KEYGEN" -o "$WORK/wrong-identity.txt" 2>/dev/null
    check_eq "age identity mode is 600" 600 "$(stat -c '%a' "$WORK/identity.txt")"
    if [[ "$RECIPIENT" == age1* ]]; then pass "age recipient generated"; else fail "could not generate an age recipient"; fi

    printf '== recipient without identity must be refused ==\n'
    RT_DB_CONFIG="$CFG" RT_BACKUP_AGE_RECIPIENT="$RECIPIENT" \
        bash "$ROOT/tools/backup.sh" --encrypt --out "$WORK/no_identity" \
        >"$WORK/logs/no_identity.log" 2>&1
    rc=$?
    check_eq "encryption without identity is refused" "refused" "$([[ $rc -ne 0 ]] && echo refused || echo accepted)"
    check_count "no dump when identity is missing" 0 "$WORK/no_identity" '*.dump'

    printf '== encryption keeps plaintext by default ==\n'
    RT_DB_CONFIG="$CFG" \
        RT_BACKUP_AGE_IDENTITY="$WORK/identity.txt" \
        RT_BACKUP_AGE_RECIPIENT="$RECIPIENT" \
        bash "$ROOT/tools/backup.sh" --encrypt --out "$WORK/enc_keep" \
        >"$WORK/logs/enc_keep.log" 2>&1
    check_eq "encrypted backup without deletion exits 0" 0 "$?"
    check_count "encrypted run keeps the plaintext dump" 1 "$WORK/enc_keep" '*.dump'
    check_count "encrypted run writes the .age file" 1 "$WORK/enc_keep" '*.age'
    check_count "encrypted run writes the .age checksum" 1 "$WORK/enc_keep" '*.age.sha256'
    check_mode "plaintext stays 600 after encryption" "$(find "$WORK/enc_keep" -name '*.dump' | head -1)" 600

    printf '== wrong identity must not delete plaintext ==\n'
    RT_DB_CONFIG="$CFG" \
        RT_BACKUP_AGE_IDENTITY="$WORK/wrong-identity.txt" \
        RT_BACKUP_AGE_RECIPIENT="$RECIPIENT" \
        bash "$ROOT/tools/backup.sh" --encrypt --out "$WORK/wrong_identity" \
        >"$WORK/logs/wrong_identity.log" 2>&1
    rc=$?
    check_eq "wrong identity fails verification" "failed" "$([[ $rc -ne 0 ]] && echo failed || echo ignored)"
    check_count "plaintext is kept after a wrong identity" 1 "$WORK/wrong_identity" '*.dump'
    check_count "unverified .age file is removed" 0 "$WORK/wrong_identity" '*.age'
    check_count "unverified .age checksum is removed" 0 "$WORK/wrong_identity" '*.age.sha256'

    printf '== age failure must not delete plaintext ==\n'
    RT_DB_CONFIG="$CFG" \
        RT_BACKUP_AGE_IDENTITY="$WORK/identity.txt" \
        RT_BACKUP_AGE_RECIPIENT="not-a-recipient" \
        bash "$ROOT/tools/backup.sh" --encrypt --out "$WORK/age_fail" \
        >"$WORK/logs/age_fail.log" 2>&1
    rc=$?
    check_eq "invalid recipient fails encryption" "failed" "$([[ $rc -ne 0 ]] && echo failed || echo ignored)"
    check_count "plaintext is kept after an age failure" 1 "$WORK/age_fail" '*.dump'
    check_count "no .age file after an age failure" 0 "$WORK/age_fail" '*.age'

    printf '== piped confirmation must be refused ==\n'
    if command -v setsid >/dev/null 2>&1; then
        printf 'DELETE\n' | setsid -w env \
            RT_DB_CONFIG="$CFG" \
            RT_BACKUP_AGE_IDENTITY="$WORK/identity.txt" \
            RT_BACKUP_AGE_RECIPIENT="$RECIPIENT" \
            bash "$ROOT/tools/backup.sh" --encrypt --delete-plaintext --out "$WORK/piped" \
            >"$WORK/logs/piped.log" 2>&1
        rc=$?
        check_eq "piped DELETE is refused (exit 3)" 3 "$rc"
        check_count "plaintext survives a piped DELETE" 1 "$WORK/piped" '*.dump'
        check_count "encrypted file survives a piped DELETE" 1 "$WORK/piped" '*.age'
        if grep -q 'BACKUP_DELETE_REFUSED' "$WORK/logs/piped.log"; then
            pass "refusal is explicit in the log"
        else
            fail "refusal message missing"
        fi
        check_count "identity is not stored next to the backup" 0 "$WORK/piped" 'identity*'
    else
        skip "setsid not available: piped-confirmation test"
    fi

    printf '== credential cleanup before the deletion prompt ==\n'
    if ((HAVE_SCRAM)) && command -v setsid >/dev/null 2>&1; then
        : >"$WORK/logs/trace2.log"
        printf 'DELETE\n' | setsid -w env PATH="$WORK/bin:$PATH" HOME="$WORK/fakehome" \
            RT_REAL_PG_DUMP="$REAL_PG_DUMP" RT_PGDUMP_TRACE="$WORK/logs/trace2.log" \
            RT_BACKUP_TMPDIR="$WORK/cred-tmp2" RT_DB_CONFIG="$WORK/tcp.json" \
            RT_BACKUP_AGE_IDENTITY="$WORK/identity.txt" \
            RT_BACKUP_AGE_RECIPIENT="$RECIPIENT" \
            bash "$ROOT/tools/backup.sh" --encrypt --delete-plaintext --out "$WORK/tcp_piped" \
            >"$WORK/logs/tcp_piped.log" 2>&1
        rc=$?
        check_eq "piped DELETE with credentials exits 3" 3 "$rc"
        check_count "plaintext survives a piped DELETE with credentials" 1 "$WORK/tcp_piped" '*.dump'
        check_count "encrypted file survives a piped DELETE with credentials" 1 "$WORK/tcp_piped" '*.age'
        tcp_passfile="$(trace_value PGPASSFILE "$WORK/logs/trace2.log")"
        if [[ -n "$tcp_passfile" && ! -e "$tcp_passfile" ]]; then
            pass "passfile is removed before the deletion prompt"
        else
            fail "passfile survives until the deletion prompt"
        fi
        check_eq "no leftover passfile after a refused deletion" 0 \
            "$(find "$WORK/cred-tmp2" -name pgpass 2>/dev/null | wc -l)"
    else
        skip "credential cleanup at deletion prompt (scram or setsid unavailable)"
    fi

    printf '== interactive terminal confirmation ==\n'
    if ! command -v python3 >/dev/null 2>&1; then
        skip "python3 not available: pty confirmation tests"
    else
        run_pty() {
            local answer=$1
            shift
            RT_PTY_ANSWER="$answer" python3 - "$@" <<'PY'
import os, pty, select, sys, time

answer = os.environ["RT_PTY_ANSWER"].encode()
pid, fd = pty.fork()
if pid == 0:
    os.execvp(sys.argv[1], sys.argv[1:])

out = b""
sent = False
start = time.time()
while True:
    if time.time() - start > 90:
        os.kill(pid, 9)
        break
    try:
        ready, _, _ = select.select([fd], [], [], 0.5)
    except OSError:
        break
    if fd in ready:
        try:
            chunk = os.read(fd, 4096)
        except OSError:
            break
        if not chunk:
            break
        out += chunk
        if not sent and b"Nh\xe1\xba\xadp DELETE" in out:
            os.write(fd, answer + b"\n")
            sent = True
    elif not sent and time.time() - start > 20:
        os.write(fd, answer + b"\n")
        sent = True

try:
    _, status = os.waitpid(pid, 0)
except OSError:
    status = 0
sys.stdout.write(out.decode("utf-8", "replace"))
sys.exit(os.waitstatus_to_exitcode(status))
PY
        }

        run_pty DELETE env \
            RT_DB_CONFIG="$CFG" \
            RT_BACKUP_AGE_IDENTITY="$WORK/identity.txt" \
            RT_BACKUP_AGE_RECIPIENT="$RECIPIENT" \
            bash "$ROOT/tools/backup.sh" --encrypt --delete-plaintext --out "$WORK/tty_delete" \
            >"$WORK/logs/tty_delete.log" 2>&1
        rc=$?
        check_eq "typed DELETE succeeds" 0 "$rc"
        check_count "plaintext removed after typed confirmation" 0 "$WORK/tty_delete" '*.dump'
        check_count "plaintext checksum removed after typed confirmation" 0 "$WORK/tty_delete" '*.dump.sha256'
        check_count "encrypted file kept after typed confirmation" 1 "$WORK/tty_delete" '*.age'
        check_count "encrypted checksum kept after typed confirmation" 1 "$WORK/tty_delete" '*.age.sha256'
        check_mode "encrypted file is 600" "$(find "$WORK/tty_delete" -name '*.age' | head -1)" 600
        encfile="$(find "$WORK/tty_delete" -name '*.age' | head -1)"
        if [[ -n "$encfile" ]] && sha256sum -c "${encfile}.sha256" >/dev/null 2>&1; then
            pass "encrypted checksum verifies against the .age file"
        else
            fail "encrypted checksum does not verify"
        fi

        run_pty DELETE env \
            RT_DB_CONFIG="$CFG" \
            RT_BACKUP_AGE_IDENTITY="$WORK/wrong-identity.txt" \
            RT_BACKUP_AGE_RECIPIENT="$RECIPIENT" \
            bash "$ROOT/tools/backup.sh" --encrypt --delete-plaintext --out "$WORK/tty_wrong_id" \
            >"$WORK/logs/tty_wrong_id.log" 2>&1
        rc=$?
        check_eq "wrong identity never reaches the deletion prompt" "failed" "$([[ $rc -ne 0 ]] && echo failed || echo ignored)"
        check_count "wrong identity keeps plaintext even with a terminal" 1 "$WORK/tty_wrong_id" '*.dump'
        check_count "wrong identity removes the unverified .age file" 0 "$WORK/tty_wrong_id" '*.age'

        run_pty NO env \
            RT_DB_CONFIG="$CFG" \
            RT_BACKUP_AGE_IDENTITY="$WORK/identity.txt" \
            RT_BACKUP_AGE_RECIPIENT="$RECIPIENT" \
            bash "$ROOT/tools/backup.sh" --encrypt --delete-plaintext --out "$WORK/tty_decline" \
            >"$WORK/logs/tty_decline.log" 2>&1
        rc=$?
        check_eq "declined confirmation exits 0" 0 "$rc"
        check_count "declined confirmation keeps plaintext" 1 "$WORK/tty_decline" '*.dump'
        check_count "declined confirmation keeps encrypted file" 1 "$WORK/tty_decline" '*.age'
    fi
fi

printf '\nRESULT PASS=%d FAIL=%d SKIP=%d\n' "$PASS" "$FAIL" "$SKIP"
[[ $FAIL -eq 0 ]]
