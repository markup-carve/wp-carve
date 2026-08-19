<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Post;

/**
 * Optional public attribution and lossless access to a post's Carve source.
 */
class SourceFooter
{
    /**
     * @var string
     */
    public const PROJECT_URL = 'https://markup-carve.github.io/carve/';

    /**
     * @var string
     */
    public const WORDPRESS_PLUGIN_URL = 'https://wordpress.org/plugins/carve-markup/';

    public function register(): void
    {
        add_filter('the_content', [$this, 'append'], 20);
        add_action('template_redirect', [$this, 'download']);
    }

    public function append(string $content): string
    {
        if (!is_singular() || !in_the_loop() || !is_main_query() || is_feed()) {
            return $content;
        }
        $post = get_post();
        if (!$post instanceof WP_Post || !$this->isCarve($post) || !$this->isPublic($post)) {
            return $content;
        }

        $attribution = $this->attributionEnabled($post);
        $mode = $this->sourceMode($post);
        if (!$attribution && $mode === 'none') {
            return $content;
        }

        $parts = [];
        if ($attribution) {
            $parts[] = sprintf(
                '<span class="wpcarve-attribution">%s <a href="%s">Carve</a></span>',
                esc_html__('Written with', 'carve-markup'),
                esc_url(self::PROJECT_URL),
            );
            if (Settings::get('attribution_plugin_link')) {
                $parts[] = sprintf(
                    '<a class="wpcarve-plugin-link" href="%s">%s</a>',
                    esc_url(self::WORDPRESS_PLUGIN_URL),
                    esc_html__('Carve WordPress plugin', 'carve-markup'),
                );
            }
        }
        if ($mode === 'download' || $mode === 'both') {
            $parts[] = sprintf(
                '<a class="wpcarve-source-download" href="%s">%s</a>',
                esc_url($this->downloadUrl($post)),
                esc_html__('Download .crv', 'carve-markup'),
            );
        }

        $footer = '<footer class="wpcarve-meta" aria-label="' . esc_attr__('Carve source', 'carve-markup') . '">';
        if ($parts !== []) {
            $footer .= '<p>' . implode('<span aria-hidden="true"> · </span>', $parts) . '</p>';
        }
        if ($mode === 'view' || $mode === 'both') {
            $footer .= '<details class="wpcarve-source-details"><summary>'
                . esc_html__('View .crv source', 'carve-markup')
                . '</summary><pre><code>' . esc_html($this->source($post)) . '</code></pre></details>';
        }
        $footer .= '</footer>';

        return $content . (string)apply_filters('wpcarve_source_footer_html', $footer, $post, $mode);
    }

    public function download(): void
    {
        $postId = isset($_GET['wpcarve_source']) ? absint($_GET['wpcarve_source']) : 0;
        if ($postId < 1) {
            return;
        }
        $post = get_post($postId);
        if (!$post instanceof WP_Post || !$this->isCarve($post) || !$this->isPublic($post)) {
            status_header(404);
            exit;
        }
        $mode = $this->sourceMode($post);
        if ($mode !== 'download' && $mode !== 'both') {
            status_header(404);
            exit;
        }

        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $this->filename($post) . '"');
        echo $this->source($post); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intentional plain-text download.
        exit;
    }

    public function source(WP_Post $post): string
    {
        if (get_post_meta($post->ID, '_wpcarve_enabled', true)) {
            return (string)$post->post_content;
        }

        return Plugin::carveFromBlocks((string)$post->post_content);
    }

    public function sourceMode(WP_Post $post): string
    {
        $override = (string)get_post_meta($post->ID, '_wpcarve_source_access', true);
        $mode = in_array($override, ['none', 'view', 'download', 'both'], true)
            ? $override
            : (string)Settings::get('source_access');

        return in_array($mode, ['view', 'download', 'both'], true) ? $mode : 'none';
    }

    private function attributionEnabled(WP_Post $post): bool
    {
        $override = (string)get_post_meta($post->ID, '_wpcarve_attribution', true);
        if ($override === 'show') {
            return true;
        }
        if ($override === 'hide') {
            return false;
        }

        return (bool)Settings::get('attribution_enabled');
    }

    private function isCarve(WP_Post $post): bool
    {
        return (bool)get_post_meta($post->ID, '_wpcarve_enabled', true)
            || has_block('carve/markup', $post);
    }

    private function isPublic(WP_Post $post): bool
    {
        return $post->post_status === 'publish' && $post->post_password === '';
    }

    private function downloadUrl(WP_Post $post): string
    {
        return add_query_arg('wpcarve_source', (string)$post->ID, get_permalink($post));
    }

    private function filename(WP_Post $post): string
    {
        $slug = $post->post_name !== '' ? $post->post_name : sanitize_title($post->post_title);

        return ($slug !== '' ? $slug : 'carve-source') . '.crv';
    }
}
