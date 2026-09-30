#!/bin/bash

# One PHP floor, stated once, repeated nowhere silently.
#
# Usage: ./scripts/check-php-floor.sh
#
# readme.txt's "Requires PHP" is the source: it is the line WordPress.org reads
# to decide which installs are offered the plugin, so it is the promise that
# actually binds. Every other place the version appears - the plugin header,
# the Composer constraint and platform pin, the wp-env PHP, the README badge
# and requirements line, the lowest cell of the CI test matrix - is a copy, and
# a copy that drifts is either a lie to users or a version the tests never run
# on. This check compares each copy with the source and names the ones that
# disagree.
#
# Locating a copy is part of the assertion. A renamed field or a reworded badge
# exits 2 rather than passing, because a check that silently stops looking at
# something is indistinguishable from one that found it correct.

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

FLOOR="$(grep -oP '^Requires PHP:\s*\K[0-9]+\.[0-9]+' "$REPO/readme.txt" || true)"
if [ -z "$FLOOR" ]; then
	echo "::error::readme.txt states no 'Requires PHP' version, so there is no floor for anything to agree with."
	exit 2
fi
echo "Floor (readme.txt, the version WordPress.org gates installs on): PHP $FLOOR"
echo

STATUS=0

# Read one copy and compare it with the floor. A copy that cannot be read at all
# is fatal: the pattern went stale and this check stopped watching that file.
copy() {
	local label="$1" file="$2" pattern="$3" found
	found="$(grep -oP "$pattern" "$REPO/$file" | head -1 || true)"
	if [ -z "$found" ]; then
		echo "::error::$label: no PHP version found in $file. The copy moved or was reworded, so this check is no longer watching it - fix the pattern in scripts/check-php-floor.sh."
		STATUS=2
		return
	fi
	if [ "$found" != "$FLOOR" ]; then
		echo "::error::$label: $file says PHP $found, readme.txt says PHP $FLOOR."
		[ "$STATUS" -eq 2 ] || STATUS=1
		return
	fi
	printf '  %-44s PHP %s\n' "$label" "$found"
}

copy "plugin header"            carve-markup.php  '^ \* Requires PHP:\s*\K[0-9]+\.[0-9]+'
copy "composer require"         composer.json     '"php":\s*">=\K[0-9]+\.[0-9]+'
copy "composer platform pin"    composer.json     '"php":\s*"\K[0-9]+\.[0-9]+(?=\.[0-9]+")'
copy "wp-env PHP"               .wp-env.json      '"phpVersion":\s*"\K[0-9]+\.[0-9]+'
copy "README badge"             README.md         '\[!\[PHP \K[0-9]+\.[0-9]+(?=\+\])'
copy "README requirements line" README.md         '^- PHP \K[0-9]+\.[0-9]+(?=\+,)'

# The CI test matrix: its lowest cell is a claim that the floor is exercised.
# A floor no job runs on is a version nobody has ever seen the plugin work on.
MATRIX_LOW="$(sed -n '/^  tests:/,/^  [a-z]/p' "$REPO/.github/workflows/ci.yml" \
	| grep -oP "^\s+- '\K[0-9]+\.[0-9]+" | sort -V | head -1 || true)"
if [ -z "$MATRIX_LOW" ]; then
	echo "::error::CI test matrix: no PHP versions found in the tests job of .github/workflows/ci.yml - fix the pattern in scripts/check-php-floor.sh."
	STATUS=2
elif [ "$MATRIX_LOW" != "$FLOOR" ]; then
	echo "::error::CI test matrix: its lowest cell is PHP $MATRIX_LOW, readme.txt promises PHP $FLOOR. The floor is either untested or lower than advertised."
	[ "$STATUS" -eq 2 ] || STATUS=1
else
	printf '  %-44s PHP %s\n' "CI test matrix, lowest cell" "$MATRIX_LOW"
fi

# rector.php aims BELOW the floor on purpose: WordPress.org's SVN pre-commit
# lint parses the tree on an older interpreter than the plugin supports. Above
# the floor would mean the downgrade cannot reach what that lint needs.
RECTOR_LEVEL="$(grep -oP 'DOWN_TO_PHP_\K[0-9]{2}' "$REPO/rector.php" || true)"
if [ -z "$RECTOR_LEVEL" ]; then
	echo "::error::rector.php declares no DOWN_TO_PHP_8x set - fix the pattern in scripts/check-php-floor.sh."
	STATUS=2
else
	TARGET="${RECTOR_LEVEL:0:1}.${RECTOR_LEVEL:1:1}"
	if [ "$(printf '%s\n%s\n' "$TARGET" "$FLOOR" | sort -V | head -1)" != "$TARGET" ]; then
		echo "::error::rector.php downgrades to PHP $TARGET, which is above the floor of PHP $FLOOR. The staged tree would still be too new for the lint the downgrade exists for."
		[ "$STATUS" -eq 2 ] || STATUS=1
	else
		printf '  %-44s PHP %s\n' "rector downgrade target (must be lower)" "$TARGET"
	fi
fi

echo
case "$STATUS" in
	0) echo "Every copy of the PHP floor agrees with readme.txt." ;;
	1) echo "::error::Copies of the PHP floor disagree with readme.txt. Change readme.txt and let every copy follow it." ;;
	*) echo "::error::A copy of the PHP floor could not be read, so this check cannot vouch for it." ;;
esac
exit "$STATUS"
