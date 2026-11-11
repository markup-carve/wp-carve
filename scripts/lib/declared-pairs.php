<?php

declare(strict_types=1);

/**
 * Count the corpus pairs one example page declares: every `carve` fence inside
 * a `::: compare` block, since a block may hold several pairs. Port of the
 * spec's scripts/lib/example-pair-census.mjs.
 */
function countDeclaredPairs(string $source): int
{
    return count(declaredPairInputs($source));
}

/**
 * The input of every pair one example page declares, keyed by the block's
 * modifiers so that a `crlf` or `bom` variant of the same text stays distinct.
 *
 * @return array<string>
 */
function declaredPairInputs(string $source): array
{
    $inputs = [];
    $marker = null;
    $modifiers = '';
    $fence = null;
    $body = null;

    foreach (explode("\n", $source) as $line) {
        // Inside a fence nothing is markup, so an example whose content is a
        // fenced `carve` block does not count as a second pair.
        if ($fence !== null) {
            if (str_starts_with($line, $fence) && trim(substr($line, strlen($fence))) === '') {
                if ($body !== null) {
                    $inputs[] = $modifiers . "\0" . implode("\n", $body);
                }
                $fence = null;
                $body = null;
            } elseif ($body !== null) {
                $body[] = $line;
            }

            continue;
        }

        $ticks = strspn($line, '`');
        if ($ticks >= 3) {
            $fence = substr($line, 0, $ticks);
            if ($marker !== null && trim(substr($line, $ticks)) === 'carve') {
                $body = [];
            }

            continue;
        }

        $trimmed = trim($line);
        $colons = strspn($trimmed, ':');
        if ($colons < 3) {
            continue;
        }
        if ($marker === null) {
            $rest = substr($trimmed, $colons);
            if (preg_match('/^[ \t]+compare(?:[ \t]+(.*))?$/', $rest, $match) === 1) {
                $marker = substr($trimmed, 0, $colons);
                $words = preg_split('/[ \t]+/', trim($match[1] ?? '')) ?: [];
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
