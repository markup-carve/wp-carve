<?php

declare(strict_types=1);

namespace WpCarve\Blocks;

use WP_Block_Type_Registry;
use WP_Post;

final class FallbackContent
{
    private bool $updating = false;

    public function register(): void
    {
        add_action('wp_after_insert_post', [$this, 'onSave'], 20, 1);
    }

    public function onSave(int $postId): void
    {
        if ($this->updating || wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        $this->refreshPost($postId);
    }

    public function refreshPost(int $postId): bool
    {
        $post = get_post($postId);
        if (!$post instanceof WP_Post || !has_blocks($post->post_content)) {
            return false;
        }
        $blocks = parse_blocks($post->post_content);
        $changed = false;
        $previous = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        try {
            foreach ($blocks as &$block) {
                $changed = $this->refreshBlock($block) || $changed;
            }
            unset($block);
        } finally {
            $GLOBALS['post'] = $previous;
        }
        if (!$changed) {
            return false;
        }
        $this->updating = true;
        try {
            wp_update_post(['ID' => $postId, 'post_content' => wp_slash(serialize_blocks($blocks))]);
        } finally {
            $this->updating = false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function refreshBlock(array &$block): bool
    {
        $changed = false;
        foreach ($block['innerBlocks'] ?? [] as &$child) {
            $changed = $this->refreshBlock($child) || $changed;
        }
        unset($child);
        $name = $block['blockName'] ?? '';
        if (!is_string($name) || !str_starts_with($name, 'carve/')) {
            return $changed;
        }
        $type = WP_Block_Type_Registry::get_instance()->get_registered($name);
        if (!$type || !is_callable($type->render_callback)) {
            return $changed;
        }
        $html = (string)call_user_func($type->render_callback, $block['attrs'] ?? []);
        if (($block['innerHTML'] ?? '') === $html && ($block['innerContent'] ?? []) === [$html]) {
            return $changed;
        }
        $block['innerHTML'] = $html;
        $block['innerContent'] = [$html];

        return true;
    }
}
