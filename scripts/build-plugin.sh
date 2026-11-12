#!/bin/bash
# Assembles the installable plugin tree and packages it as a .txz + .md5,
# for attaching to a GitHub Release (see CLAUDE.md "Shipping model").
#
# Run by Gitea CI (release-readiness) and by .github/workflows/release.yml on a mirrored v* tag, and available
# locally for a test build (needs GNU tar and xz); a local build gives the same md5 as CI. Building a package is not the same
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

mkdir -p "$PKG_DIR"/{src,scripts,reference,app}

# Resolve the repo's src/ <-> plugin/src symlink into real files in the
# package — a .txz has no business shipping symlinks that only make sense
# inside this git checkout.
cp "$REPO_ROOT"/src/*.php "$PKG_DIR/src/"
cp "$REPO_ROOT"/plugin/scripts/*.php "$PKG_DIR/scripts/"
cp -r "$REPO_ROOT"/reference/. "$PKG_DIR/reference/"

# The web app (ui/, Preact + Vite): index.php is the PHP shell, assets/ is the built JS and CSS. The build is
# reproducible (fixed output names, no sourcemaps, lockfile-pinned) so the archive md5 matches across runners.
# SECRETSMAN_UI_DIST points at a prebuilt dist (tests/run.php builds the UI once and reuses it); unset, this builds.
UI_DIST="${SECRETSMAN_UI_DIST:-}"
if [ -z "$UI_DIST" ]; then
  echo "  building ui/ ($(node --version), npm $(npm --version))" >&2
  ( cd "$REPO_ROOT/ui" && npm ci --no-audit --no-fund --loglevel=error && npm run build ) >&2
  UI_DIST="$REPO_ROOT/ui/dist"
fi
if [ ! -f "$UI_DIST/assets/app.js" ] || [ ! -f "$UI_DIST/assets/app.css" ]; then
  echo "build-plugin.sh: $UI_DIST/assets/app.js or app.css is missing: the UI did not build" >&2
  exit 1
fi
cp "$REPO_ROOT"/plugin/app/index.php "$PKG_DIR/app/"
cp -r "$UI_DIST/assets" "$PKG_DIR/app/assets"

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
# Deterministic archive: the same source must give the same bytes on any machine, because the .plg's &md5; is
# committed before the tag and the GitHub release workflow rebuilds and requires it to match (scripts/check-md5.sh).
# - sorted names, fixed mtime, numeric root owner;
# - --mode: cp/mkdir/git checkout all inherit the caller's umask, so normalise to 0644 files / 0755 dirs;
# - xz -T1 on the command line: xz >= 5.6 defaults to multi-threaded, which writes sizes into the block header and
#   changes the bytes (identical tar stream, different .txz). Command-line flags beat XZ_OPT/XZ_DEFAULTS.
# tests/run.php builds under different umask/TZ/XZ_DEFAULTS and requires identical md5s.
echo "  $(tar --version | head -1); $(xz --version | head -1)" >&2  # stderr: check-md5.sh discards stdout
( cd "$BUILD_DIR" && tar --sort=name --mtime='UTC 2020-01-01' --owner=0 --group=0 --numeric-owner \
    --mode='u=rwX,go=rX' -I 'xz -6 -T1' -cf "$TXZ_PATH" usr )

MD5=$(md5sum "$TXZ_PATH" | awk '{print $1}')
echo "$MD5" > "$OUT_DIR/$NAME.md5"

echo "Built: $TXZ_PATH"
echo "  md5: $MD5"
echo
echo "This is a local build. A release is: bump &version; in unraid-secretsman.plg, write its ###<version>"
echo "  CHANGES entry, set &md5; to the md5 above (CI tells you the value), merge the PR on Gitea, then tag"
echo "  v<version> on Gitea. The tag is mirrored to GitHub and .github/workflows/release.yml publishes it."
echo "  Then run scripts/install-on-host.sh to put it on the Unraid host. See CLAUDE.md \"Deploy and verify\"."
