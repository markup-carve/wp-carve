<?php

declare(strict_types=1);

namespace WpCarve\CLI;

if (!defined('ABSPATH')) {
    exit;
}

use Carve\Converter\HtmlToCarve;
use Carve\Converter\MarkdownToCarve;
use WP_CLI;

/**
 * WP-CLI: migrate existing content into Carve.
 *
 *   wp carve migrate --post_type=post --from=html [--dry-run]
 *   wp carve migrate --from=markdown --post=123
 *
 * Converts post_content (HTML or Markdown) to Carve source and flags the post
 * to render as Carve (`_wp_carve_enabled`). Use --dry-run to preview counts.
 */
class MigrateCommand
{
    /**
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function migrate(array $args, array $assoc): void
    {
        $from = $assoc['from'] ?? 'html';
        $dryRun = isset($assoc['dry-run']);
        $converter = $from === 'markdown' ? new MarkdownToCarve() : new HtmlToCarve();

        if (isset($assoc['post'])) {
            $ids = [(int)$assoc['post']];
        } else {
            $ids = get_posts([
                'post_type' => $assoc['post_type'] ?? 'post',
                'post_status' => 'any',
                'numberposts' => -1,
                'fields' => 'ids',
            ]);
        }

        $count = 0;
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post || trim($post->post_content) === '') {
                continue;
            }
            $carve = $converter->convert($post->post_content);

            if ($dryRun) {
                WP_CLI::log(sprintf('[dry-run] #%d (%d -> %d chars)', $id, strlen($post->post_content), strlen($carve)));
                $count++;

                continue;
            }

            wp_update_post(['ID' => $id, 'post_content' => $carve]);
            update_post_meta($id, '_wp_carve_enabled', 1);
            $count++;
        }

        WP_CLI::success(sprintf('%s %d post(s) from %s to Carve.', $dryRun ? 'Would migrate' : 'Migrated', $count, $from));
    }
}
