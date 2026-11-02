#!/usr/bin/env bash
# Chỉ dùng cho học tập cục bộ. Script không cài gói, không xóa dữ liệu.
set -u
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)" || exit 2
cd "$root" || exit 2
lesson="5"
port="8105"
usage() {
  printf 'Cách dùng: bash lab.sh check | migrate | run | test | seed | db-config | sql | legacy-report\n'
  printf 'check: kiểm tra môi trường; migrate: áp dụng migration; run: chạy ứng dụng.\n'
  printf 'test: chạy kiểm thử; seed: áp dụng schema và nạp dữ liệu mẫu.\n'
  printf 'db-config và sql dùng để cấu hình/kiểm tra PostgreSQL. Dừng máy chủ bằng Ctrl+C.\n'
  printf 'legacy-report: báo cáo chỉ đọc các test result legacy issue_version=0.\n'
}
need_php() {
  command -v php >/dev/null 2>&1 || { echo 'THIEU_PHP: xem mục Công cụ cần có trong Huong_dan.docx của bài lab'; return 2; }
  php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || { echo 'CAN_PHP_8_1_TRO_LEN'; return 2; }
}
check_env() {
  need_php || return 2
  if ! command -v git >/dev/null 2>&1; then
    echo 'CANH_BAO_THIEU_GIT: Git không bắt buộc để chạy ứng dụng/test; chỉ cần cho quy trình version control hoặc nộp bài.' >&2
  fi
  if [[ "$lesson" == 5 ]]; then
    command -v psql >/dev/null 2>&1 || { echo 'THIEU_PSQL: xem tài liệu chuẩn bị Lab 05'; return 2; }
    php -r 'exit(extension_loaded("pgsql") ? 0 : 1);' || { echo 'THIEU_PGSQL: cài phần mở rộng đúng phiên bản PHP'; return 2; }
    php tools/db-check.php || return 2
  fi
  printf 'MOI_TRUONG=SAN_SANG | Lab %s\n' "$lesson"
  echo 'Đây không phải kết luận bài tập đã hoàn thành.'
}
case "${1:-help}" in
  check) check_env ;;
  run)
    check_env || exit 2
    if [[ "$lesson" == 2 ]]; then
      php demo.php
    else
      echo "THU_MUC=$root"
      echo "DIA_CHI=http://127.0.0.1:$port"
      echo 'Giữ terminal này mở. Nhấn Ctrl+C để dừng; không có tiến trình chạy nền.'
      session_dir="${RT_SESSION_DIR:-$root/.local/sessions}"
      mkdir -p "$session_dir" || exit 2
      chmod 700 "$session_dir" 2>/dev/null || true
      php -d session.name="OSSLAB$lesson" -d session.save_path="$session_dir" \
        -S "127.0.0.1:$port" -t public
    fi ;;
  test)
    check_env || exit 2
    if [[ "$lesson" == 1 ]]; then
      bash scripts/check-env.sh
    elif [[ "$lesson" == 5 ]]; then
      php tests/run.php && php tests/integration.php
    else
      php tests/run.php
    fi ;;
  migrate)
    check_env || exit 2
    php tools/migrate.php ;;
  seed)
    check_env || exit 2
    php tools/migrate.php || exit 2
    php tools/seed.php ;;
  db-config)
    [[ "$lesson" == 5 ]] || { echo 'Lệnh này chỉ dùng ở Lab 05.'; exit 2; }
    need_php || exit 2
    php tools/db-config.php ;;
  extend) php tests/integration.php ;;
  sql)
    [[ "$lesson" == 5 ]] || { echo 'Lệnh này chỉ dùng ở Lab 05.'; exit 2; }
    need_php || exit 2
    php tools/sql.php ;;
  legacy-report)
    need_php || exit 2
    php tools/legacy-report.php "${@:2}" ;;
  help|-h|--help) usage ;;
  *) usage; exit 2 ;;
esac
