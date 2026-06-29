<?php

declare(strict_types=1);

namespace WpCarve\Migration;

if (!defined('ABSPATH')) {
    exit;
}

use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use WP_Post;

/**
 * Migrate existing post content into Carve, with a safety analysis first.
 *
 * `analyze()` decides whether a post can be auto-migrated: it skips posts that
 * already use the block editor or carry non-trivial shortcodes, and detects
 * whether the source looks like Markdown or HTML. `migrate()` performs the
 * conversion and flags the post to render as Carve.
 */
class Migrator
{
    private MarkdownToCarve $markdown;

    private HtmlToCarve $html;

    public function __construct()
    {
        $this->markdown = new MarkdownToCarve();
        $this->html = new HtmlToCarve();
    }

    /**
     * @return array{post_id: int, source: string, can_auto_migrate: bool, reason: string}
     */
    public function analyze(int $postId): array
    {
        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            return ['post_id' => $postId, 'source' => 'none', 'can_auto_migrate' => false, 'reason' => 'Post not found.'];
        }

        $content = (string)$post->post_content;
        if (trim($content) === '') {
            return ['post_id' => $postId, 'source' => 'none', 'can_auto_migrate' => false, 'reason' => 'Empty content.'];
        }
        if (has_blocks($content)) {
            return ['post_id' => $postId, 'source' => 'blocks', 'can_auto_migrate' => false, 'reason' => 'Uses the block editor; convert manually.'];
        }
        if ($this->hasComplexShortcodes($content)) {
            return ['post_id' => $postId, 'source' => 'shortcodes', 'can_auto_migrate' => false, 'reason' => 'Contains shortcodes; convert manually.'];
        }

        $source = $this->detectMarkdown($content) ? 'markdown' : 'html';

        return ['post_id' => $postId, 'source' => $source, 'can_auto_migrate' => true, 'reason' => 'OK'];
    }

    /**
     * Convert and flag the post. Returns the new Carve length, or null when the
     * post cannot be auto-migrated (unless $force).
     */
    public function migrate(int $postId, bool $force = false): ?int
    {
        $analysis = $this->analyze($postId);
        if (!$analysis['can_auto_migrate'] && !$force) {
            return null;
        }

        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            return null;
        }

        $carve = $analysis['source'] === 'markdown'
            ? $this->markdown->convert($post->post_content)
            : $this->html->convert($post->post_content);

        wp_update_post(['ID' => $postId, 'post_content' => $carve]);
        update_post_meta($postId, '_wp_carve_enabled', 1);

        return strlen($carve);
    }

    private function detectMarkdown(string $content): bool
    {
        // Strong Markdown signals that are not valid HTML.
        return (bool)preg_match('/^#{1,6}\s|\*\*[^*]+\*\*|^[-*+]\s|\[[^\]]+\]\([^)]+\)|^>\s/m', $content)
            && !preg_match('/^\s*<[a-z]/i', $content);
    }

    private function hasComplexShortcodes(string $content): bool
    {
        // Ignore our own [carve] tag; any other registered shortcode is "complex".
        if (!preg_match_all('/\[([a-z][a-z0-9_-]*)[\s\]]/i', $content, $m)) {
            return false;
        }
        foreach ($m[1] as $tag) {
            if (strtolower($tag) !== 'carve') {
                return true;
            }
        }

        return false;
    }
}
