<?php

declare(strict_types=1);

namespace WpCarve\Ingest;

if (!defined('ABSPATH')) {
    exit;
}

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;

/**
 * Converts foreign markup to Carve with the carve-php 0.2 importer.
 *
 * The 0.2 engine imports HTML, Markdown and Djot. BBCode remains unavailable.
 */
final class Importer
{
    /**
     * The engine issue each missing importer waits on.
     *
     * @var array<string, string>
     */
    public const PENDING = [
        'bbcode' => 'markup-carve/carve-php#5251',
    ];

    /**
     * @throws \WpCarve\Ingest\ImporterUnavailable
     *
     * @return array{value: string, report: array{source_format: string, diagnostics: array<int, array<string, string>>}}
     */
    public static function import(string $from, string $source): array
    {
        if (!in_array($from, ['html', 'markdown', 'djot'], true)) {
            throw new ImporterUnavailable(esc_html($from), esc_html(self::PENDING[$from] ?? ''));
        }

        // WordPress authors use GitHub Markdown alerts and task lists.
        $result = Carve::importDocument($source, ['from' => $from === 'markdown' ? 'gfm' : $from]);

        return [
            'value' => $result->source,
            'report' => [
                'schemaVersion' => 2,
                'source_format' => $from,
                'diagnostics' => array_map(static fn (Diagnostic $d): array => self::entry($d), $result->diagnostics),
            ],
        ];
    }

    /**
     * One report entry in the 0.1 report shape the editor and the migrator read.
     *
     * @return array<string, string>
     */
    private static function entry(Diagnostic $diagnostic): array
    {
        $fidelity = match (true) {
            str_contains($diagnostic->code, 'dropped') => 'dropped',
            $diagnostic->severity === 'hint' => 'normalized',
            default => 'approximated',
        };

        return [
            'code' => $diagnostic->code,
            'message' => $diagnostic->message,
            'severity' => $diagnostic->severity,
            'fidelity' => $fidelity,
            'element' => (string)$diagnostic->foreignType,
        ];
    }
}
