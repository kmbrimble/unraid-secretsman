#!/bin/bash
# Publish a Gitea release for a plugin repo: gates, deterministic rebuild, md5 check, release + asset upload, readback.
# SHARED SCRIPT: the same file lives in every plugin repo (canonical copy: unraid-flightplan/scripts/gitea-release.sh;
# change it there, then copy it). Per-repo differences (toolchain setup, extra tests) stay in .gitea/workflows/release.yml.
#
# Usage: scripts/gitea-release.sh <tag> [--dry-run]
#   <tag>        v<version>; must equal v<&version;> in the single *.plg at the repo root
#   --dry-run    run every gate and the rebuild, print the md5, publish nothing
# Environment: GITEA_TOKEN (the job token, or a narrowly scoped Actions secret), GITEA_API_URL and GITHUB_REPOSITORY
# (both set by the runner; the first may be overridden). The token is only ever sent as an Authorization header to
# the API origin, never printed and never put on a command line (curl reads headers from stdin via -H @-).
#
# Gates: (1) tag == plg version; (2) a ###<version> CHANGES entry; (3) strcmp ordering against every other v* tag
# (Unraid compares versions with strcmp; the new one must sort strictly after all of them); (4) the repo's own
# scripts/check-md5.sh rebuilds and requires the plg &md5; to match. Idempotent: an existing release only gets
# missing assets uploaded; an existing asset with different bytes is an error, never overwritten.
set -euo pipefail
export LC_ALL=C

TAG="${1:?usage: gitea-release.sh <tag> [--dry-run]}"
DRY=0; [ "${2:-}" = "--dry-run" ] && DRY=1
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

shopt -s nullglob
PLGS=(*.plg)
[ "${#PLGS[@]}" -eq 1 ] || { echo "::error::expected exactly one *.plg at the repo root, found ${#PLGS[@]}"; exit 1; }
PLG="${PLGS[0]}"
NAME="$(sed -rn 's|^<!ENTITY name[[:space:]]+"([^"]+)">.*|\1|p' "$PLG")"
PLG_VERSION="$(sed -rn 's|^<!ENTITY version[[:space:]]+"([^"]+)">.*|\1|p' "$PLG")"
PLG_MD5="$(sed -rn 's|^<!ENTITY md5[[:space:]]+"([^"]+)">.*|\1|p' "$PLG")"
[ -n "$NAME" ] && [ -n "$PLG_VERSION" ] && [ -n "$PLG_MD5" ] || { echo "::error::could not read name/version/md5 from $PLG"; exit 1; }
VERSION="${TAG#v}"

[ "$TAG" = "v$PLG_VERSION" ] || { echo "::error::tag $TAG does not match $PLG &version; $PLG_VERSION"; exit 1; }
grep -qx "###$VERSION" "$PLG" || { echo "::error::$PLG has no '###$VERSION' entry in <CHANGES>"; exit 1; }

# strcmp ordering against every other tag (the plugin manager refuses anything that does not sort after the installed version)
while read -r other; do
  [ -z "$other" ] && continue
  if ! [[ "$VERSION" > "${other#v}" ]]; then
    echo "::error::$VERSION does not sort after ${other#v} under strcmp (Unraid's rule). Zero-pad; never let a component reach two digits."
    exit 1
  fi
done < <(git tag --list 'v*' | grep -vx "$TAG" || true)
echo "ordering ok: $VERSION sorts after every other tag"

bash scripts/check-md5.sh "$VERSION"
TXZ="dist/$NAME-$VERSION.txz"
[ -f "$TXZ" ] || { echo "::error::$TXZ was not built"; exit 1; }
BUILT="$(md5sum "$TXZ" | awk '{print $1}')"
[ "$BUILT" = "$PLG_MD5" ] || { echo "::error::built md5 $BUILT != $PLG &md5; $PLG_MD5"; exit 1; }

ASSETS=("$TXZ")
for extra in "dist/$NAME.md5" "dist/$NAME.sha256"; do [ -f "$extra" ] && ASSETS+=("$extra"); done
echo "assets: ${ASSETS[*]}"

if [ "$DRY" = 1 ]; then echo "dry run: md5 $BUILT matches; nothing published"; exit 0; fi

: "${GITEA_TOKEN:?GITEA_TOKEN is not set}"
API="${GITEA_API_URL:-${GITHUB_API_URL:?no API url}}"
REPO="${GITHUB_REPOSITORY:?no repository}"
api() { # api METHOD PATH [curl args...]; token via stdin header, never argv
  local m="$1" p="$2"; shift 2
  printf 'Authorization: token %s\n' "$GITEA_TOKEN" | curl -sS -X "$m" -H @- "$@" "$API/repos/$REPO/$p"
}

NOTES="$(mktemp)"
awk -v want="###$VERSION" '$0 == want { inside = 1; next } inside && /^###/ { exit } inside { print }' "$PLG" > "$NOTES"

REL="$(api GET "releases/tags/$TAG" -w '\n%{http_code}')"
CODE="${REL##*$'\n'}"; REL="${REL%$'\n'*}"
if [ "$CODE" = 404 ]; then
  SHA="$(git rev-parse HEAD)"
  BODY="$(jq -n --arg t "$TAG" --arg s "$SHA" --rawfile n "$NOTES" '{tag_name:$t,target_commitish:$s,name:$t,body:$n,draft:false,prerelease:false}')"
  REL="$(api POST releases -H 'Content-Type: application/json' -d "$BODY")"
  echo "created release $TAG"
elif [ "$CODE" = 200 ]; then
  echo "release $TAG already exists; checking its assets"
else
  echo "::error::release lookup returned HTTP $CODE"; exit 1
fi
ID="$(jq -r '.id // empty' <<<"$REL")"
[ -n "$ID" ] || { echo "::error::no release id in the API response (token lacks write access?)"; exit 1; }

for f in "${ASSETS[@]}"; do
  base="$(basename "$f")"
  if jq -e --arg n "$base" '.assets[]? | select(.name==$n)' <<<"$REL" >/dev/null; then
    echo "asset $base already attached"
  else
    api POST "releases/$ID/assets?name=$base" -F "attachment=@$f" -o /dev/null -w "upload $base: HTTP %{http_code}\n"
  fi
done

# Readback: every asset must now be on the release with the bytes we built.
REL="$(api GET "releases/$ID")"
TMP="$(mktemp -d)"
for f in "${ASSETS[@]}"; do
  base="$(basename "$f")"
  url="$(jq -r --arg n "$base" '.assets[] | select(.name==$n) | .browser_download_url' <<<"$REL")"
  [ -n "$url" ] || { echo "::error::$base is not attached to release $TAG"; exit 1; }
  printf 'Authorization: token %s\n' "$GITEA_TOKEN" | curl -sSfL -H @- -o "$TMP/$base" "$url"
  [ "$(md5sum "$TMP/$base" | awk '{print $1}')" = "$(md5sum "$f" | awk '{print $1}')" ] \
    || { echo "::error::$base downloaded from the release differs from the build"; exit 1; }
done
echo "release $TAG published and verified: $(basename "$TXZ") md5 $BUILT"
