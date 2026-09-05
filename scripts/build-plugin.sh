#!/bin/bash
# Assembles the installable plugin tree and packages it as a .txz + .md5,
# for attaching to a GitHub Release (see CLAUDE.md "Shipping model").
#
# Run by .github/workflows/release.yml on every push to main that names an
# untagged &version;, and available locally for a test build (needs xz, which
# the claude-code container does not have). Building a package is not the same
# as publishing it: this script only produces local files — it neither tags nor
# publishes anything. See CLAUDE.md "Deploy and verify".
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
#
# That slot is a one-line description in a shared table, NOT documentation, so
# it gets its own file: plugin/README.md, following the stock convention of a
# bold name plus one paragraph and no headings. Packaging the repo's own
# README.md instead put its `# unraid-secretsman` H1 into that table and
# rendered the row several times the size of every stock plugin beside it —
# see issue #2.
cp "$REPO_ROOT"/plugin/README.md "$PKG_DIR/"

mkdir -p "$OUT_DIR"
TXZ_PATH="$OUT_DIR/$NAME-$VERSION.txz"
( cd "$BUILD_DIR" && tar -cJf "$TXZ_PATH" usr )

MD5=$(md5sum "$TXZ_PATH" | awk '{print $1}')
echo "$MD5" > "$OUT_DIR/$NAME.md5"

echo "Built: $TXZ_PATH"
echo "  md5: $MD5"
echo
echo "This is a local build. Releasing is automatic and does not use it:"
echo "  bump &version; in unraid-secretsman.plg to the new version, write its ###<version>"
echo "  CHANGES entry, and push to main. .github/workflows/release.yml builds, writes"
echo "  the real &md5; back into the .plg, tags v<version> and publishes the release."
echo "  Then run scripts/install-on-host.sh to put it on the Unraid host."
echo "  See CLAUDE.md \"Deploy and verify\"."
