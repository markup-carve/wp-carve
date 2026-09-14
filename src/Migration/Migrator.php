<?php

declare(strict_types=1);

namespace WpCarve\Migration;

if (!defined('ABSPATH')) {
    exit;
}

use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use WP_Post;

/**
 * Migrate existing post content into Carve, with a safety analysis first.
 *
 * `analyze()` decides whether a post can be auto-migrated: it skips posts that
 * already use the block editor or carry non-trivial shortcodes, and detects
 * whether the source looks like Markdown or HTML. `migrate()` performs the
 * conversion and flags the post to render as Carve.
 */
class Migrator
{
    private MarkdownToCarve $markdown;

    private HtmlToCarve $html;

    /**
     * Reuse the assessed conversion when a batch immediately migrates it.
     *
     * @var array<int, \MarkupCarve\Carve\Converter\MigrationResult>
     */
    private array $assessments = [];

    /**
     * Reuse the safety decision made by a dry-run or bulk-list pass.
     *
     * @var array<int, array{post_id: int, source: string, can_auto_migrate: bool, reason: string, report?: array<string, mixed>}>
     */
    private array $analyses = [];

    public function __construct()
    {
        $this->markdown = new MarkdownToCarve();
        $this->html = new HtmlToCarve();
    }

    /**
     * @return array{post_id: int, source: string, can_auto_migrate: bool, reason: string, report?: array<string, mixed>}
     */
    public function analyze(int $postId): array
    {
        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            return ['post_id' => $postId, 'source' => 'none', 'can_auto_migrate' => false, 'reason' => 'Post not found.'];
        }

        $content = (string)$post->post_content;
        if (trim($content) === '') {
            return ['post_id' => $postId, 'source' => 'none', 'can_auto_migrate' => false, 'reason' => 'Empty content.'];
        }
        if (has_blocks($content)) {
            return ['post_id' => $postId, 'source' => 'blocks', 'can_auto_migrate' => false, 'reason' => 'Uses the block editor; convert manually.'];
        }
        if ($this->hasComplexShortcodes($content)) {
            return ['post_id' => $postId, 'source' => 'shortcodes', 'can_auto_migrate' => false, 'reason' => 'Contains shortcodes; convert manually.'];
        }

        $source = $this->detectMarkdown($content) ? 'markdown' : 'html';

        $result = $source === 'markdown'
            ? $this->markdown->convertWithFidelityReport($content)
            : $this->html->convertWithFidelityReport($content);
        $report = $result->report();
        $this->assessments[$postId] = $result;
        $unsafe = array_filter(
            $report['diagnostics'],
            static fn (array $item): bool => !in_array($item['fidelity'], ['preserved', 'normalized'], true),
        );

        $analysis = [
            'post_id' => $postId,
            'source' => $source,
            'can_auto_migrate' => $unsafe === [],
            'reason' => $unsafe === [] ? 'OK' : 'Migration fidelity requires review.',
            'report' => $report,
        ];
        $this->analyses[$postId] = $analysis;

        return $analysis;
    }

    /**
     * Convert and flag the post. Returns the new Carve length, or null when the
     * post cannot be auto-migrated (unless $force).
     */
    public function migrate(int $postId, bool $force = false): ?int
    {
        $analysis = $this->analyses[$postId] ?? $this->analyze($postId);
        if (!$analysis['can_auto_migrate'] && !$force) {
            $this->forgetAnalysis($postId);

            return null;
        }

        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            $this->forgetAnalysis($postId);

            return null;
        }

        $result = $this->assessments[$postId] ?? ($analysis['source'] === 'markdown'
            ? $this->markdown->convertWithFidelityReport($post->post_content)
            : $this->html->convertWithFidelityReport($post->post_content));
        $carve = $result->value;

        // wp_update_post unslashes its input; slash so Carve backslash escapes
        // (e.g. \* or math \(...\)) survive instead of being stripped.
        wp_update_post(['ID' => $postId, 'post_content' => wp_slash($carve)]);
        update_post_meta($postId, '_wpcarve_enabled', 1);
        $report = $result->report();
        if (in_array($analysis['source'], ['blocks', 'shortcodes'], true)) {
            $report['diagnostics'][] = [
                'code' => 'analysis-overridden',
                'message' => $analysis['reason'],
                'severity' => 'warning',
                'fidelity' => 'dropped',
                'confidence' => 'fallback',
            ];
        }
        update_post_meta($postId, '_wpcarve_import_report', wp_slash(wp_json_encode($report)));
        $this->forgetAnalysis($postId);

        return strlen($carve);
    }

    /**
     * Release cached dry-run data once its caller has rendered or logged it.
     */
    public function forgetAnalysis(int $postId): void
    {
        unset($this->analyses[$postId], $this->assessments[$postId]);
    }

    private function detectMarkdown(string $content): bool
    {
        // Strong Markdown signals that are not valid HTML.
        return (bool)preg_match('/^#{1,6}\s|\*\*[^*]+\*\*|^[-*+]\s|\[[^\]]+\]\([^)]+\)|^>\s/m', $content)
            && !preg_match('/^\s*<[a-z]/i', $content);
    }

    private function hasComplexShortcodes(string $content): bool
    {
        // Ignore our own [carve] tag; any other registered shortcode is "complex".
        if (!preg_match_all('/\[([a-z][a-z0-9_-]*)[\s\]]/i', $content, $m)) {
            return false;
        }
        foreach ($m[1] as $tag) {
            if (strtolower($tag) !== 'carve') {
                return true;
            }
        }

        return false;
    }
}
