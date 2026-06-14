<?php

declare(strict_types=1);

namespace WpCarve\CLI;

if (!defined('ABSPATH')) {
    exit;
}

use WP_CLI;
use WpCarve\Migration\Migrator;

/**
 * WP-CLI: migrate existing content into Carve.
 *
 *   wp carve migrate --post_type=post [--dry-run] [--force]
 *   wp carve migrate --post=123 --force
 *
 * Each post is analyzed first: posts using the block editor or non-trivial
 * shortcodes are skipped (use --force to convert anyway). Markdown vs HTML is
 * auto-detected. Converted posts are flagged to render as Carve.
 *
 * @param array<int, string> $args
 * @param array<string, string> $assoc
 */
class MigrateCommand
{
    /**
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function migrate(array $args, array $assoc): void
    {
        $migrator = new Migrator();
        $dryRun = isset($assoc['dry-run']);
        $force = isset($assoc['force']);

        $ids = isset($assoc['post'])
            ? [(int)$assoc['post']]
            : get_posts([
                'post_type' => $assoc['post_type'] ?? 'post',
                'post_status' => 'any',
                'numberposts' => -1,
                'fields' => 'ids',
            ]);

        $migrated = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            $analysis = $migrator->analyze($id);

            if (!$analysis['can_auto_migrate'] && !$force) {
                WP_CLI::log(sprintf('skip  #%d (%s): %s', $id, $analysis['source'], $analysis['reason']));
                $skipped++;

                continue;
            }

            if ($dryRun) {
                WP_CLI::log(sprintf('[dry-run] would migrate #%d from %s', $id, $analysis['source']));
                $migrated++;

                continue;
            }

            $len = $migrator->migrate($id, $force);
            if ($len === null) {
                $skipped++;

                continue;
            }
            WP_CLI::log(sprintf('migrate #%d from %s (%d chars)', $id, $analysis['source'], $len));
            $migrated++;
        }

        WP_CLI::success(sprintf('%s %d post(s), skipped %d.', $dryRun ? 'Would migrate' : 'Migrated', $migrated, $skipped));
    }
}
