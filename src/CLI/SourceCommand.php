<?php

declare(strict_types=1);

namespace WpCarve\CLI;

if (!defined('ABSPATH')) {
    exit;
}

use WP_CLI;

final class SourceCommand
{
    /**
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        if (!isset($assoc['post'], $assoc['index'])) {
            WP_CLI::error('Pass --post=<id> and --index=<number>.');
        }
        $id = (int)$assoc['post'];
        $index = (int)$assoc['index'];
        $archives = get_post_meta($id, '_wpcarve_archived_sources', true);
        if (!is_array($archives) || !isset($archives[$index])) {
            WP_CLI::error('Source copy not found.');
        }
        fwrite(STDOUT, $archives[$index]['source']);
    }
}
