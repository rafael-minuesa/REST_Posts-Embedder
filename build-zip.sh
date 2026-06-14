#!/bin/bash
#
# Build release zip for REST Posts Embedder.
# Creates /tmp/restpostsembedder-X.Y.Z.zip with restpostsembedder/ as the root
# folder, excluding dev/CI files that shouldn't ship to production.
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_SLUG="restpostsembedder"

VERSION=$(grep -oP "define\('REST_POSTS_EMBEDDER_VERSION', '\K[0-9]+\.[0-9]+\.[0-9]+" "$PLUGIN_DIR/restpostsembedder.php")
if [[ -z "$VERSION" ]]; then
    echo "Error: Could not read version from restpostsembedder.php" >&2
    exit 1
fi

OUTPUT="/tmp/$PLUGIN_SLUG-$VERSION.zip"
STAGING="/tmp/$PLUGIN_SLUG-build"

echo "Building REST Posts Embedder v$VERSION..."
rm -rf "$STAGING"
mkdir -p "$STAGING/$PLUGIN_SLUG"

rsync -a --exclude='.git' \
         --exclude='.github' \
         --exclude='.gitignore' \
         --exclude='dev-tools' \
         --exclude='build-zip.sh' \
         --exclude='CLAUDE.md' \
         --exclude='CONTRIBUTING.md' \
         --exclude='.claude' \
         --exclude='*.po' \
         "$PLUGIN_DIR/" "$STAGING/$PLUGIN_SLUG/"

rm -f "$OUTPUT"
cd "$STAGING"
zip -rq "$OUTPUT" "$PLUGIN_SLUG/"
rm -rf "$STAGING"

echo "Built: $OUTPUT"
ls -lh "$OUTPUT"
