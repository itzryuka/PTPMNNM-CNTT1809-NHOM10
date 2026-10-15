#!/usr/bin/env bash
set -u
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)" || exit 2
fail=0
check_tool() {
  if command -v "$1" >/dev/null 2>&1; then
    printf 'OK tool %s\n' "$1"
  else
    printf 'MISSING tool %s\n' "$1" >&2
    fail=$((fail + 1))
  fi
}
for tool in php git; do check_tool "$tool"; done
for file in README.md public/index.php; do
  if [[ -f "$root/$file" ]]; then
    printf 'OK file %s\n' "$file"
  else
    printf 'MISSING file %s\n' "$file" >&2
    fail=$((fail + 1))
  fi
done
if [[ "$fail" -gt 0 ]]; then exit 2; fi
if php -l "$root/public/index.php"; then
  echo 'CHECK_ENV=PASS'
else
  echo 'CHECK_ENV=FAIL' >&2
  exit 1
fi
