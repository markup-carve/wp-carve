<?php

declare(strict_types=1);

namespace WpCarve\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use WpCarve\Converter;
use WpCarve\Plugin;

/**
 * The `carve/markup` Gutenberg block. Stores raw Carve in a `carve` attribute
 * and renders it server-side; the editor script (index.js) provides the
 * source textarea and the live in-browser preview (innovation A).
 */
class CarveBlock
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
        register_block_type(WPCARVE_DIR . 'assets/blocks/carve', [
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

        $profile = (string)($attributes['profile'] ?? '');
        $bibliography = json_decode((string)($attributes['bibliography'] ?? ''), true);
        if (!is_array($bibliography) || $bibliography !== array_values($bibliography)) {
            $bibliography = [];
        }
        $citationMode = (string)($attributes['citationMode'] ?? 'numbered');
        $safe = Plugin::safeForAuthor((int)get_post_field('post_author', get_the_ID()));
        // A block post reaches a feed through this callback and never through
        // Plugin::maybeRenderPost, which bails on any post without
        // `_wpcarve_enabled`. So the feed context has to be chosen here too, or
        // a Carve BLOCK keeps handing feed readers hydration containers while a
        // Carve POST degrades correctly.
        $context = function_exists('is_feed') && is_feed() ? 'feed' : 'post';
        $html = $this->converter->toHtml(
            $carve,
            $context,
            $profile !== '' ? $profile : null,
            $safe,
            $bibliography,
            $citationMode,
        );

        // Escape the rendered markup at the block render callback's return, so
        // only allowlisted tags/attributes reach output (wp_kses is idempotent
        // over the already-sanitized converter output).
        return sprintf('<div class="wpcarve">%s</div>', Converter::sanitizeHtml($html));
    }
}
