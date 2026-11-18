#!/usr/bin/env bash
# ReleaseTracker — script tiện ích cho môi trường học tập cục bộ.
# Script không cài gói và không xóa dữ liệu.
set -u
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)" || exit 2
cd "$root" || exit 2
port="8105"

usage() {
  printf 'Cách dùng: bash lab.sh check | migrate | seed | run | test | db-config | sql | legacy-report\n'
  printf '  check         kiểm tra môi trường (PHP, pgsql, psql, kết nối CSDL)\n'
  printf '  migrate       áp dụng các migration trong database/migrations\n'
  printf '  seed          migrate rồi nạp dữ liệu mẫu (chạy lại được)\n'
  printf '  run           chạy ứng dụng tại http://127.0.0.1:%s (Ctrl+C để dừng)\n' "$port"
  printf '  test          chạy toàn bộ test và in tổng PASS/FAIL\n'
  printf '  db-config     lưu cấu hình kết nối vào .local/db.json\n'
  printf '  sql           kiểm tra kết nối bằng một truy vấn đơn giản\n'
  printf '  legacy-report báo cáo chỉ đọc các test result legacy issue_version=0\n'
}

need_php() {
  command -v php >/dev/null 2>&1 || { echo 'THIEU_PHP: cần PHP 8.1+ trong PATH'; return 2; }
  php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || { echo 'CAN_PHP_8_1_TRO_LEN'; return 2; }
}

check_env() {
  need_php || return 2
  if ! command -v git >/dev/null 2>&1; then
    echo 'CANH_BAO_THIEU_GIT: Git không bắt buộc để chạy ứng dụng/test.' >&2
  fi
  command -v psql >/dev/null 2>&1 || { echo 'THIEU_PSQL: cài PostgreSQL client'; return 2; }
  php -r 'exit(extension_loaded("pgsql") ? 0 : 1);' || { echo 'THIEU_PGSQL: cài phần mở rộng pgsql đúng phiên bản PHP'; return 2; }
  php tools/db-check.php || return 2
  echo 'MOI_TRUONG=SAN_SANG'
}

# Chạy một file test, in toàn bộ output, cộng dồn số test/số lỗi từ dòng tổng kết
# dạng "<TÊN>=<số test> FAILED=<số lỗi>".
total=0
failed=0
broken=0
run_suite() {
  local file="$1" output code summary count fails
  echo "=== $file"
  output="$(php "$file" 2>&1)"
  code=$?
  printf '%s\n' "$output"
  summary="$(printf '%s\n' "$output" | grep -E '^[A-Z]+=[0-9]+ FAILED=[0-9]+$' | tail -1)"
  if [[ -z "$summary" ]]; then
    echo "LOI: $file không in dòng tổng kết (exit=$code)"
    broken=$((broken + 1))
    return
  fi
  count="$(printf '%s' "$summary" | sed -E 's/^[A-Z]+=([0-9]+) FAILED=([0-9]+)$/\1/')"
  fails="$(printf '%s' "$summary" | sed -E 's/^[A-Z]+=([0-9]+) FAILED=([0-9]+)$/\2/')"
  total=$((total + count))
  failed=$((failed + fails))
  if [[ $code -ne 0 && $fails -eq 0 ]]; then
    broken=$((broken + 1))
  fi
}

case "${1:-help}" in
  check) check_env ;;
  run)
    check_env || exit 2
    echo "THU_MUC=$root"
    echo "DIA_CHI=http://127.0.0.1:$port"
    echo 'Giữ terminal này mở. Nhấn Ctrl+C để dừng; không có tiến trình chạy nền.'
    session_dir="${RT_SESSION_DIR:-$root/.local/sessions}"
    mkdir -p "$session_dir" || exit 2
    chmod 700 "$session_dir" 2>/dev/null || true
    php -d session.name="RTSESSION" -d session.save_path="$session_dir" \
      -S "127.0.0.1:$port" -t public ;;
  test)
    check_env || exit 2
    # run.php: unit (không cần DB); integration.php: nghiệp vụ trong BEGIN…ROLLBACK;
    # regression.php: hồi quy DB/đồng thời; http.php: smoke test HTTP.
    for suite in tests/run.php tests/integration.php tests/regression.php tests/http.php; do
      run_suite "$suite"
    done
    echo '==================================================='
    echo "TONG_KET: $((total - failed)) PASS / $failed FAIL / $total test"
    if [[ $failed -ne 0 || $broken -ne 0 ]]; then
      [[ $broken -ne 0 ]] && echo "CO_BO_TEST_KHONG_CHAY_DUOC=$broken"
      exit 1
    fi
    ;;
  migrate)
    check_env || exit 2
    php tools/migrate.php ;;
  seed)
    check_env || exit 2
    php tools/migrate.php || exit 2
    php tools/seed.php ;;
  db-config)
    need_php || exit 2
    php tools/db-config.php ;;
  sql)
    need_php || exit 2
    php tools/sql.php ;;
  legacy-report)
    need_php || exit 2
    php tools/legacy-report.php "${@:2}" ;;
  help|-h|--help) usage ;;
  *) usage; exit 2 ;;
esac
