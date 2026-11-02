#!/bin/bash
# Build the package for <version> and require the .plg's &md5; to equal the built md5.
#
# Usage: scripts/check-md5.sh <version> [--report-only]
#
# Gitea is the primary forge and GitHub is a push mirror, so the release commit can no longer be
# written back by a GitHub workflow. The md5 is therefore committed *before* the tag (in the PR that
# bumps &version;), checked by Gitea CI on that PR, and checked again by the GitHub release workflow
# when the mirrored tag arrives. The build is deterministic (see build-plugin.sh), so both sides must agree.
#   --report-only  print the md5 of this build and exit 0 (used by the GitHub dry run to compare runners)
set -euo pipefail

VERSION="${1:?usage: check-md5.sh <version> [--report-only]}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="unraid-secretsman"
PLG="$ROOT/$NAME.plg"

bash "$ROOT/scripts/build-plugin.sh" "$VERSION" > /dev/null
BUILT="$(md5sum "$ROOT/dist/$NAME-$VERSION.txz" | awk '{print $1}')"
echo "built md5: $BUILT"
[ "${2:-}" = "--report-only" ] && exit 0

IN_PLG="$(sed -rn 's|^<!ENTITY md5[[:space:]]+"([^"]+)">.*|\1|p' "$PLG")"
echo ".plg md5:  $IN_PLG"
if [ "$BUILT" != "$IN_PLG" ]; then
  echo "::error::$NAME.plg &md5; is $IN_PLG but this source builds to $BUILT. Set &md5; to $BUILT in the PR that bumps the version."
  exit 1
fi
echo "md5 matches."
