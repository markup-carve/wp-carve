<?php
/**
 * Render every `render` example of the Carve 0.2 conformance file
 * (spec/0.2/conformance/examples.json in markup-carve/carve) through the engine
 * this plugin ships, and count the ones whose HTML differs.
 *
 *   php scripts/conformance-through-engine.php <examples.json> [--list] [--baseline=<file>]
 *
 * Prints key=value lines for the workflow to read. With --baseline it exits
 * non-zero when an example outside the recorded list renders differently.
 */

declare(strict_types=1);

use MarkupCarve\Carve\Carve;

$rest = array_slice($argv, 1);
$list = in_array('--list', $rest, true);
$positional = array_values(array_filter($rest, static fn (string $a): bool => !str_starts_with($a, '--')));
$baselineFile = null;
foreach ($rest as $arg) {
    if (str_starts_with($arg, '--baseline=')) {
        $baselineFile = substr($arg, strlen('--baseline='));
    }
}
if (count($positional) < 1 || !is_file($positional[0])) {
    fwrite(STDERR, "usage: conformance-through-engine.php <examples.json> [--list] [--baseline=<file>]\n");
    exit(2);
}

require __DIR__ . '/../vendor/autoload.php';

$data = json_decode((string)file_get_contents($positional[0]), true);
$examples = is_array($data) ? ($data['examples'] ?? null) : null;
if (!is_array($examples) || $examples === []) {
    fwrite(STDERR, "::error::{$positional[0]} holds no examples; nothing would be measured\n");
    exit(1);
}

$wrong = [];
$threw = 0;
$rendered = 0;
foreach ($examples as $example) {
    if (($example['kind'] ?? '') !== 'render' || !isset($example['expected']['html'])) {
        continue;
    }
    $rendered++;
    $id = (string)$example['id'];
    try {
        $got = Carve::render((string)$example['input'], (array)($example['options'] ?? []));
    } catch (Throwable) {
        $threw++;
        $wrong[] = $id;

        continue;
    }
    if (trim($got) !== trim((string)$example['expected']['html'])) {
        $wrong[] = $id;
    }
}

if ($rendered === 0) {
    fwrite(STDERR, "::error::no render examples in {$positional[0]}\n");
    exit(1);
}
if ($list) {
    foreach ($wrong as $id) {
        fwrite(STDOUT, "wrong: {$id}\n");
    }
}
fwrite(STDOUT, "documents={$rendered}\n");
fwrite(STDOUT, 'wrong=' . count($wrong) . "\n");
fwrite(STDOUT, "threw={$threw}\n");

if ($baselineFile !== null) {
    $known = array_filter(array_map('trim', (array)file($baselineFile)), static fn (string $l): bool => $l !== '' && !str_starts_with($l, '#'));
    $new = array_values(array_diff($wrong, $known));
    foreach ($new as $id) {
        fwrite(STDERR, "::error::{$id} renders differently from the conformance example and is not in the baseline\n");
    }
    exit($new === [] ? 0 : 1);
}
