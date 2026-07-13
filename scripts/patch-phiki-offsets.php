<?php
/**
 * Patch phiki's capture-offset recovery (upstream bug, phiki/phiki v2.2.0).
 *
 * mb_ereg_search_getregs() returns capture TEXTS without offsets, and phiki
 * re-derives each capture's position with a first-occurrence mb_strpos over
 * the match. Any capture whose text repeats inside the match gets the wrong
 * offset - most visibly a closing delimiter identical to the opening one
 * (`*bold*`: the closing `*` resolves to offset 0), which silently drops the
 * closing punctuation scope from every such token.
 *
 * The patch replaces the naive lookup with a cursor walk: capture N+1 starts
 * at or after capture N's start (capture numbers follow opening-paren order),
 * and a same-start candidate longer than the previous capture cannot be
 * nested inside it, so the search resumes past the previous capture's end.
 *
 * Idempotent; run from Composer post-install/post-update hooks and against
 * the staged distribution vendor in the deploy workflow. Remove when the
 * upstream fix ships.
 *
 * Usage: php scripts/patch-phiki-offsets.php [vendor-dir]
 */

declare(strict_types=1);

$vendor = $argv[1] ?? dirname(__DIR__) . '/vendor';
$file = rtrim($vendor, '/\\') . '/phiki/phiki/src/TextMate/PatternSearcher.php';

if (!is_file($file)) {
    echo "patch-phiki-offsets: {$file} not found, skipping.\n";
    exit(0);
}

$content = (string)file_get_contents($file);

if (str_contains($content, 'wpcarve-capture-offset-patch')) {
    echo "patch-phiki-offsets: already applied.\n";
    exit(0);
}

$search = <<<'PHP'
            // For subsequent matches, we can use the reduced search grid to find the position
            // of the match within the substring. We need to adjust the position based on the
            // original input string's start position.
            $pos = mb_strpos($substr, $match);

            // We can then store the value in the matches array with the adjusted position.
            $wellFormedMatches[$key] = [$match, $bestLocation + $pos];
        }
PHP;

$replace = <<<'PHP'
            // wpcarve-capture-offset-patch: a first-occurrence search returns
            // the wrong offset for any capture whose text repeats inside the
            // match (e.g. a closing delimiter identical to the opening one).
            // Capture numbers follow opening-paren order, so each capture
            // starts at or after the previous capture's start; a same-start
            // candidate longer than the previous capture cannot be nested
            // inside it and must be a later sibling.
            $pos = mb_strpos($substr, $match, min($patchPrevStart, mb_strlen($substr)));

            if ($pos === $patchPrevStart && mb_strlen($match) > $patchPrevEnd - $patchPrevStart) {
                $sibling = mb_strpos($substr, $match, min($patchPrevEnd, mb_strlen($substr)));

                if ($sibling !== false) {
                    $pos = $sibling;
                }
            }

            if ($pos === false) {
                $pos = (int) mb_strpos($substr, $match);
            }

            $patchPrevStart = $pos;
            $patchPrevEnd = max($patchPrevEnd, $pos + mb_strlen($match));

            // We can then store the value in the matches array with the adjusted position.
            $wellFormedMatches[$key] = [$match, $bestLocation + $pos];
        }
PHP;

if (!str_contains($content, $search)) {
    fwrite(STDERR, "patch-phiki-offsets: anchor not found - phiki changed, re-check the upstream fix.\n");
    exit(1);
}

$content = str_replace($search, $replace, $content);

// Initialize the cursor state before the loop.
$loopAnchor = '        foreach ($bestMatches as $key => $match) {';
if (!str_contains($content, $loopAnchor)) {
    fwrite(STDERR, "patch-phiki-offsets: loop anchor not found.\n");
    exit(1);
}
$content = str_replace(
    $loopAnchor,
    "        \$patchPrevStart = 0;\n        \$patchPrevEnd = 0;\n" . $loopAnchor,
    $content,
);

file_put_contents($file, $content);
echo "patch-phiki-offsets: applied.\n";
