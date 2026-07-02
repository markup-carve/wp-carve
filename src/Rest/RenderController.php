<?php

declare(strict_types=1);

namespace WpCarve\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WpCarve\Converter;
use WpCarve\Plugin;

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
    }

    public function render(WP_REST_Request $request): WP_REST_Response
    {
        $carve = (string)$request->get_param('carve');
        $requestedContext = (string)$request->get_param('context');
        // 'editor' seeds the visual editor and omits non-round-trippable markup
        // (TOC, permalinks, ...); anything unrecognized falls back to 'post'.
        $context = in_array($requestedContext, ['comment', 'editor'], true) ? $requestedContext : 'post';
        $profile = (string)$request->get_param('profile');

        // Gate raw HTML on the requesting user's capability, not the global
        // setting, so an edit_posts user without unfiltered_html can't render
        // raw HTML through the endpoint even when safe mode is globally off.
        // When rendering an existing post the caller can edit (the block-editor
        // preview), gate on the POST AUTHOR instead - the preview then matches
        // the published output, so a reviewer can't be XSS'd by a lower-
        // privilege author's stored raw HTML.
        $postId = (int)$request->get_param('post_id');
        $safe = ($postId > 0 && current_user_can('edit_post', $postId))
            ? Plugin::safeForAuthor((int)get_post_field('post_author', $postId))
            : Plugin::safeForAuthor(get_current_user_id());

        return new WP_REST_Response([
            'html' => $this->converter->toHtml($carve, $context, $profile !== '' ? $profile : null, $safe),
        ], 200);
    }
}
