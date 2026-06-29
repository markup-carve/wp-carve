<?php

declare(strict_types=1);

namespace WpCarve\Ingest;

if (!defined('ABSPATH')) {
    exit;
}

use MarkupCarve\Carve\Converter\BbcodeToCarve;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Innovation B: convert pasted Markdown / Djot / BBCode / HTML into Carve
 * source, using carve-php's source-to-source converters.
 *
 *   POST /wp-json/carve/v1/ingest { "source": "...", "from": "markdown" }
 *   -> { "carve": "..." }
 *
 * `from` = markdown | djot | bbcode | html | auto (sniffed).
 */
class PasteController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route('carve/v1', '/ingest', [
            'methods' => 'POST',
            'callback' => [$this, 'ingest'],
            'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            'args' => [
                'source' => ['type' => 'string', 'required' => true],
                'from' => ['type' => 'string', 'default' => 'auto'],
            ],
        ]);
    }

    public function ingest(WP_REST_Request $request): WP_REST_Response
    {
        $source = (string)$request->get_param('source');
        $from = (string)$request->get_param('from');
        if ($from === '' || $from === 'auto') {
            $from = $this->sniff($source);
        }

        $carve = match ($from) {
            'markdown' => (new MarkdownToCarve())->convert($source),
            'djot' => (new DjotToCarve())->convert($source),
            'bbcode' => (new BbcodeToCarve())->convert($source),
            'html' => (new HtmlToCarve())->convert($source),
            default => $source,
        };

        return new WP_REST_Response(['carve' => $carve, 'from' => $from], 200);
    }

    private function sniff(string $source): string
    {
        if (preg_match('/\[(b|i|u|url|img|code|quote)\b/i', $source)) {
            return 'bbcode';
        }
        if (preg_match('/<\/?(p|div|h[1-6]|ul|ol|li|strong|em|a|img|pre|code)\b/i', $source)) {
            return 'html';
        }
        // Markdown vs Djot: `**bold**` / `__x__` / setext `===` strongly imply
        // Markdown (Djot uses single `*`); otherwise treat as Djot.
        if (preg_match('/\*\*[^*]+\*\*|__[^_]+__|^={3,}\s*$/m', $source)) {
            return 'markdown';
        }

        return 'djot';
    }
}
