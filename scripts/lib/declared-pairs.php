<?php

declare(strict_types=1);

/**
 * Count the corpus pairs one example page declares: every `carve` fence inside
 * a `::: compare` block, since a block may hold several pairs. Port of the
 * spec's scripts/lib/example-pair-census.mjs.
 */
function countDeclaredPairs(string $source): int
{
    $pairs = 0;
    $marker = null;
    $fence = null;

    foreach (explode("\n", $source) as $line) {
        // Inside a fence nothing is markup, so an example whose content is a
        // fenced `carve` block does not count as a second pair.
        if ($fence !== null) {
            if (str_starts_with($line, $fence) && trim(substr($line, strlen($fence))) === '') {
                $fence = null;
            }

            continue;
        }

        $ticks = strspn($line, '`');
        if ($ticks >= 3) {
            $fence = substr($line, 0, $ticks);
            if ($marker !== null && trim(substr($line, $ticks)) === 'carve') {
                $pairs++;
            }

            continue;
        }

        $trimmed = trim($line);
        $colons = strspn($trimmed, ':');
        if ($colons < 3) {
            continue;
        }
        if ($marker === null) {
            if (preg_match('/^[ \t]+compare(?:[ \t]|$)/', substr($trimmed, $colons)) === 1) {
                $marker = substr($trimmed, 0, $colons);
            }

            continue;
        }
        if ($trimmed === $marker) {
            $marker = null;
        }
    }

    return $pairs;
}

/**
 * The distinct inputs one example page declares, keyed by the compare block's
 * byte modifiers plus the `carve` fence content. carve's generate-corpus.mjs
 * writes one fixture per distinct input, so two examples with the same document
 * are one corpus pair.
 *
 * @return array<string, true>
 */
function declaredPairInputs(string $source): array
{
    $inputs = [];
    $marker = null;
    $modifiers = '';
    $fence = null;
    $body = null;

    foreach (explode("\n", $source) as $line) {
        if ($fence !== null) {
            if (str_starts_with($line, $fence) && trim(substr($line, strlen($fence))) === '') {
                if ($body !== null) {
                    $inputs[$modifiers . "\0" . implode("\n", $body)] = true;
                }
                $fence = null;
                $body = null;

                continue;
            }
            if ($body !== null) {
                $body[] = $line;
            }

            continue;
        }

        $ticks = strspn($line, '`');
        if ($ticks >= 3) {
            $fence = substr($line, 0, $ticks);
            $body = $marker !== null && trim(substr($line, $ticks)) === 'carve' ? [] : null;

            continue;
        }

        $trimmed = trim($line);
        $colons = strspn($trimmed, ':');
        if ($colons < 3) {
            continue;
        }
        if ($marker === null) {
            if (preg_match('/^[ \t]+compare(?:[ \t]+(.*))?$/', substr($trimmed, $colons), $match) === 1) {
                $marker = substr($trimmed, 0, $colons);
                $words = array_diff(preg_split('/\s+/', trim($match[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [], ['no-render']);
                sort($words);
                $modifiers = implode(' ', $words);
            }

            continue;
        }
        if ($trimmed === $marker) {
            $marker = null;
        }
    }

    return $inputs;
}
