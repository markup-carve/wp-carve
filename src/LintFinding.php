<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

use MarkupCarve\Carve\Diagnostics\Diagnostic;

/**
 * A lint finding with a 1-based line and column, built from a carve-php 0.2
 * diagnostic (which carries UTF-8 byte offsets).
 */
final class LintFinding
{
    public function __construct(
        public readonly int $line,
        public readonly int $column,
        public readonly string $rule,
        public readonly string $message,
        public readonly string $severity = 'warning',
    ) {
    }

    public static function fromDiagnostic(Diagnostic $diagnostic, string $source): self
    {
        $line = 1;
        $column = 1;
        if ($diagnostic->startOffset >= 0 && $diagnostic->file === null) {
            $before = substr($source, 0, min($diagnostic->startOffset, strlen($source)));
            $line = substr_count($before, "\n") + 1;
            $lastBreak = strrpos($before, "\n");
            $column = mb_strlen($lastBreak === false ? $before : substr($before, $lastBreak + 1), 'UTF-8') + 1;
        }

        return new self($line, $column, $diagnostic->code, $diagnostic->message, $diagnostic->severity);
    }
}
