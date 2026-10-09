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
