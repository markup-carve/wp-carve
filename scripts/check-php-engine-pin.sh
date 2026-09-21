#!/bin/bash

# Check the released carve-php pin: composer.json names a release constraint,
# composer.lock resolves it to a tagged release, and that tag is a commit on
# carve-php main.
#
# Usage: ./scripts/check-php-engine-pin.sh <carve-php checkout with tags>

set -euo pipefail

engine_dir="${1:?usage: check-php-engine-pin.sh <carve-php checkout>}"
pkg='markup-carve/carve-php'

# Also fails when the locked version does not satisfy the constraint.
if ! composer validate --no-check-publish --no-check-all; then
    echo "::error::composer.json and composer.lock disagree - run composer update"
    exit 1
fi

constraint="$(php -r '$c=json_decode(file_get_contents("composer.json"), true); echo $c["require"][$argv[1]] ?? "";' "${pkg}")"
if [ -z "${constraint}" ]; then
    echo "::error::${pkg} is not required by this repo - this check is watching nothing"
    exit 1
fi
case "${constraint}" in
    *dev* | *'#'* | *' as '* | *'@'*)
        echo "::error::${pkg} is \"${constraint}\" - it must be a release constraint such as ^0.1.9, not a branch, alias or commit"
        exit 1
        ;;
esac

lock_field() {
    php -r '$l=json_decode(file_get_contents("composer.lock"), true); foreach ($l["packages"] as $p) if ($p["name"] === $argv[1]) { echo $argv[2] === "reference" ? ($p["source"]["reference"] ?? "") : ($p["version"] ?? ""); break; }' "${pkg}" "$1"
}
version="$(lock_field version)"
reference="$(lock_field reference)"
if ! printf '%s' "${version}" | grep -qE '^v?[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "::error::composer.lock resolves ${pkg} to \"${version}\", which is not a tagged release"
    exit 1
fi

tagged="$(git -C "${engine_dir}" rev-parse -q --verify "refs/tags/${version}^{commit}" || true)"
if [ -z "${tagged}" ]; then
    echo "::error::composer.lock resolves ${pkg} to ${version}, but markup-carve/carve-php has no such tag"
    exit 1
fi
if [ "${tagged}" != "${reference}" ]; then
    echo "::error::composer.lock records ${pkg} ${version} at ${reference}, but the ${version} tag is ${tagged}"
    exit 1
fi
if ! git -C "${engine_dir}" merge-base --is-ancestor "${tagged}" origin/main; then
    echo "::error::${pkg} ${version} (${tagged}) is not on markup-carve/carve-php main"
    exit 1
fi

latest="$(git -C "${engine_dir}" tag --list '[0-9]*.[0-9]*.[0-9]*' 'v[0-9]*.[0-9]*.[0-9]*' --sort=-version:refname | sed -n 1p)"
echo "${pkg}: constraint ${constraint}, lock ${version} (${reference}), latest tag ${latest}"
if [ "${version}" != "${latest}" ]; then
    echo "::warning::${pkg} ${version} is behind the latest release ${latest}; the synchronization workflow will open an update PR"
fi
