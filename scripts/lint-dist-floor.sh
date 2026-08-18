#!/bin/bash

# Parse the STAGED, DOWNGRADED distribution tree with the older PHP interpreters
# the shipped artifact promises to run on.
#
# Usage: ./scripts/lint-dist-floor.sh [dist-dir]   (default: build/dist/carve-markup)
#
# Why this exists, and why it does not reuse the deploy workflow's `php -l`:
# that lint runs on the job's own PHP, which is the version this repository
# develops against, and it runs BEFORE the downgrade. An un-downgraded tree is
# perfectly valid syntax for that interpreter, so the check passes identically
# whether scripts/downgrade-dist.sh did its job, did half of it, or fatalled and
# did nothing at all. Only an older parser can tell those apart. Measured on the
# tree as it stands: at the repository's own PHP floor a downgraded tree and an
# untouched one both report zero parse errors; at the downgrade target the
# untouched tree reports 67 and the downgraded one 33.
#
# Two assertions, both against real interpreters in Docker rather than a
# reimplementation of PHP's parser:
#
#   1. DECLARED FLOOR - every staged file must parse on the version readme.txt
#      advertises under "Requires PHP". No tolerance. A file that does not parse
#      here cannot run on a supported install, so there is nothing to ratchet.
#
#   2. DOWNGRADE TARGET - the version rector.php's DOWN_TO_PHP_8x set aims at,
#      with a ratcheting ceiling rather than a hard zero. The bundled
#      dependencies contain enums rector does not rewrite, so the honest number
#      is not zero and pretending otherwise would put this permanently red for
#      something no change here can fix, which is how a check gets ignored. What
#      it refuses is the number getting WORSE, which is exactly what a skipped,
#      broken or partially applied downgrade looks like.
#
# Both versions are read out of the files that make the promise, so neither can
# drift away from the claim it is checking.

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="${1:-$REPO/build/dist/carve-markup}"

# Files still failing to parse at the downgrade target. Lower this whenever the
# real number drops; it is a ceiling on known-remaining work, not a target.
# 37 as of torchlight/engine v1.0.0 + phiki v2.2.0 + carve-php 0.1.5: rector's
# downgrade sets leave native enums, readonly classes and readonly promoted
# properties in place. It was 33 against carve-php 0.1.4; that release added
# four files in constructs the DOWN_TO_PHP_80 set does not rewrite - two
# `readonly class` (HtmlImportDiagnostic, HtmlImportResult), one enum
# (BlockQuoteLazyMode) and one readonly promoted property
# (SentinelSpaceExhaustedException). The downgrade itself still runs: the same
# staged tree reports 71 before it and 37 after.
DOWNGRADE_TARGET_CEILING="${DOWNGRADE_TARGET_CEILING:-37}"

if [ ! -d "$DIST" ]; then
	echo "::error::Staged distribution not found at $DIST. Run scripts/build-dist.sh first."
	exit 2
fi

if ! command -v docker > /dev/null 2>&1; then
	# Deliberately not a skip. A check that quietly opts out on the machine that
	# matters is the failure mode this script was written to remove.
	echo "::error::docker is required to parse the staged tree with older PHP interpreters."
	exit 2
fi

# --- the versions, read from the files that promise them ----------------------

DECLARED_FLOOR="$(grep -oP '^Requires PHP:\s*\K[0-9]+\.[0-9]+' "$REPO/readme.txt" || true)"
if [ -z "$DECLARED_FLOOR" ]; then
	echo "::error::readme.txt does not state a 'Requires PHP' version, so there is no floor to lint against."
	exit 2
fi

# DowngradeLevelSetList::DOWN_TO_PHP_80 -> 8.0
RECTOR_LEVEL="$(grep -oP 'DOWN_TO_PHP_\K[0-9]{2}' "$REPO/rector.php" || true)"
if [ -z "$RECTOR_LEVEL" ]; then
	echo "::error::rector.php declares no DOWN_TO_PHP_8x set, so there is no downgrade target to lint against."
	exit 2
fi
DOWNGRADE_TARGET="${RECTOR_LEVEL:0:1}.${RECTOR_LEVEL:1:1}"

echo "Declared floor (readme.txt):   PHP $DECLARED_FLOOR"
echo "Downgrade target (rector.php): PHP $DOWNGRADE_TARGET"
echo

# --- population guard ---------------------------------------------------------
#
# A count of parse failures is only worth reading if the thing counted is the
# whole plugin. A staged tree that lost most of its files reports few failures
# and would sail past a ceiling, so the population is checked against an
# independent statement of what it should be: the repository's own src/, which
# ships verbatim and is not produced by the staging step being checked.

REPO_SRC_FILES="$(find "$REPO/src" -name '*.php' | wc -l)"
DIST_SRC_FILES="$(find "$DIST/src" -name '*.php' 2>/dev/null | wc -l)"
if [ "$REPO_SRC_FILES" -eq 0 ]; then
	echo "::error::The repository's own src/ holds no PHP files, so it cannot vouch for the staged tree."
	exit 2
fi
if [ "$DIST_SRC_FILES" -ne "$REPO_SRC_FILES" ]; then
	echo "::error::Staged src/ holds $DIST_SRC_FILES PHP files, the repository's src/ holds $REPO_SRC_FILES. The staged tree is not the plugin."
	exit 1
fi

# Every package rector.php claims to rewrite has to actually be in the tree,
# otherwise the downgrade target was measured over something else.
MISSING=0
while read -r PKG; do
	[ -z "$PKG" ] && continue
	if [ ! -d "$DIST/$PKG" ] || [ "$(find "$DIST/$PKG" -name '*.php' | wc -l)" -eq 0 ]; then
		echo "::error::rector.php targets $PKG but the staged tree has no PHP files there."
		MISSING=1
	fi
done < <(grep -oP "__DIR__ \. '/\Kvendor/[^']+" "$REPO/rector.php")
if [ "$MISSING" -ne 0 ]; then
	exit 1
fi

TOTAL="$(find "$DIST" -name '*.php' | wc -l)"
echo "Population: $TOTAL PHP files staged, src/ matches the repository at $REPO_SRC_FILES."
echo

# --- lint ---------------------------------------------------------------------

# Parse every staged file with a real interpreter of $1, print the failing paths,
# and return the failure count on stdout's last line.
lint_at() {
	local version="$1"
	docker run --rm -v "$DIST:/app:ro" -w /app "php:${version}-cli" sh -c '
		failed=0
		for f in $(find . -name "*.php"); do
			if ! php -l "$f" > /dev/null 2>&1; then
				failed=$((failed + 1))
				echo "  parse error: $f"
			fi
		done
		echo "FAILED=$failed"
	'
}

echo "Parsing the staged tree on PHP $DECLARED_FLOOR (declared floor, no tolerance)..."
FLOOR_OUT="$(lint_at "$DECLARED_FLOOR")"
FLOOR_FAILED="${FLOOR_OUT##*FAILED=}"
echo "$FLOOR_OUT" | grep -v '^FAILED=' || true
echo "  $FLOOR_FAILED of $TOTAL files fail to parse on PHP $DECLARED_FLOOR"
echo

echo "Parsing the staged tree on PHP $DOWNGRADE_TARGET (downgrade target, ceiling $DOWNGRADE_TARGET_CEILING)..."
TARGET_OUT="$(lint_at "$DOWNGRADE_TARGET")"
TARGET_FAILED="${TARGET_OUT##*FAILED=}"
echo "$TARGET_OUT" | grep -v '^FAILED=' || true
echo "  $TARGET_FAILED of $TOTAL files fail to parse on PHP $DOWNGRADE_TARGET"
echo

STATUS=0

if [ "$FLOOR_FAILED" -ne 0 ]; then
	echo "::error::$FLOOR_FAILED staged files do not parse on PHP $DECLARED_FLOOR, the version readme.txt tells users to run. The distribution is not shippable."
	STATUS=1
fi

if [ "$TARGET_FAILED" -gt "$DOWNGRADE_TARGET_CEILING" ]; then
	echo "::error::$TARGET_FAILED staged files do not parse on PHP $DOWNGRADE_TARGET, over the ceiling of $DOWNGRADE_TARGET_CEILING. The downgrade did not run, or ran over less of the tree than it used to."
	STATUS=1
elif [ "$TARGET_FAILED" -lt "$DOWNGRADE_TARGET_CEILING" ]; then
	echo "::notice::Only $TARGET_FAILED staged files fail to parse on PHP $DOWNGRADE_TARGET. Lower DOWNGRADE_TARGET_CEILING in scripts/lint-dist-floor.sh to $TARGET_FAILED so the gate keeps ratcheting."
fi

if [ "$STATUS" -eq 0 ]; then
	echo "Staged distribution parses at the declared floor and is within the downgrade ceiling."
fi

exit "$STATUS"
