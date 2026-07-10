<?php

declare(strict_types=1);

namespace WpCarve\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WpCarve\Converter;

/**
 * REST API: render Carve to HTML.
 *
 *   POST /wp-json/carve/v1/render { "carve": "...", "context": "post" }
 *   -> { "html": "..." }
 *
 * Serves headless WordPress and acts as the server-side fallback for the block
 * editor's live preview when the in-browser engine is unavailable.
 */
class RenderController
{
    public function __construct(private Converter $converter)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route('carve/v1', '/render', [
            'methods' => 'POST',
            'callback' => [$this, 'render'],
            'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            'args' => [
                'carve' => ['type' => 'string', 'required' => true],
                'context' => ['type' => 'string', 'default' => 'post'],
                'profile' => ['type' => 'string', 'default' => ''],
                'post_id' => ['type' => 'integer', 'default' => 0],
            ],
        ]);

        // Public comment preview (parity with wp-djot's preview-comment): the
        // comment context renders with the comment profile and strict safe
        // mode - the pipeline built for untrusted input - and wp_kses runs on
        // every path, so the preview matches exactly what would be published.
        register_rest_route('carve/v1', '/preview-comment', [
            'methods' => 'POST',
            'callback' => [$this, 'previewComment'],
            'permission_callback' => '__return_true',
            'args' => [
                'carve' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);
    }

    public function previewComment(WP_REST_Request $request): WP_REST_Response
    {
        $carve = (string)$request->get_param('carve');
        // Same ballpark as WordPress' own 65k comment length limit, but small
        // enough that anonymous preview calls stay cheap.
        if (strlen($carve) > 20000) {
            $carve = substr($carve, 0, 20000);
        }

        return new WP_REST_Response([
            'html' => $this->converter->toHtml($carve, 'comment'),
        ], 200);
    }

    public function render(WP_REST_Request $request): WP_REST_Response
    {
        $carve = (string)$request->get_param('carve');
        $requestedContext = (string)$request->get_param('context');
        // 'editor' seeds the visual editor and omits non-round-trippable markup
        // (TOC, permalinks, ...); anything unrecognized falls back to 'post'.
        $context = in_array($requestedContext, ['comment', 'editor'], true) ? $requestedContext : 'post';
        $profile = (string)$request->get_param('profile');

        // Rendering is always sanitized (wp_kses on every path), so the preview
        // returned here matches the published output and cannot emit raw
        // script/style regardless of who requests it.
        return new WP_REST_Response([
            'html' => $this->converter->toHtml($carve, $context, $profile !== '' ? $profile : null),
        ], 200);
    }
}
