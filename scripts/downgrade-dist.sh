#!/bin/bash

# Downgrade the STAGED distribution tree so it parses on older PHP than this
# repository develops against. WordPress.org's SVN pre-commit lint runs on an
# older interpreter than the plugin's own floor, so the bundled dependencies
# have to lose their 8.1/8.2-only syntax before the tree is committed there.
#
# Usage: ./scripts/downgrade-dist.sh [dist-dir]   (default: build/dist/carve-markup)
#
# Run scripts/build-dist.sh first. Run scripts/lint-dist-floor.sh after: this
# script only performs the rewrite, it does not check that the rewrite worked.
#
# This lived inline in .github/workflows/deploy.yml until it was extracted here.
# That workflow runs only on `release: published`, so nothing exercised the step
# between releases, and a rector invocation that fatalled on every run went
# unnoticed across three published versions because the step ended in `|| true`.

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="${1:-$REPO/build/dist/carve-markup}"

if [ ! -d "$DIST" ]; then
	echo "::error::Staged distribution not found at $DIST. Run scripts/build-dist.sh first."
	exit 2
fi

echo "Downgrading staged tree -> $DIST"

# Rector runs from the REPO ROOT against the staged tree.
#
# The config anchors its paths to its own location via __DIR__, so the copy
# placed inside the staged dir targets the staged vendor. The working directory
# must stay the repo root: rector's bin loads `getcwd()/vendor/autoload.php`
# unless it can see `vendor/rector/rector` under the working directory, and the
# staged tree is installed --no-dev and therefore never contains rector. Running
# it with the staged dir as the working directory made it load a second Composer
# autoloader whose generated class name collides with the one the rector binary
# had already loaded, and PHP fatalled before a single file was read.
cp "$REPO/rector.php" "$REPO/rector-bootstrap.php" "$DIST/"
php -d auto_prepend_file="$REPO/rector-bootstrap.php" \
	"$REPO/vendor/bin/rector" process --config "$DIST/rector.php" --no-diffs
rm -f "$DIST/rector.php" "$DIST/rector-bootstrap.php"

# Trait constants are a PHP 8.2 feature rector does not rewrite: move them to a
# plain class and repoint the `self::` references.
TRAIT_FILE="$DIST/vendor/torchlight/engine/src/Generators/Concerns/ProcessesFileLanguage.php"
if [ -f "$TRAIT_FILE" ]; then
	DIST="$DIST" php << 'PHPSCRIPT'
<?php
$dist = getenv('DIST');
$file = $dist . '/vendor/torchlight/engine/src/Generators/Concerns/ProcessesFileLanguage.php';
$content = file_get_contents($file);

if (preg_match_all('/^\s*const\s+(\w+)\s*=\s*([^;]+);/m', $content, $matches, PREG_SET_ORDER)) {
    $helperClass = "<?php\n\nnamespace Torchlight\\Engine\\Generators\\Concerns;\n\nclass ProcessesFileLanguageConstants\n{\n";
    foreach ($matches as $match) {
        $helperClass .= "    public const {$match[1]} = {$match[2]};\n";
    }
    $helperClass .= "}\n";
    file_put_contents($dist . '/vendor/torchlight/engine/src/Generators/Concerns/ProcessesFileLanguageConstants.php', $helperClass);

    $content = preg_replace('/^\s*const\s+\w+\s*=\s*[^;]+;\s*\/\/[^\n]*\n?/m', '', $content);
    $content = preg_replace('/^\s*const\s+\w+\s*=\s*[^;]+;\s*\n?/m', '', $content);
    foreach ($matches as $match) {
        $content = str_replace("self::{$match[1]}", "ProcessesFileLanguageConstants::{$match[1]}", $content);
    }
    file_put_contents($file, $content);
    printf("trait constants: moved %d out of ProcessesFileLanguage.php\n", count($matches));
} else {
    echo "trait constants: ProcessesFileLanguage.php holds none, nothing to move\n";
}
PHPSCRIPT
else
	# Not an error: torchlight/engine v1.0.0 no longer ships this trait. Said out
	# loud so the patch cannot quietly become a no-op the way the rector call did.
	echo "trait constants: no ProcessesFileLanguage.php in the staged tree, patch not applicable"
fi

# phiki reads enum cases in constant expressions, which the older interpreter
# behind WordPress.org's SVN lint rejects. Substitute the literal strings.
PHIKI_FILE="$DIST/vendor/phiki/phiki/src/Adapters/CommonMark/Transformers/AnnotationsTransformer.php"
if [ -f "$PHIKI_FILE" ]; then
	DIST="$DIST" php << 'PHPSCRIPT'
<?php
$dist = getenv('DIST');
$file = $dist . '/vendor/phiki/phiki/src/Adapters/CommonMark/Transformers/AnnotationsTransformer.php';
$grammarFile = $dist . '/vendor/phiki/phiki/src/Grammar/Grammar.php';
$grammar = file_get_contents($grammarFile);
preg_match_all('/case\s+(\w+)\s*=\s*\'([^\']+)\'/', $grammar, $m, PREG_SET_ORDER);
$map = [];
foreach ($m as $match) {
    $map["Grammar::{$match[1]}->value"] = "'{$match[2]}'";
}
$content = file_get_contents($file);
$patched = strtr($content, $map);
file_put_contents($file, $patched);
printf(
    "phiki AnnotationsTransformer: %d enum constant expressions replaced\n",
    preg_match_all('/Grammar::\w+->value/', $content),
);
PHPSCRIPT
else
	echo "phiki AnnotationsTransformer: not in the staged tree, patch not applicable"
fi

# The trait patch can add a class file: regenerate the optimized classmap.
composer dump-autoload --working-dir="$DIST" --no-dev --optimize

echo "Done. Downgraded staged plugin at: $DIST"
