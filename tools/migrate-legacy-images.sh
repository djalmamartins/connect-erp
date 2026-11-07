#!/usr/bin/env bash
# Copies the legacy uploaded images that the database still references into
# storage/images (gitignored). Thumbnails in storage/images/cache are rebuilt
# on demand by Source\Support\Thumb, so the legacy cache is never copied.
#
# Usage: tools/migrate-legacy-images.sh <legacy public_html dir> <legacy dump.sql> [target storage/images]
set -euo pipefail

LEGACY="${1:?legacy public_html directory}"
DUMP="${2:?legacy SQL dump}"
TARGET="${3:-$(cd "$(dirname "$0")/.." && pwd)/storage/images}"
SRC="$LEGACY/storage/images"

[ -d "$SRC" ] || { echo "missing $SRC" >&2; exit 1; }
[ -f "$DUMP" ] || { echo "missing $DUMP" >&2; exit 1; }

copied=0; skipped=0
while IFS= read -r -d '' file; do
    rel="${file#"$SRC"/}"
    # Only images still referenced by the database (columns store "images/YYYY/MM/name").
    if grep -qF "images/$rel" "$DUMP"; then
        mkdir -p "$TARGET/$(dirname "$rel")"
        cp -n "$file" "$TARGET/$rel"
        copied=$((copied + 1))
    else
        skipped=$((skipped + 1))
    fi
done < <(find "$SRC" -type f -not -path "$SRC/cache/*" -not -name '.*' -print0)

mkdir -p "$TARGET/cache"
echo "copied=$copied skipped_unreferenced=$skipped target=$TARGET"
