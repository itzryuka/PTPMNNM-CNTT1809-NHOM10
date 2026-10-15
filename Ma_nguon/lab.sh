#!/usr/bin/env bash
# Chỉ dùng cho học tập cục bộ. Script không cài gói, không xóa dữ liệu.
set -u
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)" || exit 2
cd "$root" || exit 2
lesson="1"
port="8101"
usage() {
  printf 'Cách dùng: bash lab.sh check | run | test | extend | db-config | sql\n'
  printf 'check: kiểm tra môi trường; run: chạy bài; test: kiểm tra mẫu; extend: kiểm tra mở rộng.\n'
  printf 'db-config và sql chỉ dùng cho Lab 05. Dừng máy chủ bằng Ctrl+C.\n'
}
need_php() {
  command -v php >/dev/null 2>&1 || { echo 'THIEU_PHP: xem mục Công cụ cần có trong Huong_dan.docx của bài lab'; return 2; }
  php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || { echo 'CAN_PHP_8_1_TRO_LEN'; return 2; }
}
check_env() {
  need_php || return 2
  command -v git >/dev/null 2>&1 || { echo 'THIEU_GIT'; return 2; }
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
      mkdir -p .local/sessions || exit 2
      php -d session.name="OSSLAB$lesson" -d session.save_path="$root/.local/sessions" \
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
  extend)
    check_env || exit 2
    echo 'BÀI TỰ MỞ RỘNG: có ca chưa đạt trước khi bạn hoàn thiện là bình thường.'
    if [[ "$lesson" == 1 ]]; then bash tests/extension.sh; else php tests/extension.php; fi ;;
  db-config)
    [[ "$lesson" == 5 ]] || { echo 'Lệnh này chỉ dùng ở Lab 05.'; exit 2; }
    need_php || exit 2
    php tools/db-config.php ;;
  sql)
    [[ "$lesson" == 5 ]] || { echo 'Lệnh này chỉ dùng ở Lab 05.'; exit 2; }
    need_php || exit 2
    php tools/sql.php ;;
  help|-h|--help) usage ;;
  *) usage; exit 2 ;;
esac
