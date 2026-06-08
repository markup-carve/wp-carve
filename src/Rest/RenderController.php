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
            ],
        ]);
    }

    public function render(WP_REST_Request $request): WP_REST_Response
    {
        $carve = (string)$request->get_param('carve');
        $context = $request->get_param('context') === 'comment' ? 'comment' : 'post';

        return new WP_REST_Response([
            'html' => $this->converter->toHtml($carve, $context),
        ], 200);
    }
}
