<?php

declare(strict_types=1);

namespace WpCarve\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Post;

/**
 * Per-post "Render this post as Carve" toggle (meta `_wpcarve_enabled`).
 *
 * When on, the whole post_content is treated as Carve source: the_content
 * renders it, RenderCache caches it, and FrontmatterMeta maps its frontmatter.
 * Registered as REST-visible meta plus a classic metabox so it works in both
 * the block and classic editors.
 */
class PostMode
{
    public function register(): void
    {
        add_action('init', [$this, 'registerMeta']);
        add_action('add_meta_boxes', [$this, 'metaBox']);
        add_action('save_post', [$this, 'save'], 5);
    }

    public function registerMeta(): void
    {
        foreach (get_post_types(['public' => true]) as $type) {
            register_post_meta($type, '_wpcarve_enabled', [
                'type' => 'boolean',
                'single' => true,
                'show_in_rest' => true,
                // Per-object: only someone who can edit THIS post may flip its
                // Carve flag over REST (not just anyone with edit_posts).
                'auth_callback' => static fn (bool $allowed, string $metaKey, int $postId): bool => current_user_can('edit_post', $postId),
            ]);
        }
    }

    public function metaBox(): void
    {
        add_meta_box(
            'wpcarve-mode',
            __('Carve', 'carve-markup'),
            [$this, 'renderBox'],
            null,
            'side',
            'high',
        );
    }

    public function renderBox(WP_Post $post): void
    {
        wp_nonce_field('wpcarve_mode', 'wpcarve_mode_nonce');
        $on = (bool)get_post_meta($post->ID, '_wpcarve_enabled', true);
        printf(
            '<label><input type="checkbox" name="wpcarve_enabled" value="1" %s> %s</label>',
            checked($on, true, false),
            esc_html__('Render this post as Carve markup', 'carve-markup'),
        );
    }

    public function save(int $postId): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        // Only act when our metabox was actually submitted (classic editor).
        if (!isset($_POST['wpcarve_mode_nonce'])) {
            return;
        }
        if (!wp_verify_nonce(sanitize_key((string)$_POST['wpcarve_mode_nonce']), 'wpcarve_mode')) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        if (!empty($_POST['wpcarve_enabled'])) {
            update_post_meta($postId, '_wpcarve_enabled', 1);
        } else {
            delete_post_meta($postId, '_wpcarve_enabled');
        }
    }
}
