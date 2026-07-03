#!/bin/bash

# Version update script for Carve Markup (wpcarve).
# Keeps the plugin header, constant, readme stable tag and asset versions in
# sync so the release deploy workflow's consistency check passes.
#
# Usage: ./scripts/version.sh 0.1.0

set -e

if [ -z "$1" ]; then
    echo "Usage: $0 <version>"
    echo "Example: $0 0.1.0"
    exit 1
fi

VERSION="$1"

# Validate version format (semver)
if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Error: Version must be in semver format (e.g., 0.1.0)"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"

echo "Updating version to $VERSION in all files..."

# carve-markup.php - Plugin header Version
sed -i "s/^\( \* Version:\s*\)[0-9]\+\.[0-9]\+\.[0-9]\+/\1$VERSION/" "$PLUGIN_DIR/carve-markup.php"

# carve-markup.php - WPCARVE_VERSION constant
sed -i "s/define('WPCARVE_VERSION', '[0-9]\+\.[0-9]\+\.[0-9]\+')/define('WPCARVE_VERSION', '$VERSION')/" "$PLUGIN_DIR/carve-markup.php"

# readme.txt - Stable tag
sed -i "s/^Stable tag: [0-9]\+\.[0-9]\+\.[0-9]\+/Stable tag: $VERSION/" "$PLUGIN_DIR/readme.txt"

# package.json - version
sed -i "s/\"version\": \"[0-9]\+\.[0-9]\+\.[0-9]\+\"/\"version\": \"$VERSION\"/" "$PLUGIN_DIR/package.json"

# assets/blocks/*/index.asset.php - version (hand-maintained no-build assets)
for asset in "$PLUGIN_DIR"/assets/blocks/*/index.asset.php; do
    [ -f "$asset" ] || continue
    sed -i "s/'version' => '[0-9]\+\.[0-9]\+\.[0-9]\+'/'version' => '$VERSION'/" "$asset"
done

echo "Done! Updated version to $VERSION in:"
echo "  - carve-markup.php (header and WPCARVE_VERSION constant)"
echo "  - readme.txt (stable tag)"
echo "  - package.json"
echo "  - assets/blocks/*/index.asset.php"
echo ""
echo "Don't forget to update CHANGELOG.md and readme.txt changelog!"
