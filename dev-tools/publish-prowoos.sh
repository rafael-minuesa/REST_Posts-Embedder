#!/bin/bash
#
# Publish the built zip + manifest to prowoos.com's self-hosted update server.
# Prereqs: run build-zip.sh first; SSH alias `prowoos-webdock` configured.
# Usage: ./dev-tools/publish-prowoos.sh ["Changelog override text"]
#
# Releases live in a root-owned directory outside the web root. nginx maps the
# public URL to it:
#
#   location ^~ /wp-content/uploads/rpe-updates/ {
#       alias /srv/plugin-updates/rpe-updates/;
#   }
#
# That block lives in /etc/nginx/sites-enabled/webdock, which the Webdock panel
# rewrites when a web server action is run there (certificates, web root). If
# the block is lost, this script stops at its checksum test; put the block back
# and reload nginx.
#
# The web process (www-data) must never be able to write a release, its
# directory or any parent directory: installed plugins take the package URL
# from the manifest, so whoever can replace these files can ship code to every
# site that updates. Do not move them back under wp-content/uploads, which
# www-data owns.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_SLUG="restpostsembedder"
SSH_ALIAS="prowoos-webdock"
REMOTE_DIR="/srv/plugin-updates/rpe-updates"
PUBLIC_BASE="https://prowoos.com/wp-content/uploads/rpe-updates"
MANIFEST_NAME="${PLUGIN_SLUG}.json"

VERSION=$(grep -oP "define\('REST_POSTS_EMBEDDER_VERSION', '\K[0-9]+\.[0-9]+\.[0-9]+" "$PLUGIN_DIR/restpostsembedder.php")
[[ -z "$VERSION" ]] && { echo "Error: could not read version" >&2; exit 1; }

ZIP_LOCAL="/tmp/${PLUGIN_SLUG}-${VERSION}.zip"
PACKAGE_URL="${PUBLIC_BASE}/${PLUGIN_SLUG}-${VERSION}.zip"
MANIFEST_URL="${PUBLIC_BASE}/${MANIFEST_NAME}"

[[ ! -f "$ZIP_LOCAL" ]] && { echo "Error: $ZIP_LOCAL not found. Run ./build-zip.sh first." >&2; exit 1; }

# Version requirements come from readme.txt so the manifest never drifts from it.
readme_field() {
    grep -m1 -oP "^$1:\s*\K\S+" "$PLUGIN_DIR/readme.txt" || true
}
TESTED=$(readme_field "Tested up to")
REQUIRES=$(readme_field "Requires at least")
REQUIRES_PHP=$(readme_field "Requires PHP")
for field in TESTED REQUIRES REQUIRES_PHP; do
    [[ -z "${!field}" ]] && { echo "Error: could not read $field from readme.txt" >&2; exit 1; }
done

# Every directory from / down to the release directory must be owned by root
# and closed to group and other writes, or the files could be swapped by
# renaming a parent.
ssh "$SSH_ALIAS" "sudo install -d -o root -g root -m 755 ${REMOTE_DIR}"
ssh "$SSH_ALIAS" "bash -s" <<REMOTE
set -euo pipefail
dir="${REMOTE_DIR}"
while :; do
    read -r owner mode < <(stat -c '%U %a' "\$dir")
    if [[ "\$owner" != root ]] || (( 8#\$mode & 8#022 )); then
        echo "Error: \$dir is \$owner \$mode; it must be owned by root and not group- or world-writable." >&2
        exit 1
    fi
    [[ "\$dir" == / ]] && break
    dir="\$(dirname "\$dir")"
done
REMOTE

# Stage uploads in a private directory in the deploy user's home, not in the
# world-writable /tmp, then install them as root.
STAGE=$(ssh "$SSH_ALIAS" 'mktemp -d "$HOME/rpe-publish.XXXXXX"')
[[ "$STAGE" == /*/rpe-publish.* ]] || { echo "Error: could not create a staging directory" >&2; exit 1; }
trap 'ssh "$SSH_ALIAS" "rm -rf -- \"$STAGE\"" || true; rm -f "${MANIFEST_LOCAL:-}"' EXIT

# Install a staged file as root and confirm the public URL serves those bytes.
# A mismatch means nginx is not serving from REMOTE_DIR (alias missing).
publish_file() {
    local local_file="$1" name="$2" expected served
    expected=$(sha256sum "$local_file" | cut -d' ' -f1)
    scp -q "$local_file" "${SSH_ALIAS}:${STAGE}/${name}"
    ssh "$SSH_ALIAS" "sudo install -o root -g root -m 644 '${STAGE}/${name}' '${REMOTE_DIR}/${name}'"
    served=$(curl --fail --silent --show-error --location --max-time 60 "${PUBLIC_BASE}/${name}?verify=${expected}" | sha256sum | cut -d' ' -f1)
    if [[ "$served" != "$expected" ]]; then
        echo "Error: ${PUBLIC_BASE}/${name} does not serve the file just published." >&2
        echo "  Check that the nginx alias to ${REMOTE_DIR} still exists (see the top of this script)." >&2
        echo "  expected sha256 $expected" >&2
        echo "  served   sha256 $served" >&2
        exit 1
    fi
}

echo "Uploading ${ZIP_LOCAL}"
publish_file "$ZIP_LOCAL" "${PLUGIN_SLUG}-${VERSION}.zip"

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
  "tested":       "$TESTED",
  "requires":     "$REQUIRES",
  "requires_php": "$REQUIRES_PHP",
  "homepage":     "https://github.com/rafael-minuesa/REST_Posts-Embedder",
  "updated":      "$UPDATED_ISO",
  "changelog":    $CHANGELOG_JSON
}
JSON

echo "Uploading manifest"
publish_file "$MANIFEST_LOCAL" "$MANIFEST_NAME"

echo ""
echo "Published v$VERSION."
echo "  Manifest: $MANIFEST_URL"
echo "  Package:  $PACKAGE_URL"
