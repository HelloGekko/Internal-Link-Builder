#!/usr/bin/env bash
#
# Builds a distributable plugin zip in dist/, containing only the runtime
# files (dev files are excluded via .gitattributes export-ignore).
#
# Usage: bin/build-zip.sh
#
set -euo pipefail

SLUG="internal-link-builder"
VERSION="$(grep -oE "Version:[[:space:]]*[0-9.]+" "${SLUG}.php" | grep -oE "[0-9.]+")"

if [ -z "${VERSION}" ]; then
	echo "Could not read the version from ${SLUG}.php" >&2
	exit 1
fi

mkdir -p dist
OUT="dist/${SLUG}-${VERSION}.zip"

# git archive respects .gitattributes export-ignore and never includes
# untracked/ignored files such as vendor/.
git archive --format=zip --prefix="${SLUG}/" -o "${OUT}" HEAD

echo "Built ${OUT}"
echo "Upload it to your update host and point the manifest download_url at it."
