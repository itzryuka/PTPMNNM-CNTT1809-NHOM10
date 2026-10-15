#!/usr/bin/env bash
set -uo pipefail

if [[ $# -ne 1 ]]; then echo 'Usage: scan-source.sh DIRECTORY' >&2; exit 2; fi
root="$1"

# TODO 1: Từ chối thư mục thiếu / không đọc được / thiếu php -> exit 2
if [[ ! -d "$root" ]] || [[ ! -r "$root" ]] || ! command -v php >/dev/null 2>&1; then
    exit 2
fi

list="$(mktemp)" || exit 2
trap 'rm -f -- "$list"' EXIT
if ! find "$root" -type f -name '*.php' -print0 > "$list"; then exit 2; fi

total=0; passed=0; failed=0
while IFS= read -r -d '' file; do
    # TODO 2: Tăng total, dùng php -l kiểm tra cú pháp, tăng passed/failed
    # Đặt php -l trong nhánh if để không dừng vòng lặp khi gặp tệp lỗi
    ((total++))
    if php -l "$file" >/dev/null 2>&1; then
        ((passed++))
    else
        ((failed++))
    fi
done < "$list"

printf 'TOTAL=%s PASS=%s FAIL=%s\n' "$total" "$passed" "$failed"

# TODO 3: Thư mục không có tệp PHP (total=0) -> exit 2; Có tệp lỗi (failed>0) -> exit 1; Tất cả đạt -> exit 0
if [[ $total -eq 0 ]]; then
    exit 2
elif [[ $failed -gt 0 ]]; then
    exit 1
else
    exit 0
fi
