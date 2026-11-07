#!/usr/bin/env bash
# Non-destructive: check only tracked/modified PHP files when PHP is installed.
set -euo pipefail
command -v php >/dev/null || exit 0
if command -v git >/dev/null && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  git diff --name-only --diff-filter=ACMRT -z | while IFS= read -r -d '' file; do
    [[ "$file" == *.php && -f "$file" ]] && php -l "$file" || true
  done
fi
