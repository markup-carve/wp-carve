<?php

declare(strict_types=1);

namespace WpCarve\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use RuntimeException;
use WP_Post;

final class DeactivationConverter
{
    /**
     * @throws \RuntimeException
     *
     * @return array{blocks: int, archives: list<array{filename: string, index: int}>}
     */
    public function convertPost(int $postId): array
    {
        $post = get_post($postId);
        if (!$post instanceof WP_Post || !has_blocks($post->post_content)) {
            return ['blocks' => 0, 'archives' => []];
        }
        $blocks = parse_blocks($post->post_content);
        $sources = [];
        $previous = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        try {
            $converted = $this->convertBlocks($blocks, static function (array $block): string {
                return render_block($block);
            }, $sources);
        } finally {
            $GLOBALS['post'] = $previous;
        }
        if ($sources === []) {
            return ['blocks' => 0, 'archives' => []];
        }
        $archives = [];
        $original = get_post_meta($postId, '_wpcarve_archived_sources', true);
        $existing = $original;
        if (!is_array($existing)) {
            $existing = [];
        }
        $slug = $post->post_name !== '' ? $post->post_name : 'carve-' . $postId;
        foreach ($sources as $source) {
            $index = count($existing) + 1;
            $filename = sanitize_file_name($slug . '-' . $postId . '-block-' . $index . '.crv');
            $existing[] = ['filename' => $filename, 'source' => $source];
            $archives[] = ['filename' => $filename, 'index' => $index];
        }
        if (!update_post_meta($postId, '_wpcarve_archived_sources', wp_slash($existing))) {
            throw new RuntimeException('Could not save the Carve source copies.');
        }
        $result = wp_update_post([
            'ID' => $postId,
            'post_content' => wp_slash(serialize_blocks($converted)),
        ], true);
        $error = is_wp_error($result) ? $result->get_error_message() : (!$result ? 'Could not update the post.' : '');
        if ($error !== '') {
            if (is_array($original)) {
                update_post_meta($postId, '_wpcarve_archived_sources', wp_slash($original));
            } else {
                delete_post_meta($postId, '_wpcarve_archived_sources');
            }

            throw new RuntimeException(esc_html($error));
        }

        return ['blocks' => count($sources), 'archives' => $archives];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param callable(array<string, mixed>): string $render
     * @param list<string> $sources
     *
     * @return list<array<string, mixed>>
     */
    public function convertBlocks(array $blocks, callable $render, array &$sources): array
    {
        foreach ($blocks as &$block) {
            if (is_array($block['innerBlocks'] ?? null)) {
                $block['innerBlocks'] = $this->convertBlocks($block['innerBlocks'], $render, $sources);
            }
            $name = $block['blockName'] ?? null;
            if (!is_string($name) || !str_starts_with($name, 'carve/')) {
                continue;
            }
            $html = $render($block);
            $sources[] = (string)($block['attrs']['carve'] ?? '');
            $block = [
                'blockName' => 'core/html',
                'attrs' => [],
                'innerBlocks' => [],
                'innerHTML' => $html,
                'innerContent' => [$html],
            ];
        }
        unset($block);

        return $blocks;
    }
}
