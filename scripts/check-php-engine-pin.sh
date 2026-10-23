#!/bin/bash

# Check the carve-php pin. Release constraints must resolve to a tag on main;
# a development constraint must resolve to a main commit in composer.lock.
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
lock_field() {
    php -r '$l=json_decode(file_get_contents("composer.lock"), true); foreach ($l["packages"] as $p) if ($p["name"] === $argv[1]) { echo $argv[2] === "reference" ? ($p["source"]["reference"] ?? "") : ($p["version"] ?? ""); break; }' "${pkg}" "$1"
}
version="$(lock_field version)"
reference="$(lock_field reference)"
if [ "${constraint}" = 'dev-main as 0.1.x-dev' ]; then
    if [ "${version}" != 'dev-main' ] || ! [[ "${reference}" =~ ^[0-9a-f]{40}$ ]]; then
        echo "::error::${pkg} ${constraint} resolves to ${version} (${reference}), expected a dev-main commit"
        exit 1
    fi
    if ! git -C "${engine_dir}" cat-file -e "${reference}^{commit}" 2>/dev/null \
        || ! git -C "${engine_dir}" merge-base --is-ancestor "${reference}" origin/main; then
        echo "::error::${pkg} ${reference} is not a commit on carve-php main"
        exit 1
    fi
    engine_spec="$(git -C "${engine_dir}" ls-tree "${reference}" tests/spec | awk '{print $3}')"
    corpus_spec="$(sed -n '/name: Check out the corpus at the spec freeze/,/path: carve-freeze/s/^[[:space:]]*ref: \([0-9a-f]\{40\}\)[[:space:]]*$/\1/p' .github/workflows/ci.yml)"
    if [ -z "${engine_spec}" ] || [ "${engine_spec}" != "${corpus_spec}" ]; then
        echo "::error::CI corpus ${corpus_spec} does not match ${pkg} tests/spec ${engine_spec}"
        exit 1
    fi
    behind="$(git -C "${engine_dir}" rev-list --count "${reference}..origin/main")"
    echo "${pkg}: locked dev-main ${reference}; main is ${behind} commit(s) ahead"
    if [ "${behind}" -gt 0 ]; then
        echo "::warning::${pkg} is ${behind} commit(s) behind carve-php main"
    fi
    exit 0
fi

case "${constraint}" in
    *dev* | *'#'* | *' as '* | *'@'*)
        echo "::error::${pkg} is \"${constraint}\" - use a release constraint or the supported dev-main alias"
        exit 1
        ;;
esac
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
