<?php

declare(strict_types=1);

namespace WpCarve\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use WpCarve\Converter;
use function __;
use function esc_attr;
use function esc_attr__;

/**
 * The `carve/slides` Gutenberg block. Stores a Carve deck in one source
 * attribute and renders it as a progressively-enhanced slide presentation.
 */
class SlidesBlock
{
    public function __construct(private Converter $converter)
    {
    }

    public function register(): void
    {
        add_action('init', [$this, 'registerType']);
    }

    public function registerType(): void
    {
        register_block_type(WP_CARVE_DIR . 'assets/blocks/slides', [
            'render_callback' => [$this, 'render'],
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function render(array $attributes): string
    {
        $carve = (string)($attributes['carve'] ?? '');
        if (trim($carve) === '') {
            return '';
        }

        $theme = $this->theme((string)($attributes['theme'] ?? 'signal'));
        $layout = $this->layout((string)($attributes['layout'] ?? 'standard'));
        $align = $this->align((string)($attributes['align'] ?? ''));
        $classes = trim(sprintf(
            'wp-carve wp-carve-slides wp-carve-slides--%s wp-carve-slides--%s %s',
            $theme,
            $layout,
            $align,
        ));
        $slides = $this->splitSlides($carve);
        $count = count($slides);
        $sections = [];

        foreach ($slides as $index => $slide) {
            $html = $this->converter->toHtml($slide, 'post');
            $sections[] = sprintf(
                '<section class="wp-carve-slide%s" data-slide="%d" aria-label="%s">%s</section>',
                $index === 0 ? ' is-active' : '',
                $index + 1,
                esc_attr(sprintf(__('Slide %1$d of %2$d', 'carve-markup'), $index + 1, $count)),
                $html,
            );
        }

        return sprintf(
            '<div class="%s" data-wp-carve-slides data-current="1" data-count="%d" tabindex="0">%s%s</div>',
            esc_attr($classes),
            $count,
            implode('', $sections),
            $this->controls($count),
        );
    }

    private function controls(int $count): string
    {
        return sprintf(
            '<div class="wp-carve-slides-controls" aria-label="%s"><button type="button" class="wp-carve-slides-prev" aria-label="%s">&lsaquo;</button><span class="wp-carve-slides-count"><span data-wp-carve-slide-current>1</span> / <span>%d</span></span><button type="button" class="wp-carve-slides-next" aria-label="%s">&rsaquo;</button><button type="button" class="wp-carve-slides-fullscreen" aria-label="%s">[]</button></div>',
            esc_attr__('Slide controls', 'carve-markup'),
            esc_attr__('Previous slide', 'carve-markup'),
            $count,
            esc_attr__('Next slide', 'carve-markup'),
            esc_attr__('Toggle fullscreen', 'carve-markup'),
        );
    }

    /**
     * @return list<string>
     */
    private function splitSlides(string $carve): array
    {
        $slides = [];
        $current = [];
        $inFence = false;
        $lines = preg_split('/\R/u', $carve) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*`{3,}|^\s*~{3,}/', $line)) {
                $inFence = !$inFence;
            }

            if (!$inFence && preg_match('/^\s*---\s*$/', $line)) {
                $slide = trim(implode("\n", $current));
                if ($slide !== '') {
                    $slides[] = $slide;
                }
                $current = [];

                continue;
            }

            $current[] = $line;
        }

        $slide = trim(implode("\n", $current));
        if ($slide !== '') {
            $slides[] = $slide;
        }

        return $slides ?: [trim($carve)];
    }

    private function theme(string $theme): string
    {
        return in_array($theme, ['signal', 'paper', 'night'], true) ? $theme : 'signal';
    }

    private function layout(string $layout): string
    {
        return in_array($layout, ['standard', 'wide', 'compact'], true) ? $layout : 'standard';
    }

    private function align(string $align): string
    {
        return in_array($align, ['wide', 'full'], true) ? 'align' . $align : '';
    }
}
