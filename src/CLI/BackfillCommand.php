<?php

declare(strict_types=1);

namespace WpCarve\CLI;

if (!defined('ABSPATH')) {
    exit;
}

use WP_CLI;
use WpCarve\Blocks\FallbackContent;

final class BackfillCommand
{
    /**
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        $ids = isset($assoc['post']) ? [(int)$assoc['post']] : get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);
        $updated = 0;
        $fallback = new FallbackContent();
        foreach ($ids as $id) {
            if ($fallback->refreshPost((int)$id)) {
                $updated++;
            }
        }
        WP_CLI::success(sprintf('Updated %d post(s).', $updated));
    }
}
