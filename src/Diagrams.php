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
 * the `wp_carve_diagram_renderers` filter and automatically gets a settings
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
                'label' => __('Mermaid diagrams', 'carve-markup'),
                'class' => 'mermaid',
                'preset' => 'mermaid',
                'mode' => 'text',
                'libs' => ['mermaid.min.js'],
            ],
            'chart' => [
                'label' => __('Chart.js charts', 'carve-markup'),
                'class' => 'chart',
                'preset' => 'chart',
                'mode' => 'json',
                'libs' => ['chart.umd.min.js'],
            ],
            'vega' => [
                'label' => __('Vega-Lite charts', 'carve-markup'),
                'class' => 'vega-lite',
                'preset' => 'vegaLite',
                'mode' => 'json',
                'libs' => ['vega.min.js', 'vega-lite.min.js', 'vega-embed.min.js'],
            ],
            'graphviz' => [
                'label' => __('Graphviz (DOT) diagrams', 'carve-markup'),
                'class' => 'graphviz',
                'preset' => 'graphviz',
                'mode' => 'text',
                'libs' => ['viz-standalone.min.js'],
            ],
            'wavedrom' => [
                'label' => __('WaveDrom timing diagrams', 'carve-markup'),
                'class' => 'wavedrom',
                'preset' => 'wavedrom',
                'mode' => 'text',
                'libs' => ['wavedrom.min.js', 'wavedrom-skin-default.js'],
            ],
            'abc' => [
                'label' => __('ABC music notation', 'carve-markup'),
                'class' => 'abc',
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
        $renderers = apply_filters('wp_carve_diagram_renderers', $builtin);

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
