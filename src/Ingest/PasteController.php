<?php

declare(strict_types=1);

namespace WpCarve\Ingest;

if (!defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;
use WP_REST_Response;

/**
 * Innovation B: convert pasted Markdown / Djot / HTML into Carve source with
 * the carve-php importer.
 *
 *   POST /wp-json/carve/v1/ingest { "source": "...", "from": "markdown" }
 *   -> { "carve": "..." }
 *
 * `from` = markdown | djot | html | auto (sniffed). BBCode (given or sniffed)
 * answers 422 with `browser: true`: the block editor converts it with carve-js.
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
        // Bound the work: converting an arbitrarily large paste is a needless
        // memory/CPU cost even for an authenticated editor. 512 KB is far above
        // any realistic paste, so reject rather than silently truncate (which
        // would corrupt the converted source mid-document).
        if (strlen($source) > 512000) {
            return new WP_REST_Response(
                ['message' => __('The pasted content is too large to convert.', 'carve-markup')],
                413,
            );
        }
        $from = (string)$request->get_param('from');
        if ($from === '' || $from === 'auto') {
            $from = $this->sniff($source);
        }
        if (!in_array($from, ['markdown', 'djot', 'bbcode', 'html'], true)) {
            return new WP_REST_Response(['carve' => $source, 'from' => $from, 'report' => null], 200);
        }

        try {
            $result = Importer::import($from, $source);
        } catch (ImporterUnavailable $e) {
            return new WP_REST_Response(['message' => $e->getMessage(), 'from' => $from, 'browser' => $e->inBrowser()], 422);
        }

        return new WP_REST_Response([
            'carve' => $result['value'],
            'from' => $from,
            'report' => $result['report'],
        ], 200);
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
