#!/usr/bin/env bash
set -uo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." || exit 2
tmp="$(mktemp -d)" || exit 2
trap 'rm -rf -- "$tmp"' EXIT
mkdir -p "$tmp/valid/sub folder" "$tmp/mixed/sub folder" "$tmp/empty"
cp fixtures/valid/a.php "$tmp/valid/a.php"
cp 'fixtures/valid/sub folder/b.php' "$tmp/valid/sub folder/b.php"
cp -r "$tmp/valid/." "$tmp/mixed/"
cp fixtures/broken.txt "$tmp/mixed/broken.php"
failed=0
run_case() {
  local label="$1" dir="$2" expected="$3" summary="$4" actual output
  output="$(bash scripts/scan-source.sh "$dir" 2>&1)"; actual=$?
  if [[ "$actual" -eq "$expected" ]] && { [[ -z "$summary" ]] || grep -Fq "$summary" <<< "$output"; }; then
    echo "PASS $label"
  else
    echo "FAIL $label expected_exit=$expected actual_exit=$actual"
    printf '%s\n' "$output"
    failed=$((failed + 1))
  fi
}
run_case valid "$tmp/valid" 0 'TOTAL=2 PASS=2 FAIL=0'
run_case mixed "$tmp/mixed" 1 'TOTAL=3 PASS=2 FAIL=1'
run_case missing "$tmp/not-found" 2 ''
run_case empty "$tmp/empty" 2 ''
echo "SHELL_CASES=4 FAILED=$failed"
[[ "$failed" -eq 0 ]]
