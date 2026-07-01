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
        register_block_type(WP_CARVE_DIR . 'assets/blocks/carve', [
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
        $safe = Plugin::safeForAuthor((int)get_post_field('post_author', get_the_ID()));
        $html = $this->converter->toHtml($carve, 'post', $profile !== '' ? $profile : null, $safe);

        return sprintf('<div class="wp-carve">%s</div>', $html);
    }
}
