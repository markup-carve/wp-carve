<?php

/**
 * Render every document of the spec corpus through the engine this plugin
 * SHIPS, and count the ones that come out differently.
 *
 * Nothing in this repository did this. tests/ asserts hand-written
 * expectations, which a stale engine satisfies exactly as well as a current
 * one, and engine-drift.yml checks that the pins are well formed, reachable and
 * honestly labelled - all of which a pin that renders 192 documents wrongly
 * passes without complaint. A version distance cannot answer it either: 200
 * commits can change nothing and one can change a construct. This counts
 * DOCUMENTS.
 *
 * The exposure here reaches a READER, not a test. carve-php renders post and
 * comment content on the front end, so what this measures is what a WordPress
 * site publishes.
 *
 *   php scripts/corpus-through-engine.php <corpus-dir> [--list]
 *
 * Prints key=value lines for the workflow to read.
 */

declare(strict_types=1);

$argvRest = array_slice($argv, 1);
$list = in_array('--list', $argvRest, true);
$positional = array_values(array_filter($argvRest, static fn (string $a): bool => !str_starts_with($a, '--')));

if (count($positional) < 1) {
    fwrite(STDERR, "usage: corpus-through-engine.php <corpus-dir> [--list]\n");
    exit(2);
}

$corpusDir = rtrim($positional[0], '/');
if (!is_dir($corpusDir)) {
    fwrite(STDERR, "::error::{$corpusDir} is not a directory\n");
    exit(2);
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "::error::no vendor/autoload.php - run composer install before measuring what the engine renders\n");
    exit(2);
}
require $autoload;

/**
 * How many documents the corpus is SUPPOSED to hold, derived from something
 * this script does not itself read as the population.
 *
 * Without it an absent or truncated corpus renders zero documents, reports zero
 * divergences and passes - the exact shape of check this file exists to be
 * rather than to become. Counting the corpus directory to decide how big the
 * corpus should be would move both sides of the comparison together and guard
 * nothing, and a hardcoded 1239 is the same defect with a bigger number, going
 * stale the day an example lands upstream.
 *
 * So the reference is the corpus's SOURCE: tests/corpus is generated from the
 * `::: compare` blocks in resources/examples/{core,extensions,edge-cases}.md,
 * one block per pair, and the generator refuses to write a corpus where the two
 * disagree. Both live in the same checkout, so this costs no second clone.
 *
 * This is the approach the sibling repositories arrived at, ported rather than
 * reinvented.
 */
function declaredCorpusSize(string $corpusDir): int
{
    $examplesDir = $corpusDir . '/../../resources/examples';
    $declared = 0;

    foreach (['core.md', 'extensions.md', 'edge-cases.md'] as $page) {
        $path = $examplesDir . '/' . $page;
        if (!file_exists($path)) {
            // Not a soft skip. Without this page there is no independent
            // statement of how big the corpus should be, and a corpus check
            // with nothing to compare against is the failure shape being
            // removed here.
            fwrite(STDERR, "::error::no corpus source page at {$path}; tests/corpus is generated from these pages, so if the spec moved them this guard has to move with them\n");
            exit(1);
        }

        // Mirrors the generator's state machine rather than grepping: a
        // `::: compare` line inside an already-open block is content, not a
        // second pair, and a block closes on a bare marker line.
        $marker = null;
        foreach (explode("\n", (string)file_get_contents($path)) as $rawLine) {
            $line = trim($rawLine);
            if ($marker !== null) {
                if ($line === $marker) {
                    $marker = null;
                }

                continue;
            }
            if (preg_match('/^(:{3,})\s+compare(\s+\S.*)?$/', $line, $m) === 1) {
                $declared++;
                $marker = $m[1];
            }
        }
    }

    if ($declared === 0) {
        fwrite(STDERR, "::error::the corpus source pages under {$examplesDir} declare no ::: compare blocks at all; that is a wiring problem, not a corpus of size zero\n");
        exit(1);
    }

    return $declared;
}

$sources = glob($corpusDir . '/*.crv') ?: [];
sort($sources);

/** @var array<int, string> $pairs */
$pairs = [];
foreach ($sources as $source) {
    $expected = substr($source, 0, -4) . '.html';
    if (file_exists($expected)) {
        $pairs[] = $source;
    }
}

$declared = declaredCorpusSize($corpusDir);
if (count($pairs) !== $declared) {
    fwrite(STDERR, sprintf(
        "::error::%d corpus pairs found in %s, but the spec's example pages declare %d. Every ::: compare block in "
        . "resources/examples/{core,extensions,edge-cases}.md becomes one corpus pair, so a difference means the corpus "
        . "checked out here is not the one those pages describe - a truncated or stale checkout, or a corpus that needs "
        . "regenerating. It does not mean this run was clean.\n",
        count($pairs),
        $corpusDir,
        $declared,
    ));
    exit(1);
}

$wrong = [];
$threw = 0;

foreach ($pairs as $source) {
    $expected = substr($source, 0, -4) . '.html';
    $name = basename($source);

    try {
        // The bare converter, because the corpus is core Carve. The plugin's
        // own extension stack is what tests/ConverterTest.php covers; mixing it
        // in here would put this repository's configuration into a number that
        // is supposed to mean "the shipped engine disagrees with the spec".
        $got = (new MarkupCarve\Carve\CarveConverter())->convert((string)file_get_contents($source));
    } catch (Throwable $e) {
        $threw++;
        $wrong[] = $name;

        continue;
    }

    if (trim($got) !== trim((string)file_get_contents($expected))) {
        $wrong[] = $name;
    }
}

if ($list) {
    foreach ($wrong as $name) {
        fwrite(STDOUT, "wrong: {$name}\n");
    }
}

fwrite(STDOUT, 'documents=' . count($pairs) . "\n");
fwrite(STDOUT, 'wrong=' . count($wrong) . "\n");
fwrite(STDOUT, "threw={$threw}\n");
