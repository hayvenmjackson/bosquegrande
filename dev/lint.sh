#!/usr/bin/env bash
# Syntax-check every PHP file with each PHP binary given in PHP_BINS
# (default: php), and every JavaScript file with node when it is installed.
#   PHP_BINS="php8.0 php8.3 php8.4" dev/lint.sh
cd "$(dirname "$0")/../bosquegrande" || exit 1
fail=0
for bin in ${PHP_BINS:-php}; do
  command -v "$bin" >/dev/null || { echo "skip: $bin not found"; continue; }
  while IFS= read -r f; do
    out=$("$bin" -l "$f" 2>&1) || { echo "$bin: $out"; fail=1; }
  done < <(find . -name '*.php')
done
if command -v node >/dev/null; then
  while IFS= read -r f; do node --check "$f" || fail=1; done < <(find . -name '*.js')
fi
[ $fail = 0 ] && echo "lint ok"
exit $fail
