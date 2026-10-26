<?php

declare(strict_types=1);

namespace WpCarve\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use WpCarve\Blocks\DeactivationConverter;

final class Deactivate
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_wpcarve_detach', [$this, 'handle']);
        add_action('admin_post_wpcarve_source_download', [$this, 'download']);
    }

    public function menu(): void
    {
        add_management_page(
            __('Before you deactivate', 'carve-markup'),
            __('Before you deactivate', 'carve-markup'),
            'manage_options',
            'wpcarve-deactivate',
            [$this, 'page'],
        );
    }

    public function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__('Before you deactivate', 'carve-markup') . '</h1>';
        echo '<p>' . esc_html__('Convert Carve blocks to core HTML blocks. Each block source is kept as a private copy and can be downloaded here as a .crv file.', 'carve-markup') . '</p>';
        if (isset($_GET['converted'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect status only.
            $count = (int)$_GET['converted'];
            /* translators: %d: number of converted Carve blocks. */
            $message = sprintf(__('Converted %d Carve block(s).', 'carve-markup'), $count);
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wpcarve_detach');
        echo '<input type="hidden" name="action" value="wpcarve_detach">';
        submit_button(__('Convert Carve blocks', 'carve-markup'));
        echo '</form>';
        $posts = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'meta_key' => '_wpcarve_archived_sources',
            'numberposts' => -1,
        ]);
        if ($posts !== []) {
            echo '<h2>' . esc_html__('Source downloads', 'carve-markup') . '</h2><ul>';
            foreach ($posts as $post) {
                if (!current_user_can('edit_post', $post->ID)) {
                    continue;
                }
                $archives = get_post_meta($post->ID, '_wpcarve_archived_sources', true);
                if (!is_array($archives)) {
                    continue;
                }
                foreach ($archives as $index => $archive) {
                    $url = wp_nonce_url(
                        admin_url('admin-post.php?action=wpcarve_source_download&post=' . $post->ID . '&index=' . $index),
                        'wpcarve_source_download_' . $post->ID . '_' . $index,
                    );
                    echo '<li><a href="' . esc_url($url) . '" download>' . esc_html($archive['filename']) . '</a></li>';
                }
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    public function download(): void
    {
        $id = isset($_GET['post']) ? (int)$_GET['post'] : 0;
        $index = isset($_GET['index']) ? (int)$_GET['index'] : -1;
        check_admin_referer('wpcarve_source_download_' . $id . '_' . $index);
        if (!$id || !current_user_can('edit_post', $id)) {
            wp_die(esc_html__('You cannot download this source.', 'carve-markup'));
        }
        $archives = get_post_meta($id, '_wpcarve_archived_sources', true);
        if (!is_array($archives) || !isset($archives[$index])) {
            wp_die(esc_html__('Source copy not found.', 'carve-markup'));
        }
        $archive = $archives[$index];
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($archive['filename']) . '"');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw Carve source is streamed as a text/plain download.
        echo $archive['source'];
        exit;
    }

    public function handle(): void
    {
        check_admin_referer('wpcarve_detach');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot convert these posts.', 'carve-markup'));
        }
        $converter = new DeactivationConverter();
        $count = 0;
        $ids = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);
        foreach ($ids as $id) {
            if (!current_user_can('edit_post', (int)$id)) {
                continue;
            }
            $result = $converter->convertPost((int)$id);
            $count += $result['blocks'];
        }
        wp_safe_redirect(admin_url('tools.php?page=wpcarve-deactivate&converted=' . $count));
        exit;
    }
}
