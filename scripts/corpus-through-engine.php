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
 *   php scripts/corpus-through-engine.php <corpus-dir> [--list] [--baseline=<file>]
 *
 * Prints key=value lines for the workflow to read. With --baseline it also
 * compares the divergent documents against a recorded list and exits non-zero
 * when a document that rendered correctly there renders differently now.
 */

declare(strict_types=1);

$argvRest = array_slice($argv, 1);
$list = in_array('--list', $argvRest, true);
$positional = array_values(array_filter($argvRest, static fn (string $a): bool => !str_starts_with($a, '--')));

$baselineFile = null;
foreach ($argvRest as $arg) {
    if (str_starts_with($arg, '--baseline=')) {
        $baselineFile = substr($arg, strlen('--baseline='));
    }
}

if (count($positional) < 1) {
    fwrite(STDERR, "usage: corpus-through-engine.php <corpus-dir> [--list] [--baseline=<file>]\n");
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

/**
 * Hold the divergent documents against the list recorded at the pinned freeze.
 *
 * The corpus is pinned to a commit and the engine to a lockfile revision, so
 * both sides of this measurement are fixed and the divergent set is a constant
 * that only THIS repository can move. Comparing counts would miss the case that
 * matters most: one document regressing while another is fixed leaves the total
 * unchanged and the gate silent. So the comparison is by name.
 *
 * @param array<int, string> $wrong Documents that rendered differently in this run.
 * @param array<int, string> $present Every document the pinned corpus actually holds.
 *
 * @return int Process exit status.
 */
/**
 * Written on its own line in the baseline to declare, explicitly, that the
 * shipped engine renders every corpus document correctly. Distinguishes a
 * deliberate zero from a baseline that did not load.
 */
const NONE_SENTINEL = '(none)';

function compareAgainstBaseline(string $path, array $wrong, array $present): int
{
    if (!file_exists($path)) {
        fwrite(STDERR, "::error::no baseline at {$path}; without the recorded divergences this run has nothing to be worse than\n");

        return 2;
    }

    $recorded = [];
    foreach (explode("\n", (string)file_get_contents($path)) as $rawLine) {
        $line = trim($rawLine);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $recorded[] = $line;
    }

    // A file with no names used to be rejected outright, because an empty list
    // and a list that failed to load are the same bytes and only one of them is
    // a result. Since carve-php 0.1.6 against the carve 0.1.4 corpus the empty
    // list is the true answer, so the two states are told apart by a sentinel
    // instead: `(none)` sits where the names sit, so anything that truncates
    // the names away takes it too and still fails here.
    $declaresNone = in_array(NONE_SENTINEL, $recorded, true);
    $recorded = array_values(array_diff($recorded, [NONE_SENTINEL]));

    if ($recorded === [] && !$declaresNone) {
        fwrite(STDERR, sprintf(
            "::error::%s names no documents and does not carry the %s line either, so it cannot say whether the corpus "
            . "renders clean or the baseline failed to load. Write %s on its own line to declare zero divergences.\n",
            $path,
            NONE_SENTINEL,
            NONE_SENTINEL,
        ));

        return 1;
    }

    if ($recorded !== [] && $declaresNone) {
        fwrite(STDERR, sprintf(
            "::error::%s carries %s and also names %d document(s). Those cannot both be true; delete whichever is stale.\n",
            $path,
            NONE_SENTINEL,
            count($recorded),
        ));

        return 1;
    }

    // A recorded name the corpus does not hold means the corpus is not the one
    // the baseline was measured against, so neither list below would mean
    // anything. Report that instead of a verdict.
    $absent = array_values(array_diff($recorded, $present));
    if ($absent !== []) {
        fwrite(STDERR, sprintf(
            "::error::the baseline names %d document(s) this corpus does not hold (%s). The corpus checkout is not the pinned "
            . "freeze the baseline was measured against, so nothing in this run is a statement about this plugin. Fix the ref "
            . "in the workflow, or re-measure and re-record if the pin moved on purpose.\n",
            count($absent),
            implode(', ', array_slice($absent, 0, 10)) . (count($absent) > 10 ? ', ...' : ''),
        ));

        return 1;
    }

    $regressed = array_values(array_diff($wrong, $recorded));
    $improved = array_values(array_diff($recorded, $wrong));

    if ($regressed !== []) {
        fwrite(STDERR, sprintf(
            "::error::%d document(s) render differently now that rendered correctly at the freeze: %s. The corpus and the "
            . "engine revision are both pinned, so this is a change in this pull request and not upstream movement.\n",
            count($regressed),
            implode(', ', $regressed),
        ));

        return 1;
    }

    if ($improved !== []) {
        fwrite(STDERR, sprintf(
            "::warning::%d document(s) recorded as divergent render correctly now: %s. Delete those lines from %s so the "
            . "baseline keeps meaning what it says.\n",
            count($improved),
            implode(', ', $improved),
            $path,
        ));
    }

    fwrite(STDOUT, 'regressed=' . count($regressed) . "\n");
    fwrite(STDOUT, 'improved=' . count($improved) . "\n");

    return 0;
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

if ($baselineFile !== null) {
    exit(compareAgainstBaseline($baselineFile, $wrong, array_map('basename', $pairs)));
}
