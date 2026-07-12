<?php

declare(strict_types=1);

namespace WpCarve\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WpCarve\Converter;
use WpCarve\Settings;

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
        // The preview mirrors what a published comment would render, so it is
        // only meaningful when Carve comment rendering is on. When it is off
        // there is nothing to preview and no reason to expose an unauthenticated
        // renderer at all.
        if (!Settings::get('enable_comments')) {
            return new WP_REST_Response(
                ['message' => __('Comment rendering is disabled.', 'carve-markup')],
                403,
            );
        }

        // Throttle anonymous callers: the endpoint runs the full Carve pipeline
        // without authentication, so an unbounded public renderer is a cheap
        // CPU-amplification vector. Trusted editors (block-editor preview) are
        // exempt.
        if (self::isRateLimited()) {
            return new WP_REST_Response(
                ['message' => __('Too many preview requests - please slow down.', 'carve-markup')],
                429,
            );
        }

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

    /**
     * Per-IP fixed-window throttle for the public comment preview. Returns true
     * when the caller has exceeded the allowance and the request should be
     * rejected with 429.
     *
     * Users who can edit posts are trusted (they drive the block editor's own
     * preview) and are never throttled. Both the request allowance and the
     * window are filterable, so a site behind a shared-IP CDN or reverse proxy
     * can widen them - or disable the limit with a non-positive allowance.
     */
    private static function isRateLimited(): bool
    {
        if (current_user_can('edit_posts')) {
            return false;
        }

        // Requests allowed per window; a non-positive value disables the limit.
        $max = (int)apply_filters('wpcarve_preview_rate_limit', 30);
        if ($max <= 0) {
            return false;
        }
        // Window length in seconds.
        $window = max(1, (int)apply_filters('wpcarve_preview_rate_window', MINUTE_IN_SECONDS));

        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string)wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $key = 'wpcarve_pcw_' . md5($ip);
        $now = time();
        $data = get_transient($key);

        // Start a fresh fixed window when none is active or the current one has
        // lapsed. The window's expiry is anchored to its first request: counting
        // a later request never extends it, so a client below the allowance is
        // never blocked (a true fixed window, not a sliding one).
        if (!is_array($data) || !isset($data['count'], $data['reset']) || $now >= (int)$data['reset']) {
            set_transient($key, ['count' => 1, 'reset' => $now + $window], $window);

            return false;
        }
        if ((int)$data['count'] >= $max) {
            return true;
        }
        set_transient(
            $key,
            ['count' => (int)$data['count'] + 1, 'reset' => (int)$data['reset']],
            max(1, (int)$data['reset'] - $now),
        );

        return false;
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
