#!/bin/bash
# Release gates for <version>, run by Gitea CI on an untagged version (so a bad release is caught in the PR) and again by
# the Gitea release workflow at the tag. Needs a full clone (tags) and python3.
#
#   1. unraid-secretsman.plg has a ###<version> CHANGES entry.
#   2. Rule 7 (CLAUDE.md): if the patch layer changed since the previous tag, that CHANGES entry must record the
#      RECOVERY.md drill ("RECOVERY drill: <result>"). The drill needs a real host and cannot run on a runner; what
#      CAN run is the refusal to publish until the entry says it was done. The patch layer live-patches a stock
#      Unraid file (dockerMan's Helpers.php) on every boot, so an unrehearsed change can leave a host unable to create a container.
#   3. The version sorts after the previous tag the way Unraid compares it (plain strcmp, not semver).
set -euo pipefail

VERSION="${1:?usage: release-gates.sh <version>}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLG="$ROOT/unraid-secretsman.plg"
cd "$ROOT"

grep -qx "###$VERSION" "$PLG" || {
  echo "::error::unraid-secretsman.plg has no '###$VERSION' entry in <CHANGES>. Write the changelog entry first."
  exit 1
}

PREV_TAG="$(git tag --list 'v*' | grep -vx "v$VERSION" | sort -V | tail -n1 || true)"
if [ -z "$PREV_TAG" ]; then
  echo "No previous tag - Rule 7 and ordering gates not applicable."
  exit 0
fi

PATCH_FILES=(src/patch.php plugin/scripts/apply_patch.php plugin/scripts/uninstall.php)
if git diff --quiet "$PREV_TAG" HEAD -- "${PATCH_FILES[@]}"; then
  echo "Patch layer unchanged since $PREV_TAG - Rule 7 gate not applicable."
else
  echo "Patch layer changed since $PREV_TAG:"
  git diff --stat "$PREV_TAG" HEAD -- "${PATCH_FILES[@]}"
  awk -v want="###$VERSION" '
    $0 == want { inside = 1; next }
    inside && /^###/ { exit }
    inside { print }
  ' "$PLG" | grep -q 'RECOVERY drill:' || {
    echo "::error::The patch layer changed since $PREV_TAG, so CLAUDE.md rule 7 applies: re-run the RECOVERY.md drill and record the outcome in the ###$VERSION CHANGES entry as 'RECOVERY drill: <result>'. Refusing to release."
    exit 1
  }
  echo "CHANGES entry records the RECOVERY drill - gate satisfied."
fi

python3 - "${PREV_TAG#v}" "$VERSION" <<'PY'
import sys
prev, new = sys.argv[1], sys.argv[2]
if not new > prev:
    sys.exit(
        f"::error::Unraid compares plugin versions with strcmp, and {new!r} does not sort after the installed "
        f"{prev!r}. The plugin manager would refuse it as an older version. Pick a version that sorts higher as a "
        f"plain string (zero-pad rather than letting a component reach double digits)."
    )
print(f"{new} sorts after {prev} - installable.")
PY
