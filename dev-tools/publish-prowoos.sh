#!/bin/bash
#
# Publish the built zip + manifest to prowoos.com's self-hosted update server.
# Prereqs: run build-zip.sh first; SSH alias `prowoos-webdock` configured.
# Usage: ./dev-tools/publish-prowoos.sh ["Changelog override text"]
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_SLUG="restpostsembedder"
SSH_ALIAS="prowoos-webdock"
REMOTE_DIR="/var/www/html/wp-content/uploads/rpe-updates"
MANIFEST_NAME="${PLUGIN_SLUG}.json"

VERSION=$(grep -oP "define\('REST_POSTS_EMBEDDER_VERSION', '\K[0-9]+\.[0-9]+\.[0-9]+" "$PLUGIN_DIR/restpostsembedder.php")
[[ -z "$VERSION" ]] && { echo "Error: could not read version" >&2; exit 1; }

ZIP_LOCAL="/tmp/${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_REMOTE="${REMOTE_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"
MANIFEST_REMOTE="${REMOTE_DIR}/${MANIFEST_NAME}"
PACKAGE_URL="https://prowoos.com/wp-content/uploads/rpe-updates/${PLUGIN_SLUG}-${VERSION}.zip"

[[ ! -f "$ZIP_LOCAL" ]] && { echo "Error: $ZIP_LOCAL not found. Run ./build-zip.sh first." >&2; exit 1; }

ssh "$SSH_ALIAS" "sudo mkdir -p ${REMOTE_DIR} && sudo chown www-data:www-data ${REMOTE_DIR}"

echo "Uploading ${ZIP_LOCAL}"
scp -q "$ZIP_LOCAL" "${SSH_ALIAS}:/tmp/${PLUGIN_SLUG}-${VERSION}.zip"
ssh "$SSH_ALIAS" "sudo mv /tmp/${PLUGIN_SLUG}-${VERSION}.zip ${ZIP_REMOTE} && sudo chown www-data:www-data ${ZIP_REMOTE} && sudo chmod 644 ${ZIP_REMOTE}"

CHANGELOG_SECTION=""
if [[ -f "$PLUGIN_DIR/CHANGELOG.md" ]]; then
    CHANGELOG_SECTION=$(awk -v ver="$VERSION" '
        $0 ~ "^## \\[" ver "\\]" { in_s=1; print; next }
        in_s && /^## \[/ { in_s=0 }
        in_s { print }' "$PLUGIN_DIR/CHANGELOG.md")
fi
[[ $# -gt 0 ]] && CHANGELOG_SECTION="$1"

UPDATED_ISO="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
MANIFEST_LOCAL="$(mktemp)"
CHANGELOG_JSON=$(printf '%s' "$CHANGELOG_SECTION" | python3 -c 'import sys,json; sys.stdout.write(json.dumps(sys.stdin.read()))')

cat > "$MANIFEST_LOCAL" <<JSON
{
  "version":      "$VERSION",
  "package":      "$PACKAGE_URL",
  "tested":       "6.9",
  "requires":     "5.0",
  "requires_php": "7.4",
  "homepage":     "https://github.com/rafael-minuesa/REST_Posts-Embedder",
  "updated":      "$UPDATED_ISO",
  "changelog":    $CHANGELOG_JSON
}
JSON

echo "Uploading manifest"
scp -q "$MANIFEST_LOCAL" "${SSH_ALIAS}:/tmp/${MANIFEST_NAME}"
ssh "$SSH_ALIAS" "sudo mv /tmp/${MANIFEST_NAME} ${MANIFEST_REMOTE} && sudo chown www-data:www-data ${MANIFEST_REMOTE} && sudo chmod 644 ${MANIFEST_REMOTE}"
rm -f "$MANIFEST_LOCAL"

echo ""
echo "Published v$VERSION."
echo "  Manifest: https://prowoos.com/wp-content/uploads/rpe-updates/${MANIFEST_NAME}"
echo "  Package:  $PACKAGE_URL"
