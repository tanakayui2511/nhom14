#!/usr/bin/env bash
# Safe PostgreSQL backup for ReleaseTracker.
#
# Design rules:
# - only writes to a Unix-permission filesystem (WSL ext2/ext3/ext4, xfs, btrfs),
#   never DrvFs or tmpfs;
# - never prints the database password or a connection string;
# - never overwrites an existing backup (mktemp + umask 077);
# - keeps the plaintext dump by default, even after successful encryption;
# - deletes plaintext only with --delete-plaintext plus an explicit confirmation;
# - verifies the dump with pg_restore --list and, when encrypted, decrypts it
#   through age back into pg_restore before reporting success.
set -Eeuo pipefail
umask 077

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="${RT_DB_CONFIG:-$ROOT/.local/db.json}"
BACKUP_DIR="${RT_BACKUP_DIR:-$HOME/.local/share/releasetracker/backups}"
LABEL="releasetracker"
RECIPIENT="${RT_BACKUP_AGE_RECIPIENT:-}"
IDENTITY="${RT_BACKUP_AGE_IDENTITY:-}"
ENCRYPT=0
DELETE_PLAINTEXT=0
DRY_RUN=0

usage() {
    cat <<'TXT'
Cách dùng: bash tools/backup.sh [tùy chọn]

  --dry-run             Kiểm tra cấu hình, thư mục, công cụ; không ghi file.
  --encrypt             Mã hóa bằng age (cần recipient và identity để kiểm tra).
  --delete-plaintext    Xóa dump plaintext sau khi mã hóa/kiểm tra thành công;
                        phải nhập đúng DELETE tại terminal tương tác (/dev/tty).
  --out DIR             Thư mục backup (mặc định ~/.local/share/releasetracker/backups).
  --label NAME          Tiền tố tên file (mặc định releasetracker).
  --recipient KEY       age recipient; hoặc đặt RT_BACKUP_AGE_RECIPIENT.
  --identity FILE       age identity để kiểm tra giải mã; hoặc RT_BACKUP_AGE_IDENTITY.
  -h, --help            Hiển thị trợ giúp.

Không dùng cho database chính nếu chưa được phê duyệt. Không in bí mật.
TXT
}

log() { printf '%s\n' "$*"; }
die() { printf 'BACKUP_FAILED: %s\n' "$*" >&2; exit 2; }

sha256_of() {
    sha256sum -- "$1" | cut -d' ' -f1
}

# Confirm the checksum file still matches the file it was written for.
verify_checksum() {
    local file=$1 sum_file=$2 expected actual
    [[ -s "$sum_file" ]] || return 1
    expected="$(cut -d' ' -f1 -- "$sum_file")"
    actual="$(sha256_of "$file")" || return 1
    [[ -n "$expected" && "$expected" == "$actual" ]]
}

# A destructive confirmation must come from a real controlling terminal.
# Pipes, redirects and environment variables are refused on purpose.
confirm_on_tty() {
    local prompt=$1 answer=""
    exec 3<>/dev/tty 2>/dev/null || return 1
    if [[ ! -t 3 ]]; then
        exec 3>&-
        return 1
    fi
    printf '%s' "$prompt" >&3 || { exec 3>&-; return 1; }
    if ! IFS= read -r answer <&3; then
        exec 3>&-
        return 1
    fi
    exec 3>&-
    printf '%s' "$answer"
}

while (($# > 0)); do
    case "$1" in
        --dry-run) DRY_RUN=1 ;;
        --encrypt) ENCRYPT=1 ;;
        --delete-plaintext) DELETE_PLAINTEXT=1 ;;
        --out)
            (($# >= 2)) || die "--out cần giá trị"
            BACKUP_DIR="$2"
            shift
            ;;
        --label)
            (($# >= 2)) || die "--label cần giá trị"
            LABEL="$2"
            shift
            ;;
        --recipient)
            (($# >= 2)) || die "--recipient cần giá trị"
            RECIPIENT="$2"
            shift
            ;;
        --identity)
            (($# >= 2)) || die "--identity cần giá trị"
            IDENTITY="$2"
            shift
            ;;
        -h|--help) usage; exit 0 ;;
        *) die "tham số không hợp lệ: $1" ;;
    esac
    shift
done

[[ -n "$LABEL" ]] || die "label rỗng"
LABEL="${LABEL//[^A-Za-z0-9._-]/_}"

[[ -f "$CONFIG" ]] || die "không tìm thấy file cấu hình"
command -v php >/dev/null 2>&1 || die "thiếu php"
command -v pg_dump >/dev/null 2>&1 || die "thiếu pg_dump"
command -v pg_restore >/dev/null 2>&1 || die "thiếu pg_restore"
command -v sha256sum >/dev/null 2>&1 || die "thiếu sha256sum"
command -v stat >/dev/null 2>&1 || die "thiếu stat"

config_mode="$(stat -c '%a' "$CONFIG" 2>/dev/null || printf 'unknown')"
case "$config_mode" in
    600|400) : ;;
    *) log "WARN: quyền file cấu hình là $config_mode (khuyến nghị 600 hoặc 400)" ;;
esac

# Credentials stay out of the shell. The PHP child writes a one-line PostgreSQL
# passfile (0600) into a private 0700 directory; pg_dump receives only the path
# through PGPASSFILE. The file is removed right after pg_dump and, as a safety
# net, by the EXIT trap on every other path.
pass_dir=""
passfile=""
dump=""
enc=""
dump_complete=0
dump_verified=0
enc_verified=0

remove_passfile() {
    if [[ -n "$pass_dir" && -d "$pass_dir" ]]; then
        rm -rf -- "$pass_dir" || true
    fi
    pass_dir=""
    unset PGPASSFILE 2>/dev/null || true
}

cleanup() {
    local rc=$?
    remove_passfile
    if ((rc != 0)); then
        if [[ -n "$enc" && -e "$enc" && $enc_verified -eq 0 ]]; then
            rm -f -- "$enc" "${enc}.sha256"
        fi
        if [[ -n "$dump" && -e "$dump" && $dump_verified -eq 0 ]]; then
            # pg_dump may have died mid-way (partial, unusable) or produced a
            # complete file that failed pg_restore --list / the checksum check.
            # A partial dump is deleted; a complete but unverified dump is kept
            # so a verification bug can never destroy the only copy. The
            # checksum file is dropped so it is not mistaken for verified.
            if ((dump_complete)); then
                rm -f -- "${dump}.sha256"
                log "PLAINTEXT_UNVERIFIED=$dump"
                log "PLAINTEXT_KEPT=yes (chưa xác minh; giữ nguyên để tránh mất dữ liệu)"
            else
                rm -f -- "$dump" "${dump}.sha256"
            fi
        elif [[ -n "$dump" && -e "$dump" ]]; then
            log "PLAINTEXT_KEPT=$dump"
        fi
    fi
    return "$rc"
}
trap cleanup EXIT

if ((!DRY_RUN)); then
    tmp_base="${RT_BACKUP_TMPDIR:-${XDG_RUNTIME_DIR:-${HOME:-}/.cache}}"
    if [[ -z "$tmp_base" || "$tmp_base" == "/.cache" ]]; then
        die "không xác định được thư mục tạm; đặt RT_BACKUP_TMPDIR"
    fi
    probe_tmp="$tmp_base"
    while [[ ! -e "$probe_tmp" ]]; do
        parent_tmp="$(dirname -- "$probe_tmp")"
        [[ "$parent_tmp" != "$probe_tmp" ]] || die "không xác định được thư mục tạm"
        probe_tmp="$parent_tmp"
    done
    [[ -d "$probe_tmp" ]] || die "thư mục tạm không hợp lệ"
    tmp_fs="$(stat -f -c '%T' "$probe_tmp" 2>/dev/null || printf 'unknown')"
    case "$tmp_fs" in
        ext2/ext3|ext4|xfs|btrfs|tmpfs) : ;;
        *)
            die "thư mục tạm '$tmp_base' nằm trên '$tmp_fs'; đặt RT_BACKUP_TMPDIR trên ext2/ext3/ext4, xfs, btrfs hoặc tmpfs"
            ;;
    esac
    pass_root="$tmp_base/releasetracker-pass"
    [[ ! -L "$pass_root" ]] || die "thư mục passfile không được là symlink"
    mkdir -p -- "$pass_root" || die "không tạo được thư mục passfile"
    chmod 700 -- "$pass_root" 2>/dev/null || die "không đặt được quyền 700 cho thư mục passfile"
    pass_dir="$(mktemp -d --tmpdir="$pass_root" 'run.XXXXXX')" \
        || die "không tạo được thư mục passfile tạm"
    chmod 700 -- "$pass_dir" || die "không đặt được quyền 700 cho passfile tạm"
    passfile="$pass_dir/pgpass"
fi

# Read the JSON config in a child process and emit shell-escaped exports.
# The password is never echoed, never put on a command line and never exported.
if config_env="$(
    RT_CFG="$CONFIG" RT_PASSFILE="$passfile" php -r '
        $path = getenv("RT_CFG");
        $raw = @file_get_contents($path);
        $config = json_decode((string)$raw, true);
        if (!is_array($config)) { exit(2); }
        foreach (["PGHOST", "PGPORT", "PGDATABASE", "PGUSER", "PGPASSWORD"] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key])) { exit(2); }
        }
        $modes = ["disable", "allow", "prefer", "require", "verify-ca", "verify-full"];
        if (isset($config["PGSSLMODE"]) && $config["PGSSLMODE"] !== "") {
            if (!is_string($config["PGSSLMODE"]) || !in_array($config["PGSSLMODE"], $modes, true)) {
                exit(2);
            }
        }
        if (isset($config["PGSSLROOTCERT"])
            && $config["PGSSLROOTCERT"] !== ""
            && !is_string($config["PGSSLROOTCERT"])) {
            exit(2);
        }
        $passfile = getenv("RT_PASSFILE");
        if (is_string($passfile) && $passfile !== "") {
            $password = $config["PGPASSWORD"];
            if (str_contains($password, "\n") || str_contains($password, "\r")) {
                exit(4);
            }
            $escape = static function (string $value): string {
                return str_replace(["\\", ":"], ["\\\\", "\\:"], $value);
            };
            $line = $escape($config["PGHOST"]) . ":"
                . $escape($config["PGPORT"]) . ":"
                . $escape($config["PGDATABASE"]) . ":"
                . $escape($config["PGUSER"]) . ":"
                . $escape($password) . "\n";
            $handle = @fopen($passfile, "x");
            if ($handle === false) { exit(5); }
            $written = fwrite($handle, $line);
            fclose($handle);
            if ($written !== strlen($line)) { exit(5); }
            @chmod($passfile, 0600);
            $perms = @fileperms($passfile);
            if ($perms === false || ($perms & 0777) !== 0600) { exit(5); }
            if (@filesize($passfile) !== strlen($line)) { exit(5); }
        }
        foreach (["PGHOST", "PGPORT", "PGDATABASE", "PGUSER"] as $key) {
            echo "export " . $key . "=" . escapeshellarg($config[$key]) . "\n";
        }
        foreach (["PGSSLMODE", "PGSSLROOTCERT"] as $key) {
            if (isset($config[$key]) && is_string($config[$key]) && $config[$key] !== "") {
                echo "export " . $key . "=" . escapeshellarg($config[$key]) . "\n";
            }
        }
    '
)"; then
    :
else
    config_rc=$?
    case "$config_rc" in
        4) die "mật khẩu chứa ký tự xuống dòng; passfile không biểu diễn được" ;;
        5) die "không tạo hoặc không bảo vệ được passfile" ;;
        *) die "cấu hình không hợp lệ" ;;
    esac
fi

eval "$config_env"
unset PGPASSWORD
[[ -n "${PGHOST:-}" && -n "${PGDATABASE:-}" && -n "${PGUSER:-}" ]] \
    || die "cấu hình thiếu thông tin kết nối"
if ((!DRY_RUN)); then
    [[ -s "$passfile" ]] || die "passfile rỗng"
    export PGPASSFILE="$passfile"
fi

if [[ -n "$RECIPIENT" ]]; then
    ENCRYPT=1
fi
if ((ENCRYPT)); then
    command -v age >/dev/null 2>&1 || die "thiếu age; không thể mã hóa"
    [[ -n "$RECIPIENT" ]] || die "thiếu age recipient"
    [[ -n "$IDENTITY" && -f "$IDENTITY" && -r "$IDENTITY" ]] \
        || die "cần age identity đọc được để kiểm tra giải mã"
    identity_mode="$(stat -c '%a' "$IDENTITY" 2>/dev/null || printf 'unknown')"
    case "$identity_mode" in
        600|400) : ;;
        *) log "WARN: quyền age identity là $identity_mode (khuyến nghị 600)" ;;
    esac
fi
if ((DELETE_PLAINTEXT)) && ((!ENCRYPT)); then
    die "--delete-plaintext chỉ dùng khi đã bật mã hóa"
fi

# Resolve the directory that actually exists, so --dry-run never creates it.
probe="$BACKUP_DIR"
while [[ ! -e "$probe" ]]; do
    parent="$(dirname -- "$probe")"
    [[ "$parent" != "$probe" ]] || die "không xác định được thư mục backup"
    probe="$parent"
done
[[ -d "$probe" ]] || die "đường dẫn backup không phải thư mục"
[[ ! -L "$BACKUP_DIR" ]] || die "thư mục backup không được là symlink"
filesystem="$(stat -f -c '%T' "$probe" 2>/dev/null || printf 'unknown')"
case "$filesystem" in
    ext2/ext3|ext4|xfs|btrfs) : ;;
    *)
        die "hệ thống file '$filesystem' không được hỗ trợ; dùng ext2/ext3/ext4, xfs hoặc btrfs trong WSL (không dùng /mnt/* hay tmpfs)"
        ;;
esac

if ((DRY_RUN)); then
    log "DRY_RUN=OK"
    log "CONFIG=valid"
    log "FILESYSTEM=$filesystem"
    log "BACKUP_DIR=$BACKUP_DIR"
    log "ENCRYPT=$ENCRYPT"
    log "DELETE_PLAINTEXT=$DELETE_PLAINTEXT"
    exit 0
fi

if [[ ! -d "$BACKUP_DIR" ]]; then
    mkdir -p -- "$BACKUP_DIR" || die "không tạo được thư mục backup"
fi
chmod 700 -- "$BACKUP_DIR" 2>/dev/null || die "không đặt được quyền 700 cho thư mục backup"
[[ -w "$BACKUP_DIR" ]] || die "thư mục backup không ghi được"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"

dump="$(mktemp --tmpdir="$BACKUP_DIR" "${LABEL}_${stamp}_XXXXXX.dump")" \
    || die "không tạo được file dump"
chmod 600 -- "$dump" || die "không đặt được quyền 600 cho dump"

if ! pg_dump --format=custom --file="$dump"; then
    remove_passfile
    die "pg_dump thất bại; xem thông báo của PostgreSQL ở trên"
fi
dump_complete=1
remove_passfile
[[ -s "$dump" ]] || die "dump rỗng"
pg_restore --list "$dump" >/dev/null \
    || die "pg_restore không đọc được dump"
sha256sum "$dump" >"${dump}.sha256" || die "không ghi được SHA-256"
chmod 600 -- "$dump" "${dump}.sha256"
verify_checksum "$dump" "${dump}.sha256" || die "checksum dump không khớp"
dump_verified=1
dump_sum="$(cut -d' ' -f1 "${dump}.sha256")"

if ((ENCRYPT)); then
    enc="${dump}.age"
    [[ ! -e "$enc" ]] || die "file mã hóa đã tồn tại; không ghi đè"
    age --recipient "$RECIPIENT" --output "$enc" "$dump" \
        || die "age mã hóa thất bại"
    chmod 600 -- "$enc"
    [[ -s "$enc" ]] || die "file mã hóa rỗng"
    age --decrypt --identity "$IDENTITY" "$enc" | pg_restore --list >/dev/null \
        || die "không giải mã/kiểm tra được file .age"
    sha256sum "$enc" >"${enc}.sha256" || die "không ghi được SHA-256 cho file mã hóa"
    chmod 600 -- "${enc}.sha256"
    verify_checksum "$enc" "${enc}.sha256" || die "checksum file mã hóa không khớp"
    enc_verified=1
    enc_sum="$(cut -d' ' -f1 "${enc}.sha256")"
fi

log "BACKUP_OK"
log "DUMP=$dump"
log "DUMP_SHA256=$dump_sum"
log "PLAINTEXT_KEPT=yes"
if ((ENCRYPT)); then
    log "ENCRYPTED=$enc"
    log "ENCRYPTED_SHA256=$enc_sum"
fi

if ((DELETE_PLAINTEXT)); then
    if ((!ENCRYPT)) || ((enc_verified != 1)); then
        die "từ chối xóa plaintext: chưa có file mã hóa đã xác minh"
    fi
    if ! answer="$(confirm_on_tty "Nhập DELETE để xóa plaintext $dump: ")"; then
        log "PLAINTEXT_DELETED=no"
        log "PLAINTEXT_KEPT=yes"
        printf 'BACKUP_DELETE_REFUSED: cần xác nhận tại terminal tương tác (/dev/tty); giữ nguyên plaintext\n' >&2
        exit 3
    fi
    if [[ "$answer" == "DELETE" ]]; then
        rm -f -- "$dump" "${dump}.sha256" || die "không xóa được plaintext"
        log "PLAINTEXT_DELETED=yes"
        log "PLAINTEXT_KEPT=no"
    else
        log "PLAINTEXT_DELETED=no (không xác nhận; giữ nguyên plaintext)"
        log "PLAINTEXT_KEPT=yes"
    fi
fi
