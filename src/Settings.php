<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin settings store. Wraps a single `wp_carve_settings` option with typed
 * defaults so the rest of the plugin reads a fully-populated array.
 */
class Settings
{
    /**
     * @var string
     */
    public const OPTION = 'wp_carve_settings';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enable_posts' => true,
            'enable_comments' => false,
            'enable_shortcode' => true,
            'safe_mode' => true,
            'post_profile' => 'article',
            'comment_profile' => 'comment',
            'toc_enabled' => false,
            'toc_min_level' => 2,
            'toc_max_level' => 4,
            'permalinks_enabled' => false,
            'smart_quotes' => false,
            'mermaid_enabled' => false,
            'normalize_tabs' => false,
            'tab_width' => 2,
            // Innovation toggles.
            'live_preview' => true,
            'paste_ingest' => true,
            'frontmatter_meta' => true,
            'render_cache' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return $stored + self::defaults();
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function update(array $values): void
    {
        update_option(self::OPTION, $values + self::all());
    }
}
