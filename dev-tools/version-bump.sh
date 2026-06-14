#!/bin/bash
#
# Version bump for REST Posts Embedder.
# Usage: ./dev-tools/version-bump.sh [major|minor|patch] "description"
# Updates: plugin header Version, REST_POSTS_EMBEDDER_VERSION constant,
#          readme.txt Stable tag, CHANGELOG.md.
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
MAIN_FILE="$PLUGIN_DIR/restpostsembedder.php"
README_TXT="$PLUGIN_DIR/readme.txt"
CHANGELOG="$PLUGIN_DIR/CHANGELOG.md"

CURRENT=$(grep -oP "define\('REST_POSTS_EMBEDDER_VERSION', '\K[0-9]+\.[0-9]+\.[0-9]+" "$MAIN_FILE")
[[ -z "$CURRENT" ]] && { echo "Error: could not read current version" >&2; exit 1; }

IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT"
BUMP_TYPE="${1:-}"; DESCRIPTION="${2:-}"
if [[ -z "$BUMP_TYPE" ]]; then echo "Current version: $CURRENT"; echo "Usage: $0 [major|minor|patch] \"description\""; exit 0; fi

case "$BUMP_TYPE" in
    major) MAJOR=$((MAJOR+1)); MINOR=0; PATCH=0 ;;
    minor) MINOR=$((MINOR+1)); PATCH=0 ;;
    patch) PATCH=$((PATCH+1)) ;;
    *) echo "Error: invalid bump type '$BUMP_TYPE'"; exit 1 ;;
esac

NEW_VERSION="$MAJOR.$MINOR.$PATCH"
DATE=$(date +%Y-%m-%d)
echo "Bumping: $CURRENT -> $NEW_VERSION"

sed -i "s/^ \* Version: .*/\ * Version:     $NEW_VERSION/" "$MAIN_FILE"
sed -i "s/define('REST_POSTS_EMBEDDER_VERSION', '.*'/define('REST_POSTS_EMBEDDER_VERSION', '$NEW_VERSION'/" "$MAIN_FILE"
[[ -f "$README_TXT" ]] && sed -i "s/^Stable tag: .*/Stable tag: $NEW_VERSION/" "$README_TXT"

if [[ -f "$CHANGELOG" ]]; then
    ENTRY="## [$NEW_VERSION] - $DATE"
    [[ -n "$DESCRIPTION" ]] && ENTRY="$ENTRY\n\n- $DESCRIPTION"
    sed -i "0,/^## \[/{ s/^## \[/$ENTRY\n\n## [/ }" "$CHANGELOG"
fi

echo "Done. Next: git commit, tag v$NEW_VERSION, ./build-zip.sh, ./dev-tools/publish-prowoos.sh"
