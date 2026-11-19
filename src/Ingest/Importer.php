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
 * 0.2 imports HTML only. Markdown, Djot and BBCode import left the engine
 * (C2-T-003); they fail with ImporterUnavailable until carve-php provides them.
 */
final class Importer
{
    /**
     * The engine issue each missing importer waits on.
     *
     * @var array<string, string>
     */
    public const PENDING = [
        'markdown' => 'markup-carve/carve-php#5237',
        'djot' => 'markup-carve/carve-php#5242',
        'bbcode' => 'markup-carve/carve-php#5251',
    ];

    /**
     * @throws \WpCarve\Ingest\ImporterUnavailable
     *
     * @return array{value: string, report: array{source_format: string, diagnostics: array<int, array<string, string>>}}
     */
    public static function import(string $from, string $source): array
    {
        if ($from !== 'html') {
            throw new ImporterUnavailable($from, self::PENDING[$from] ?? '');
        }

        $result = Carve::importDocument($source, ['from' => 'html']);

        return [
            'value' => $result->source,
            'report' => [
                'source_format' => 'html',
                'diagnostics' => array_map(self::entry(...), $result->diagnostics),
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
