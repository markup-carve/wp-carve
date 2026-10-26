<?php

declare(strict_types=1);

namespace WpCarve\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Post;

/**
 * Show Carve block source as lines in the WordPress revision comparison.
 */
final class RevisionDiff
{
    public function register(): void
    {
        add_filter('wp_get_revision_ui_diff', [$this, 'filter'], 10, 3);
    }

    /**
     * @param array<int, array{id: string, name: string, diff: string}> $fields
     * @param \WP_Post|false $from
     * @param \WP_Post $to
     *
     * @return array<int, array{id: string, name: string, diff: string}>
     */
    public function filter(array $fields, WP_Post|false $from, WP_Post $to): array
    {
        $oldContent = $from ? apply_filters('_wp_post_revision_field_post_content', $from->post_content, 'post_content', $from, 'from') : '';
        $newContent = apply_filters('_wp_post_revision_field_post_content', $to->post_content, 'post_content', $to, 'to');
        $old = $this->prepareContent((string)$oldContent);
        $new = $this->prepareContent((string)$newContent);
        if ($old['sources'] === [] && $new['sources'] === []) {
            return $fields;
        }

        $options = [
            'show_split_view' => true,
            'title_left' => __('Removed'),
            'title_right' => __('Added'),
        ];
        $options = apply_filters('revision_text_diff_options', $options, 'post_content', $from, $to);
        $contentDiff = wp_text_diff($old['content'], $new['content'], $options);
        $result = [];
        foreach ($fields as $field) {
            if ($field['id'] !== 'post_content') {
                $result[] = $field;
            } elseif ($contentDiff) {
                $field['diff'] = $contentDiff;
                $result[] = $field;
            }
        }

        $pairs = $this->sourcePairs($old['sources'], $new['sources']);
        foreach ($pairs as $index => [$before, $after]) {
            if ($before === $after) {
                continue;
            }
            $diff = wp_text_diff($before, $after, $options);
            if (!$diff) {
                continue;
            }
            $result[] = [
                'id' => 'wpcarve_source_' . ($index + 1),
                'name' => count($pairs) === 1
                    ? __('Carve source', 'carve-markup')
                    : sprintf(__('Carve source (block %d)', 'carve-markup'), $index + 1),
                'diff' => $diff,
            ];
        }

        return $result;
    }

    /**
     * Keep non-Carve blocks byte-for-byte while replacing only Carve source
     * attributes in the comparison copy. Stored revisions are never changed.
     *
     * @return array{content: string, sources: list<string>}
     */
    public function prepareContent(string $content): array
    {
        if (!str_contains($content, '<!-- wp:carve/')) {
            return ['content' => $content, 'sources' => []];
        }

        $sources = [];
        $prepared = preg_replace_callback(
            '~<!--\s+wp:carve/[a-z][a-z0-9-]*\s+(\{.*?\})\s*/?-->~s',
            static function (array $match) use (&$sources): string {
                $attributes = json_decode($match[1], true);
                if (!is_array($attributes) || !isset($attributes['carve']) || !is_string($attributes['carve'])) {
                    return $match[0];
                }
                $encoded = wp_json_encode(array_replace($attributes, ['carve' => '[Carve source shown below]']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($encoded)) {
                    return $match[0];
                }

                $sources[] = $attributes['carve'];

                return str_replace($match[1], $encoded, $match[0]);
            },
            $content,
        );

        return [
            'content' => is_string($prepared) ? $prepared : $content,
            'sources' => $sources,
        ];
    }

    /**
     * Keep unchanged blocks aligned when a block is inserted or removed.
     *
     * @param list<string> $old
     * @param list<string> $new
     *
     * @return list<array{string, string}>
     */
    public function sourcePairs(array $old, array $new): array
    {
        $oldCount = count($old);
        $newCount = count($new);
        $length = array_fill(0, $oldCount + 1, array_fill(0, $newCount + 1, 0));
        for ($i = $oldCount - 1; $i >= 0; $i--) {
            for ($j = $newCount - 1; $j >= 0; $j--) {
                $length[$i][$j] = $old[$i] === $new[$j]
                    ? 1 + $length[$i + 1][$j + 1]
                    : max($length[$i + 1][$j], $length[$i][$j + 1]);
            }
        }

        $anchors = [];
        $i = 0;
        $j = 0;
        while ($i < $oldCount && $j < $newCount) {
            if ($i < $oldCount && $j < $newCount && $old[$i] === $new[$j]) {
                $anchors[] = [$i++, $j++];
            } elseif ($length[$i][$j + 1] >= $length[$i + 1][$j]) {
                $j++;
            } else {
                $i++;
            }
        }

        $pairs = [];
        $oldStart = 0;
        $newStart = 0;
        foreach ([...$anchors, [$oldCount, $newCount]] as [$oldEnd, $newEnd]) {
            $span = max($oldEnd - $oldStart, $newEnd - $newStart);
            for ($offset = 0; $offset < $span; $offset++) {
                $pairs[] = [
                    $oldStart + $offset < $oldEnd ? $old[$oldStart + $offset] : '',
                    $newStart + $offset < $newEnd ? $new[$newStart + $offset] : '',
                ];
            }
            if ($oldEnd < $oldCount && $newEnd < $newCount) {
                $pairs[] = [$old[$oldEnd], $new[$newEnd]];
            }
            $oldStart = $oldEnd + 1;
            $newStart = $newEnd + 1;
        }

        return $pairs;
    }
}
