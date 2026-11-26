<?php

declare(strict_types=1);

namespace WpCarve\Extension;

if (!defined('ABSPATH')) {
    exit;
}

use MarkupCarve\Carve\Extension\Claim;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\ExtensionProvider;
use MarkupCarve\Carve\Extension\RenderHost;
use MarkupCarve\Carve\Node;
use WpCarve\Converter;

/**
 * Diagram fences (```mermaid, ```chart, ...) as Carve 0.2 fence extensions.
 *
 * Writes the hydration containers diagrams.js reads: `<pre class="x">` for
 * text diagrams, `<div class="x" data-carve-json>` for JSON configs. There is
 * no build-time renderer, so `mode: static` (feeds) renders the fence fallback,
 * a readable code block.
 */
class DiagramFences implements ExtensionProvider
{
    /**
     * @param array<string, array<string, mixed>> $diagrams Enabled entries of Diagrams::all().
     */
    public function __construct(private array $diagrams)
    {
    }

    /**
     * @return array<\MarkupCarve\Carve\Extension\ExtensionDefinition>
     */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->diagrams as $diagram) {
            $class = (string)($diagram['class'] ?? '');
            if ($class === '') {
                continue;
            }
            $json = ($diagram['mode'] ?? 'text') === 'json';
            $languages = array_merge([$class], array_map('strval', (array)($diagram['aliases'] ?? [])));
            foreach (array_unique($languages) as $language) {
                $definitions[] = self::definition($language, $class, $json);
            }
        }

        return $definitions;
    }

    private static function definition(string $language, string $class, bool $json): ExtensionDefinition
    {
        $name = 'wpcarve.diagram.' . preg_replace('/[^a-z0-9]+/', '-', strtolower($language));

        return new ExtensionDefinition(
            $name,
            '1',
            matcher: ['form' => 'fence', 'name' => $language],
            transform: static function (Claim $claim) use ($name, $class): Node {
                $node = new Node('block_extension', []);
                $node->f = [
                    'name' => $name,
                    'version' => '1',
                    'props' => ['class' => $class],
                    'payload' => [['format' => 'text/plain', 'value' => (string)($claim->node->f['value'] ?? '')]],
                    'fallback' => [$claim->copyNode()],
                ];

                return $node;
            },
            renderer: static function (Node $node, RenderHost $host) use ($class, $json): string {
                $payload = $node->f['payload'][0]['value'] ?? '';
                $source = is_string($payload) ? rtrim($payload, "\n") : '';
                if (!$json) {
                    return '<pre class="' . RenderHost::escapeAttribute($class) . '">' . RenderHost::escapeText($source) . '</pre>';
                }

                return '<div class="' . RenderHost::escapeAttribute($class) . '" role="img" aria-label="'
                    . RenderHost::escapeAttribute($class) . '" data-carve-json="' . RenderHost::escapeAttribute($source) . '"></div>'
                    . ($class === 'chart' ? Converter::chartDataTable($source) : '');
            },
        );
    }
}
