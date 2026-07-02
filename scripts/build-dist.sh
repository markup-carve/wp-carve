#!/bin/bash

# Stage the plugin exactly as it ships to WordPress.org: apply .distignore, and
# install runtime-only (--no-dev) Composer dependencies. Used for local Plugin
# Check runs and mirrored by the deploy workflow.
#
# Usage: ./scripts/build-dist.sh [dest-dir]   (default: ../dist/carve-markup)

set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Default inside the repo (build/ is gitignored + distignored) so the relative
# path in .wp-env.plugin-check.json resolves the same locally and in CI.
DEST="${1:-$SRC/build/dist/carve-markup}"

echo "Staging distribution copy -> $DEST"
# Don't remove $DEST itself (a live wp-env bind mount points at its inode);
# rsync --delete below prunes stale contents instead.
mkdir -p "$DEST"

# Build an rsync exclude list from .distignore (skip comments and blank lines).
EXCLUDES="$(mktemp)"
grep -vE '^\s*(#|$)' "$SRC/.distignore" > "$EXCLUDES"

# NOTE: anchor /vendor so only the root Composer dir is skipped - a bare
# 'vendor' would also drop assets/js/vendor (the shipped JS bundles).
rsync -a --delete \
	--exclude='.git' \
	--exclude='/vendor' \
	--exclude='node_modules' \
	--exclude-from="$EXCLUDES" \
	"$SRC/" "$DEST/"
rm -f "$EXCLUDES"

# Runtime-only dependencies, resolved from the committed lockfile.
cp "$SRC/composer.json" "$DEST/"
[ -f "$SRC/composer.lock" ] && cp "$SRC/composer.lock" "$DEST/"
( cd "$DEST" && composer install --no-dev --prefer-dist --no-progress --no-scripts --optimize-autoloader )
rm -f "$DEST/composer.lock"

# Prune dev cruft from the freshly-installed vendor tree (mirrors the vendor
# rules in .distignore, which only the SVN deploy applies otherwise).
find "$DEST/vendor" -type d \( -name tests -o -name docs -o -name .github -o -name bin -o -name fuzz \) -prune -exec rm -rf {} + 2>/dev/null || true
find "$DEST/vendor" -type f \( -name '*.md' -o -name '*.xml' -o -name '*.neon' -o -name '*.dist' -o -name 'phpunit.xml*' -o -name 'package.json' -o -name 'package-lock.json' \) -delete 2>/dev/null || true

echo "Done. Staged plugin at: $DEST"
