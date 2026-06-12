#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 5 ]]; then
    echo "Usage: $0 <plugin-slug> <version> <zip-path> <svn-username> <svn-password>" >&2
    exit 1
fi

PLUGIN_SLUG="$1"
VERSION="$2"
ZIP_PATH="$3"
SVN_USERNAME="$4"
SVN_PASSWORD="$5"

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
PLUGIN_ROOT=$(dirname "$SCRIPT_DIR")
ASSETS_SOURCE_DIR="$PLUGIN_ROOT/assets-wporg"
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN_SLUG}/"

if [[ ! -f "$ZIP_PATH" ]]; then
    echo "Release zip not found: $ZIP_PATH" >&2
    exit 1
fi

if [[ ! -d "$ASSETS_SOURCE_DIR" ]]; then
    echo "Assets directory not found: $ASSETS_SOURCE_DIR" >&2
    exit 1
fi

WORKDIR=$(mktemp -d)
cleanup() {
    rm -rf "$WORKDIR"
}
trap cleanup EXIT

DIST_DIR="$WORKDIR/dist"
SVN_DIR="$WORKDIR/svn"
PLUGIN_SOURCE_DIR="$DIST_DIR/$PLUGIN_SLUG"
TRUNK_DIR="$SVN_DIR/trunk"
TAG_DIR="$SVN_DIR/tags/$VERSION"
ASSETS_TARGET_DIR="$SVN_DIR/assets"

mkdir -p "$DIST_DIR"
unzip -q "$ZIP_PATH" -d "$DIST_DIR"

if [[ ! -d "$PLUGIN_SOURCE_DIR" ]]; then
    echo "Expected extracted plugin directory not found: $PLUGIN_SOURCE_DIR" >&2
    exit 1
fi

svn checkout "$SVN_URL" "$SVN_DIR" \
    --non-interactive \
    --username "$SVN_USERNAME" \
    --password "$SVN_PASSWORD"

mkdir -p "$TRUNK_DIR" "$TAG_DIR" "$ASSETS_TARGET_DIR"

rsync -a --delete --exclude='.svn' "$PLUGIN_SOURCE_DIR/" "$TRUNK_DIR/"
rsync -a --delete --exclude='.svn' "$PLUGIN_SOURCE_DIR/" "$TAG_DIR/"

for file in \
    banner-772x250.png \
    banner-1544x500.png \
    icon-128x128.png \
    icon-256x256.png
do
    cp "$ASSETS_SOURCE_DIR/$file" "$ASSETS_TARGET_DIR/$file"
done

svn add --force "$TRUNK_DIR" "$TAG_DIR" "$ASSETS_TARGET_DIR" >/dev/null

while IFS= read -r missing_path; do
    [[ -n "$missing_path" ]] || continue
    svn rm --force "$missing_path" >/dev/null
done < <(svn status "$SVN_DIR" | awk '/^!/ {print substr($0, 9)}')

while IFS= read -r png_path; do
    [[ -n "$png_path" ]] || continue
    svn propset svn:mime-type image/png "$png_path" >/dev/null
done < <(find "$ASSETS_TARGET_DIR" -maxdepth 1 -type f -name '*.png' | sort)

if [[ -z "$(svn status "$SVN_DIR")" ]]; then
    echo "WordPress.org SVN is already up to date for ${VERSION}."
    exit 0
fi

svn commit "$SVN_DIR" \
    -m "Release ${VERSION}" \
    --non-interactive \
    --username "$SVN_USERNAME" \
    --password "$SVN_PASSWORD"
