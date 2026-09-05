#!/bin/bash
# Installs a published release on the Unraid host and verifies the result.
#
# This is the half of "release" that GitHub Actions cannot do: its runners have
# no route to 192.168.0.10. Run it from the claude-code container after the
# Release workflow has published a version.
#
# Usage: scripts/install-on-host.sh [version] [--forced]
#   version defaults to the &version; entity in the local .plg.
#   --forced passes Unraid's own "forced" argument, which is needed exactly once
#   when the new version does not sort AFTER the installed one under strcmp.
#   Unraid compares plugin versions as plain strings
#   (dynamix.plugin.manager/scripts/plugin: strcmp($version, $installed_version) < 0
#   -> "not installing older version"), so "1.0.10" is older than "1.0.9" to it,
#   and anything numbered 1.x is older than a date-based 2026.08.25. This script
#   checks for that before it tries, and says which flag to add rather than
#   leaving you to decode "not installing older version".
#
# Env: UNRAID_HOST (default 192.168.0.10), UNRAID_SSH_KEY (default
#      /root/.ssh/unraid_secretsman).
#
# raw.githubusercontent.com caches for max-age=300, and a 200 seconds after a
# push does NOT mean the CDN edge the host hits has the new body — see the
# sibling repo unraid-docker-netman's CLAUDE.md for the incident where a stale,
# internally-consistent .plg silently reinstalled the wrong version with no
# error. This script refuses to install until the raw body's OWN &version; and
# &md5; match the published release, rather than trusting a status code.
#
# It verifies afterwards that Helpers.php is actually patched: this plugin's
# whole reason for existing is that patch, /usr/local/emhttp is restored from
# the OS image on every boot, and an install that lands its files but fails to
# patch is indistinguishable from a good one until a container fails to create.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="unraid-secretsman"
PLG="$NAME.plg"
GH_REPO="kmbrimble/unraid-secretsman"
PLG_URL="https://raw.githubusercontent.com/$GH_REPO/main/$PLG"
HOST="${UNRAID_HOST:-192.168.0.10}"
SSH_KEY="${UNRAID_SSH_KEY:-/root/.ssh/unraid_secretsman}"
SSH=(ssh -o StrictHostKeyChecking=no -i "$SSH_KEY" "root@$HOST")

FORCED=""
VERSION=""
for arg in "$@"; do
  case "$arg" in
    --forced|--force) FORCED="forced" ;;
    *) VERSION="$arg" ;;
  esac
done
[ -n "$VERSION" ] || VERSION=$(sed -rn 's|^<!ENTITY version[[:space:]]+"([^"]+)">.*|\1|p' "$REPO_ROOT/$PLG")
[ -n "$VERSION" ] || { echo "could not determine version"; exit 1; }

echo "==> Release v$VERSION"
gh release view "v$VERSION" --repo "$GH_REPO" --json tagName,assets \
  -q '.tagName + "  assets: " + ([.assets[].name] | join(", "))'

EXPECTED_MD5=$(curl -fsSL "https://github.com/$GH_REPO/releases/download/v$VERSION/$NAME.md5" | tr -d '[:space:]')
[ -n "$EXPECTED_MD5" ] || { echo "release has no $NAME.md5 asset"; exit 1; }
echo "    package md5: $EXPECTED_MD5"

echo "==> Waiting for raw.githubusercontent.com to serve v$VERSION (CDN cache is 5 min)"
for i in $(seq 1 40); do
  BODY=$(curl -fsSL -H 'Cache-Control: no-cache' "$PLG_URL" || true)
  RAW_VERSION=$(printf '%s' "$BODY" | sed -rn 's|^<!ENTITY version[[:space:]]+"([^"]+)">.*|\1|p')
  RAW_MD5=$(printf '%s' "$BODY" | sed -rn 's|^<!ENTITY md5[[:space:]]+"([^"]+)">.*|\1|p')
  if [ "$RAW_VERSION" = "$VERSION" ] && [ "$RAW_MD5" = "$EXPECTED_MD5" ]; then
    echo "    raw .plg is v$RAW_VERSION with the right md5 after ${i} check(s)"
    break
  fi
  echo "    attempt $i: raw says version=${RAW_VERSION:-?} md5=${RAW_MD5:0:8}... — waiting"
  [ "$i" -lt 40 ] || { echo "raw .plg never caught up to v$VERSION"; exit 1; }
  sleep 15
done

echo "==> Checking how Unraid will compare this against what is installed"
INSTALLED=$("${SSH[@]}" "sed -rn 's|^<!ENTITY version[[:space:]]+\"([^\"]+)\">.*|\\1|p' /boot/config/plugins/unraid-secretsman.plg 2>/dev/null" || true)
if [ -z "$INSTALLED" ]; then
  echo "    nothing installed yet — a clean install"
elif python3 -c 'import sys; sys.exit(0 if sys.argv[2] > sys.argv[1] else 1)' "$INSTALLED" "$VERSION"; then
  echo "    installed $INSTALLED, new $VERSION — sorts newer, normal install"
else
  echo "    installed $INSTALLED, new $VERSION — strcmp puts the new one FIRST, so the"
  echo "    plugin manager will refuse it as an older version."
  if [ -z "$FORCED" ]; then
    echo
    echo "    This is not a corrupt package and not a reason to renumber the release."
    echo "    Re-run with --forced to cross the line once:"
    echo "        scripts/install-on-host.sh $VERSION --forced"
    exit 1
  fi
  echo "    --forced given — crossing it."
fi

echo "==> Installing on $HOST"
"${SSH[@]}" "plugin install '$PLG_URL' $FORCED"

echo "==> Verifying"
"${SSH[@]}" bash -s -- "$NAME" "$VERSION" <<'REMOTE'
set -euo pipefail
NAME="$1"; VERSION="$2"
INSTALLED=$(sed -rn 's|^<!ENTITY version[[:space:]]+"([^"]+)">.*|\1|p' "/boot/config/plugins/$NAME.plg")
echo "  flash .plg version: $INSTALLED"
[ "$INSTALLED" = "$VERSION" ] || { echo "  FAIL: flash .plg is $INSTALLED, expected $VERSION"; exit 1; }
[ -L "/var/log/plugins/$NAME.plg" ] || { echo "  FAIL: not registered in /var/log/plugins"; exit 1; }
echo "  registered: yes"
for f in README.md SecretsMan.page src/secretsman.php src/patch.php src/backup.php scripts/apply_patch.php scripts/store_api.php scripts/backup_api.php; do
  [ -f "/usr/local/emhttp/plugins/$NAME/$f" ] || { echo "  FAIL: missing $f"; exit 1; }
done
echo "  installed tree: complete"
HELPERS=/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php
grep -q 'SECRETSMAN-PATCH-BEGIN' "$HELPERS" || { echo "  FAIL: Helpers.php is NOT patched"; exit 1; }
echo "  Helpers.php: patched"
head -1 "/usr/local/emhttp/plugins/$NAME/README.md"
grep -q '^# ' "/usr/local/emhttp/plugins/$NAME/README.md" && { echo "  FAIL: packaged README has a heading — the Plugins row will be oversized"; exit 1; }
echo "  Plugins-tab description: stock shape, no heading"
REMOTE

echo "==> Done: v$VERSION installed on $HOST"
