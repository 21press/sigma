#!/usr/bin/env bash
#
# Build a release: dist/sigma-{version}.zip and dist/sigma-{version}.zip.sha256, the two assets the
# GitHub updater reads (§19.2). It refuses unless the tree is clean and every version matches: the
# plugin header, P21_SIGMA_VERSION, readme.txt's Stable tag, package.json, the top CHANGELOG entry
# and, when given, the tag (which must point at HEAD). The build must not change tracked files (the
# translation template and the widget's string table are checked). It does not tag, push or publish anything.
#
# Usage: npm run release [-- v0.2.5]
#
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
fail() { echo "release: $*" >&2; exit 1; }

[[ -z "$(git status --porcelain)" ]] || fail "the working tree is not clean; commit or stash first."

HEADER="$(sed -n 's/^ \* Version: *//p' sigma.php | tr -d '[:space:]')"
CONSTANT="$(sed -n "s/^define( 'P21_SIGMA_VERSION', '\([^']*\)' );/\1/p" sigma.php)"
STABLE="$(sed -n 's/^Stable tag: *//p' readme.txt | tr -d '[:space:]')"
PACKAGE="$(node -p "require('./package.json').version")"
CHANGELOG="$(sed -n 's/^## \([0-9][0-9.]*\).*/\1/p' CHANGELOG.md | head -1)"
VERSION="$HEADER"

[[ "$VERSION" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]] || fail "the header version '$VERSION' is not x.y.z."
for pair in "P21_SIGMA_VERSION:$CONSTANT" "readme.txt Stable tag:$STABLE" "package.json:$PACKAGE" "CHANGELOG.md top entry:$CHANGELOG"; do
	[[ "${pair##*:}" == "$VERSION" ]] || fail "${pair%:*} is '${pair##*:}', the header says '$VERSION'."
done
if grep -q "^## $VERSION .*not released" CHANGELOG.md; then
	fail "the CHANGELOG entry for $VERSION still says it is not released."
fi
if [[ $# -gt 0 ]]; then
	TAG="$1"
	[[ "$TAG" == "v$VERSION" ]] || fail "the tag '$TAG' does not match the version (expected v$VERSION)."
	git rev-parse -q --verify "refs/tags/$TAG" >/dev/null || fail "the tag $TAG does not exist (create it on this commit first)."
	[[ "$(git rev-parse "$TAG^{commit}")" == "$(git rev-parse HEAD)" ]] || fail "the tag $TAG does not point at HEAD."
fi

npm run -s build
# Translations: the widget's string table must match its t() calls, and the committed pot must be current.
if [[ -f scripts/widget-strings.mjs ]]; then
	node scripts/widget-strings.mjs --check || fail "includes/class-widget-strings.php is stale; run npm run build and commit it."
fi
# npm run build regenerates languages/sigma.pot when WP-CLI is installed; the clean-tree check below
# then catches a stale one.
if [[ -f scripts/make-pot.mjs ]] && ! command -v wp >/dev/null 2>&1; then
	echo "release: WP-CLI (wp) is not installed, so languages/sigma.pot was not checked." >&2
fi
[[ -z "$(git status --porcelain)" ]] || fail "the build changed tracked files (a stale pot or string table?); commit them first:
$(git status --porcelain)"
bash scripts/zip.sh >/dev/null
ZIP="dist/sigma-$VERSION.zip"
mv -f dist/sigma.zip "$ZIP"
(cd dist && shasum -a 256 "sigma-$VERSION.zip" > "sigma-$VERSION.zip.sha256")
echo "$ZIP"
echo "$ZIP.sha256: $(cut -d' ' -f1 "$ZIP.sha256")"
echo "Upload both to the GitHub release for v$VERSION."
