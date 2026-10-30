#!/usr/bin/env bash
# Usage: scripts/add-reference-hash.sh <unraid/webgui tag> [reference-dir-to-diff-against]
#   e.g. scripts/add-reference-hash.sh 7.4.0 reference/7.4.x
#
# Fetches the tag read-only, checks the patch anchor is unique, lists the
# xmlToCommand() call sites, shows the Helpers.php diff against the current
# reference, and PRINTS the HASHES line plus the commit it came from.
# It changes nothing in the repo. Read the diff (unraid-upgrade-reverify
# skill, step 4) before adding the line; this script is not the gate.
set -euo pipefail
tag=${1:?usage: $0 <webgui-tag> [reference-dir]}
repo=$(cd "$(dirname "$0")/.." && pwd)
ref=${2:-$repo/reference/$(echo "$tag" | sed -E 's/^([0-9]+\.[0-9]+).*/\1.x/')}
work=$(mktemp -d); trap 'rm -rf "$work"' EXIT
GIT_LFS_SKIP_SMUDGE=1 git clone -q --depth 1 --branch "$tag" https://github.com/unraid/webgui "$work/w"
f=$work/w/emhttp/plugins/dynamix.docker.manager/include/Helpers.php
[ -f "$f" ] || { echo "Helpers.php not at the expected path in $tag -- locate xmlToCommand() first" >&2; exit 1; }
anchor=$(grep -c '^  foreach (\$xml\[.Config.\] as \$key => \$config) {$' "$f" || true)
[ "$anchor" = 1 ] || { echo "ANCHOR COUNT $anchor (need exactly 1) -- do not add a hash" >&2; exit 1; }
echo "== xmlToCommand() definitions / call sites (expect 1 / 5) =="
grep -rn 'function xmlToCommand' "$work/w/emhttp" --include=* | wc -l
grep -rn 'xmlToCommand(' "$work/w/emhttp" | grep -v 'function xmlToCommand'
if [ -f "$ref/Helpers.php" ]; then echo "== diff vs $ref/Helpers.php =="; diff -u "$ref/Helpers.php" "$f" || true; fi
echo "== add to HASHES =="
echo "# $tag, commit $(git -C "$work/w" rev-parse HEAD)"
echo "Helpers.php  md5:$(md5sum "$f" | cut -d' ' -f1)"
