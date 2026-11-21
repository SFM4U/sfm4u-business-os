#!/bin/bash
# Publish the current dist/ Business OS ZIP as the plugin tree on branch `test-site-build` (TEST SITE ONLY: thenoakhali.com).
# Hostinger then pulls it with hosting_git_deploy into wp-content/plugins/sfm4u-wholesale-test. Never used for live sites.
# Usage: tools/release/build.sh && tools/release/test-site-branch.sh
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
ZIP=$(ls "$REPO"/dist/SFM4U-BusinessOS-sfm4u-wholesale-*.zip | head -1)
SHA=$(sha256sum "$ZIP" | cut -d' ' -f1); SRC=$(git -C "$REPO" rev-parse --short HEAD)
T=$(mktemp -d); trap 'git -C "$REPO" worktree remove --force "$T/wt" 2>/dev/null || true; rm -rf "$T"' EXIT
unzip -q "$ZIP" -d "$T/x"
git -C "$REPO" fetch -q origin test-site-build
git -C "$REPO" worktree add -q "$T/wt" FETCH_HEAD
cd "$T/wt"; git rm -rqf . ; cp -r "$T/x/sfm4u-wholesale/." .
printf 'TEST SITE BUILD ONLY (thenoakhali.com). Plugin tree of %s from main %s, ZIP SHA-256 %s.\n' "$(basename "$ZIP")" "$SRC" "$SHA" > BUILD-INFO.txt
git add -A; git commit -qm "Test-site build from main $SRC (ZIP ${SHA:0:12})"
for i in 1 2 3 4; do git push -q origin HEAD:test-site-build && break; sleep $((i*3)); done
echo "test-site-build <- main $SRC, ZIP $SHA"
