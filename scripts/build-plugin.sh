#!/bin/bash
# Assembles the installable plugin tree and packages it as a .txz + .md5,
# for attaching to a GitHub Release (see CLAUDE.md "Shipping model").
#
# NOT run automatically by anything — a deliberate, manual release step.
# Building a package is not the same as publishing it: this script only
# produces local files; cutting the actual GitHub Release is a separate,
# explicit action.
#
# Usage: scripts/build-plugin.sh [version]
#   version defaults to today's date (YYYY.MM.DD), matching the .plg's
#   own version-entity convention.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(date +%Y.%m.%d)}"
NAME="unraid-secretsman"
# Slackware's upgradepkg/installpkg extracts a package relative to /, not
# relative to any "plugins" convention — the full absolute destination path
# has to be baked into the archive itself, or files land at /unraid-secretsman
# instead of /usr/local/emhttp/plugins/unraid-secretsman. Confirmed live: this
# exact mistake caused the Phase 2 Gate 2 .plg install failure (apply_patch.php
# "Could not open input file", exit 1) — see docs/phase2-resume.md.
INSTALL_ROOT="usr/local/emhttp/plugins/$NAME"
BUILD_DIR="$(mktemp -d)"
PKG_DIR="$BUILD_DIR/$INSTALL_ROOT"
OUT_DIR="$REPO_ROOT/dist"

trap 'rm -rf "$BUILD_DIR"' EXIT

echo "Building $NAME-$VERSION.txz ..."

mkdir -p "$PKG_DIR"/{src,scripts,reference}

# Resolve the repo's src/ <-> plugin/src symlink into real files in the
# package — a .txz has no business shipping symlinks that only make sense
# inside this git checkout.
cp "$REPO_ROOT"/src/*.php "$PKG_DIR/src/"
cp "$REPO_ROOT"/plugin/scripts/*.php "$PKG_DIR/scripts/"
cp -r "$REPO_ROOT"/reference/. "$PKG_DIR/reference/"

# .page files must sit in the plugin's installed ROOT — Unraid's page loader
# (build_pages('plugins/*/*.page')) globs non-recursively, so anything under
# a subdirectory is silently never registered. Confirmed on a real host: a
# competing plugin's page under its own pages/ subdir is not reachable.
cp "$REPO_ROOT"/plugin/*.page "$PKG_DIR/"

# The Installed Plugins page reads its description from README.md at the
# plugin's installed root (dynamix.plugin.manager/include/ShowPlugins.php:
# `plugins/{name}/README.md`, Markdown-rendered, falls back to just the bare
# plugin name if absent) — confirmed missing was the entire reason no
# description showed there.
cp "$REPO_ROOT"/README.md "$PKG_DIR/"

mkdir -p "$OUT_DIR"
TXZ_PATH="$OUT_DIR/$NAME-$VERSION.txz"
( cd "$BUILD_DIR" && tar -cJf "$TXZ_PATH" usr )

MD5=$(md5sum "$TXZ_PATH" | awk '{print $1}')
echo "$MD5" > "$OUT_DIR/$NAME.md5"

echo "Built: $TXZ_PATH"
echo "  md5: $MD5"
echo
echo "Next (manual, not run by this script):"
echo "  1. Update unraid-secretsman.plg: &version; to $VERSION, &md5; to $MD5"
echo "  2. git commit the updated .plg"
echo "  3. gh release create v$VERSION $TXZ_PATH $OUT_DIR/$NAME.md5"
