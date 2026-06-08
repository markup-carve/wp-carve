<?php

declare(strict_types=1);

namespace WpCarve\Meta;

if (!defined('ABSPATH')) {
    exit;
}

use Carve\CarveConverter;
use Carve\Extension\FrontmatterExtension;
use WP_Post;

/**
 * Innovation C: map a post's typed Carve frontmatter (---yaml / ---json /
 * ---toml) to WordPress meta and SEO fields at save time.
 *
 * Non-destructive by design: it sets the excerpt only when empty, writes SEO
 * description/canonical to common SEO plugins when present, and stores the full
 * frontmatter as `_wp_carve_frontmatter` for theme/plugin use. It never silently
 * overwrites the post title or slug.
 */
class FrontmatterMeta
{
    public function register(): void
    {
        add_action('save_post', [$this, 'onSave'], 15, 2);
    }

    public function onSave(int $postId, WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($postId) || !get_post_meta($postId, '_wp_carve_enabled', true)) {
            return;
        }

        $data = $this->extract($post->post_content);
        if ($data === []) {
            delete_post_meta($postId, '_wp_carve_frontmatter');

            return;
        }

        update_post_meta($postId, '_wp_carve_frontmatter', wp_json_encode($data));

        if (!empty($data['excerpt']) && trim((string)$post->post_excerpt) === '') {
            update_post_meta($postId, '_wp_carve_excerpt', sanitize_text_field((string)$data['excerpt']));
        }

        $description = (string)($data['description'] ?? $data['seo_description'] ?? '');
        if ($description !== '') {
            $this->writeSeoDescription($postId, sanitize_text_field($description));
        }

        if (!empty($data['canonical'])) {
            update_post_meta($postId, '_wp_carve_canonical', esc_url_raw((string)$data['canonical']));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extract(string $content): array
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);
        $converter->convert($content);

        if (!$ext->hasFrontmatter()) {
            return [];
        }

        $format = (string)$ext->getFormat();
        $raw = (string)$ext->getContent();

        if ($format === 'json') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        // yaml / yml / toml (and unknown): flat `key: value` / `key = value`.
        return $this->parseFlat($raw);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFlat(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (!preg_match('/^([A-Za-z0-9_-]+)\s*[:=]\s*(.*)$/', trim($line), $m)) {
                continue;
            }
            $value = trim($m[2], " \t\"'");
            if ($value !== '') {
                $out[strtolower($m[1])] = $value;
            }
        }

        return $out;
    }

    private function writeSeoDescription(int $postId, string $description): void
    {
        update_post_meta($postId, '_wp_carve_seo_description', $description);
        // Best-effort hand-off to common SEO plugins when active.
        if (defined('WPSEO_VERSION')) {
            update_post_meta($postId, '_yoast_wpseo_metadesc', $description);
        }
        if (defined('AIOSEO_VERSION')) {
            update_post_meta($postId, '_aioseo_description', $description);
        }
    }
}
