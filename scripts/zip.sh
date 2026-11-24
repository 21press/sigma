#!/usr/bin/env bash
#
# Build dist/sigma.zip: the plugin as it is installed, in a folder named sigma.
# The zip holds what is committed at HEAD, minus what .gitattributes marks export-ignore, plus the
# built apps in build/ (not committed): build/admin and build/widget. npm run package builds them
# first. No vendor/: the plugin has no runtime Composer dependencies.
#
# Usage: npm run package
#
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
if [[ -n "$(git status --porcelain)" ]]; then
	echo "Error: commit or stash your changes first; the zip is built from HEAD." >&2
	exit 1
fi
if [[ ! -f build/admin/admin.js ]]; then
	echo "Error: build/admin/admin.js is missing. Run npm run build." >&2
	exit 1
fi
VERSION="$(sed -n 's/^ \* Version: *//p' sigma.php | tr -d '[:space:]')"
if [[ "$(cut -d' ' -f1 build/version.txt 2>/dev/null)" != "$VERSION" ]]; then
	echo "Error: build/version.txt does not match version $VERSION. Run npm run build." >&2
	exit 1
fi
mkdir -p dist
rm -f dist/sigma.zip
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
git archive --format=tar --prefix=sigma/ HEAD | tar -x -C "$STAGE"
mkdir -p "$STAGE/sigma/build"
cp -R build/admin "$STAGE/sigma/build/admin"
cp build/version.txt "$STAGE/sigma/build/version.txt"
if [[ -f build/widget/widget.js ]]; then
	cp -R build/widget "$STAGE/sigma/build/widget"
	rm -f "$STAGE/sigma/build/widget/sizes.json"
	WIDGET="included"
else
	WIDGET="MISSING (apps/widget not built): the zip has no bubble"
fi
(cd "$STAGE" && zip -qrX "$OLDPWD/dist/sigma.zip" sigma)
echo "dist/sigma.zip"
echo "version: $(sed -n 's/^ \* Version: *//p' sigma.php | tr -d '[:space:]')"
echo "widget:  $WIDGET"
echo "sha256:  $(shasum -a 256 dist/sigma.zip | cut -d' ' -f1)"
