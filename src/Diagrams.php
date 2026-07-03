<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registry of opt-in diagram / chart renderers.
 *
 * Each entry maps a fenced-render type (carve-php `FencedRenderExtension`
 * preset) to a backend toggle and a set of vendored JS libraries that are
 * loaded only when the type is enabled AND a page actually uses it.
 *
 * The registry is filterable: an add-on can register a new diagram type with
 * the `wpcarve_diagram_renderers` filter and automatically gets a settings
 * toggle, conditional script loading, and extension registration.
 *
 * Entry shape:
 * - label string Settings checkbox label.
 * - class string Fence word and CSS class on the rendered wrapper.
 * - preset string carve-php FencedRenderExtension factory (e.g. 'mermaid'),
 *                  or null to use the generic constructor.
 * - mode string 'text' or 'json' (json keeps the source in an inner
 *                  `<script type="application/json">`).
 * - libs string[] Vendored file names under assets/js/vendor/ (in order).
 * - src string[] Optional external script URLs (for custom renderers).
 * - init string Optional inline JS for a custom renderer; built-in types
 *                  are handled by the shared diagrams.js.
 */
class Diagrams
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $builtin = [
            'mermaid' => [
                'label' => 'Mermaid diagrams',
                'class' => 'mermaid',
                'url' => 'https://mermaid.js.org',
                'preset' => 'mermaid',
                'mode' => 'text',
                'libs' => ['mermaid.min.js'],
            ],
            'chart' => [
                'label' => 'Chart.js charts',
                'class' => 'chart',
                'url' => 'https://www.chartjs.org',
                'preset' => 'chart',
                'mode' => 'json',
                'libs' => ['chart.umd.min.js'],
            ],
            'vega' => [
                'label' => 'Vega-Lite charts',
                'class' => 'vega-lite',
                'url' => 'https://vega.github.io/vega-lite/',
                'preset' => 'vegaLite',
                'mode' => 'json',
                'libs' => ['vega.min.js', 'vega-lite.min.js', 'vega-embed.min.js'],
            ],
            'graphviz' => [
                'label' => 'Graphviz (DOT) diagrams',
                'class' => 'graphviz',
                'url' => 'https://graphviz.org',
                'preset' => 'graphviz',
                'mode' => 'text',
                'libs' => ['viz-standalone.min.js'],
            ],
            'wavedrom' => [
                'label' => 'WaveDrom timing diagrams',
                'class' => 'wavedrom',
                'url' => 'https://wavedrom.com',
                'preset' => 'wavedrom',
                'mode' => 'text',
                'libs' => ['wavedrom.min.js', 'wavedrom-skin-default.js'],
            ],
            'abc' => [
                'label' => 'ABC music notation',
                'class' => 'abc',
                'url' => 'https://www.abcjs.net',
                'preset' => 'abc',
                'mode' => 'text',
                'libs' => ['abcjs-basic-min.js'],
            ],
        ];

        /**
         * Filter the diagram renderer registry.
         *
         * @param array<string, array<string, mixed>> $builtin
         */
        $renderers = apply_filters('wpcarve_diagram_renderers', $builtin);

        return is_array($renderers) ? $renderers : $builtin;
    }

    /**
     * Setting key for a renderer name (e.g. 'chart' => 'chart_enabled').
     */
    public static function settingKey(string $name): string
    {
        return $name . '_enabled';
    }
}
