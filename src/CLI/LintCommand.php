<?php

declare(strict_types=1);

namespace WpCarve\CLI;

if (!defined('ABSPATH')) {
    exit;
}

use Throwable;
use WP_CLI;
use WP_Post;
use WpCarve\Converter;
use WpCarve\Diagrams;
use WpCarve\Plugin;

/**
 * WP-CLI: lint Carve content for render health and visual-editor round-trip caveats.
 *
 *   wp carve lint [--post_type=<type>] [--post=<id>]
 *
 * For every Carve-enabled post (the "Render as Carve" toggle or a carve/markup
 * block) it renders the source and reports:
 *
 *   - error the source threw or rendered empty (broken Carve),
 *   - notes features the Visual editor simplifies vs the front end - a table
 *              of contents, heading permalinks, diagrams or abbreviations are
 *              generated on render and are intentionally omitted from the visual
 *              editor seed so they never freeze into the post source.
 *
 * This is a read-only report; it changes nothing.
 */
class LintCommand
{
    /**
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        $converter = Converter::fromSettings();

        $ids = isset($assoc['post'])
            ? [(int)$assoc['post']]
            : get_posts([
                'post_type' => $assoc['post_type'] ?? 'any',
                'post_status' => 'any',
                'numberposts' => -1,
                'fields' => 'ids',
            ]);

        $rows = [];
        $errors = 0;
        $withNotes = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            $postObj = get_post($id);
            if (!$postObj) {
                continue;
            }
            $sources = $this->carveSources($postObj);
            if ($sources === []) {
                continue;
            }

            // Render exactly as the front end would: raw HTML is gated on the
            // post author's capability, not the site setting.
            $safe = Plugin::safeForAuthor((int)$postObj->post_author);

            $status = 'ok';
            $notes = [];
            foreach ($sources as $source) {
                try {
                    $post = $converter->toHtml($source, 'post', null, $safe);
                    $editor = $converter->toHtml($source, 'editor', null, $safe);
                } catch (Throwable $e) {
                    $status = 'error';
                    $notes[] = 'render threw: ' . $e->getMessage();

                    continue;
                }
                if (trim($source) !== '' && trim($post) === '') {
                    $status = 'error';
                    $notes[] = 'empty render';

                    continue;
                }
                if ($post !== $editor) {
                    foreach ($this->simplifiedFeatures($post) as $feature) {
                        $notes[$feature] = $feature;
                    }
                }
            }

            if ($status === 'error') {
                $errors++;
            } elseif ($notes !== []) {
                $withNotes++;
            }

            $simplifies = implode(', ', array_values($notes)) ?: '-';
            $rows[] = $id;
            WP_CLI::log(sprintf(
                '%-6s #%d  %s  [visual editor simplifies: %s]',
                strtoupper($status),
                $id,
                (string)get_the_title($id),
                $simplifies,
            ));
        }

        if ($rows === []) {
            WP_CLI::success('No Carve-enabled posts found.');

            return;
        }

        WP_CLI::success(sprintf(
            '%d Carve post(s): %d error(s), %d with visual-editor caveats.',
            count($rows),
            $errors,
            $withNotes,
        ));
    }

    /**
     * Collect every Carve source in a post: the raw content of a "Render as
     * Carve" post (the same body the `the_content` filter renders), plus the
     * `carve` attribute of each carve/markup block, including blocks nested
     * inside container blocks (Group, Columns, ...).
     *
     * @param \WP_Post $post
     *
     * @return array<int, string>
     */
    private function carveSources(WP_Post $post): array
    {
        $sources = [];
        if (get_post_meta($post->ID, '_wp_carve_enabled', true)) {
            $sources[] = (string)$post->post_content;
        }

        $this->collectBlockSources(parse_blocks((string)$post->post_content), $sources);

        return array_values(array_filter($sources, static fn (string $s): bool => trim($s) !== ''));
    }

    /**
     * Recursively collect carve/markup block sources, descending into
     * innerBlocks so nested Carve blocks are not missed.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, string> $sources
     */
    private function collectBlockSources(array $blocks, array &$sources): void
    {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'carve/markup' && isset($block['attrs']['carve'])) {
                $sources[] = (string)$block['attrs']['carve'];
            }
            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->collectBlockSources($block['innerBlocks'], $sources);
            }
        }
    }

    /**
     * Which generated features appear in the front-end render but are stripped
     * from the visual-editor seed.
     *
     * @return array<int, string>
     */
    private function simplifiedFeatures(string $postHtml): array
    {
        $found = [];
        if (str_contains($postHtml, 'class="toc"')) {
            $found[] = 'table of contents';
        }
        if (str_contains($postHtml, 'class="permalink"')) {
            $found[] = 'heading permalinks';
        }
        if (str_contains($postHtml, '<abbr')) {
            $found[] = 'abbreviations';
        }
        foreach (Diagrams::all() as $name => $diagram) {
            $class = (string)($diagram['class'] ?? $name);
            if ($class !== '' && str_contains($postHtml, 'class="' . $class . '"')) {
                $found[] = 'diagrams';

                break;
            }
        }

        return $found;
    }
}
