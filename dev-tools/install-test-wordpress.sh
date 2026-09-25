#!/usr/bin/env bash
# Download matching WordPress source and PHPUnit libraries; never touch a database.
set -euo pipefail

version="${1:-6.8}"
destination="${WP_DEVELOP_DIR:-/tmp/rpe-wordpress}"
if [[ -e "$destination" ]]; then
    echo "Refusing to overwrite $destination. Choose a fresh WP_DEVELOP_DIR." >&2
    exit 1
fi

if [[ "$version" == latest ]]; then
    version=$(curl --fail --silent --show-error --location --retry 3 --max-time 120 \
        https://api.wordpress.org/core/version-check/1.7/ \
        | php -r '$data = json_decode(stream_get_contents(STDIN), true); if (empty($data["offers"][0]["version"])) { exit(1); } echo $data["offers"][0]["version"];')
fi
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
    echo "Expected a stable WordPress version or latest." >&2
    exit 1
fi

archive=$(mktemp)
trap 'rm -f "$archive"' EXIT
# The development repository tags initial major releases as e.g. 6.8.0.
tag="$version"
if [[ "$tag" =~ ^[0-9]+\.[0-9]+$ ]]; then
    tag="$tag.0"
fi
curl --fail --silent --show-error --location --retry 3 --max-time 120 \
    "https://github.com/WordPress/wordpress-develop/archive/refs/tags/$tag.tar.gz" \
    --output "$archive"
mkdir -p "$destination"
tar -xzf "$archive" --strip-components=1 -C "$destination"
echo "WordPress $version installed in $destination (database must already exist)."
