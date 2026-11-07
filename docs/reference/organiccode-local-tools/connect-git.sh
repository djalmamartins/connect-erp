#!/usr/bin/env bash
# Connects this folder to GitHub keeping every local file. Does not commit or push.
set -euo pipefail

cd "$(dirname "$0")/../.."
base=origin/setup/organiccode-foundation
branch=setup/local-environment

if git rev-parse --verify -q HEAD >/dev/null; then
  echo "Este repositório já tem commits; nada foi alterado." >&2
  exit 1
fi

[[ -d .git ]] || git init -q -b "$branch"
git symbolic-ref HEAD "refs/heads/$branch"
git remote get-url origin >/dev/null 2>&1 || git remote add origin https://github.com/djalmamartins/organiccode
git fetch -q --no-tags origin

# Mixed reset only moves HEAD and the index; the working tree stays untouched.
git reset -q "$base"

# Brings back only files that exist on the remote and are missing locally.
git ls-files --deleted -z | while IFS= read -r -d '' file; do
  git restore -- "$file"
  echo "Restaurado do remoto: $file"
done

git status --short --branch
echo "Ignorados (devem aparecer .env e .local):"
git check-ignore .env .local || true
