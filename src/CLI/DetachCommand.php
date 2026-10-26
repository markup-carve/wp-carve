<?php

declare(strict_types=1);

namespace WpCarve\CLI;

if (!defined('ABSPATH')) {
    exit;
}

use WP_CLI;
use WpCarve\Blocks\DeactivationConverter;

final class DetachCommand
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
        $converter = new DeactivationConverter();
        $posts = 0;
        $blocks = 0;
        foreach ($ids as $id) {
            $result = $converter->convertPost((int)$id);
            if ($result['blocks'] === 0) {
                continue;
            }
            $posts++;
            $blocks += $result['blocks'];
            foreach ($result['archives'] as $archive) {
                WP_CLI::log(sprintf(
                    'wp carve source --post=%d --index=%d > %s',
                    $id,
                    $archive['index'] - 1,
                    $archive['filename'],
                ));
            }
        }
        WP_CLI::success(sprintf('Converted %d block(s) in %d post(s).', $blocks, $posts));
    }
}
