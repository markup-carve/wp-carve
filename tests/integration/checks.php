<?php

// No declare(strict_types) here: this file is executed via `wp eval-file`, which
// wraps it in eval() where a strict_types declaration is illegal.

/**
 * Integration checks for Carve Markup (wpcarve).
 *
 * Runs inside a real WordPress via `wp eval-file` (see the "WP Integration" CI
 * job), so WordPress core and the activated plugin are fully loaded. Unlike the
 * unit suite - which stubs WP functions - these exercise the actual entry points
 * (shortcode, the_content filter, blocks, REST routes, comment safe mode)
 * against a live install.
 *
 * Exits non-zero if any check fails so CI marks the job red.
 */

$carve_failures = [];

$carve_check = static function (string $name, bool $passed, string $detail = '') use (&$carve_failures): void {
    if ($passed) {
        fwrite(STDOUT, "PASS  {$name}\n");

        return;
    }
    $carve_failures[] = $name . ($detail !== '' ? " - {$detail}" : '');
    fwrite(STDOUT, "FAIL  {$name}" . ($detail !== '' ? " - {$detail}" : '') . "\n");
};

$carve_snippet = static fn (string $html): string => trim(preg_replace('/\s+/', ' ', substr($html, 0, 160)) ?? '');

// --- Plugin loaded + active ---------------------------------------------------
$carve_check('plugin constant defined', defined('WPCARVE_VERSION'));
$carve_check('shortcode registered', shortcode_exists('carve'));

// --- Shortcode renders Carve to HTML -----------------------------------------
$sc = do_shortcode('[carve]# Hello World[/carve]');
$carve_check(
    'shortcode renders heading',
    str_contains($sc, '<h1') && str_contains($sc, 'Hello World'),
    $carve_snippet($sc),
);

// --- Blocks registered --------------------------------------------------------
$registry = WP_Block_Type_Registry::get_instance();
$carve_check('carve/markup block registered', $registry->is_registered('carve/markup'));
$carve_check('carve/slides block registered', $registry->is_registered('carve/slides'));

// --- REST routes registered ---------------------------------------------------
$routes = rest_get_server()->get_routes();
$carve_check('REST render route registered', isset($routes['/carve/v1/render']));
$carve_check('REST ingest route registered', isset($routes['/carve/v1/ingest']));
$carve_check('REST comment-preview route registered', isset($routes['/carve/v1/preview-comment']));

// --- Public comment preview: gated on the comment setting ---------------------
// With Carve comment rendering off (the default), the unauthenticated endpoint
// refuses instead of running the renderer for anyone.
update_option(\WpCarve\Settings::OPTION, ['enable_comments' => false]);
$disabled_request = new WP_REST_Request('POST', '/carve/v1/preview-comment');
$disabled_request->set_param('carve', '*strong*');
$disabled_response = rest_get_server()->dispatch($disabled_request);
$carve_check('comment preview refuses when comments disabled', $disabled_response->get_status() === 403);

// Enable Carve comment rendering for the remaining preview checks.
update_option(\WpCarve\Settings::OPTION, ['enable_comments' => true]);

// --- Public comment preview renders with the comment pipeline ------------------
$preview_request = new WP_REST_Request('POST', '/carve/v1/preview-comment');
$preview_request->set_param('carve', 'Some *strong* text <script>alert(1)</script>');
$preview_response = rest_get_server()->dispatch($preview_request);
$preview_html = (string)($preview_response->get_data()['html'] ?? '');
$carve_check('comment preview renders strong', str_contains($preview_html, '<strong>strong</strong>'));
$carve_check('comment preview strips script', !str_contains($preview_html, '<script'));

// --- Public comment preview: anonymous rate limit ----------------------------
// An unauthenticated caller with a tight allowance: the request past the limit
// in the window is rejected with 429, so the public renderer cannot be abused
// as a cheap CPU-amplification vector.
$carve_prev_user = get_current_user_id();
wp_set_current_user(0);
delete_transient('wpcarve_pcw_' . md5(isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'unknown'));
$carve_rate_limit = static fn (): int => 2;
add_filter('wpcarve_preview_rate_limit', $carve_rate_limit);
$carve_preview_status = static function (): int {
    $r = new WP_REST_Request('POST', '/carve/v1/preview-comment');
    $r->set_param('carve', 'hi');

    return rest_get_server()->dispatch($r)->get_status();
};
$carve_check('preview allows the first request', $carve_preview_status() === 200);
$carve_check('preview allows up to the limit', $carve_preview_status() === 200);
$carve_check('preview throttles past the limit', $carve_preview_status() === 429);
remove_filter('wpcarve_preview_rate_limit', $carve_rate_limit);
wp_set_current_user($carve_prev_user);

// --- the_content renders an opt-in post --------------------------------------
$postId = wp_insert_post([
    'post_title' => 'Carve integration',
    'post_status' => 'publish',
    'post_content' => "## Sub heading\n\nSome /emphasis/ and *strong* text.",
]);
update_post_meta($postId, '_wpcarve_enabled', '1');
$GLOBALS['post'] = get_post($postId);
$content = apply_filters('the_content', get_post($postId)->post_content);
$carve_check(
    'the_content renders Carve for an opt-in post',
    str_contains($content, '<h2') && str_contains($content, '<em>'),
    $carve_snippet($content),
);
wp_delete_post($postId, true);

// --- Comment safe mode strips dangerous HTML ---------------------------------
if (class_exists(\WpCarve\Converter::class)) {
    $converter = new \WpCarve\Converter([]);
    $commentHtml = $converter->toHtml('Hello <script>alert(1)</script> there', 'comment');
    $carve_check(
        'comment context does not emit a raw <script>',
        !str_contains($commentHtml, '<script>alert'),
        $carve_snippet($commentHtml),
    );
}

// --- Sanitization wp_kses layer ------------------------------------------------
if (class_exists(\WpCarve\Converter::class)) {
    $converter = new \WpCarve\Converter([]);

    // Generated markup the allowlist must keep: task-list checkboxes.
    $taskHtml = $converter->toHtml("- [x] done\n- [ ] open");
    $carve_check(
        'sanitization keeps task-list checkboxes through wp_kses',
        str_contains($taskHtml, '<input') && str_contains($taskHtml, 'checkbox'),
        $carve_snippet($taskHtml),
    );

    // Raw HTML is a Full-profile capability, written with Djot's `=html` raw
    // block. There the engine emits author raw HTML verbatim (RAW_HTML_ALLOW), so
    // wp_kses is the authoritative gate that must drop scripts and event handlers.
    $fullConverter = new \WpCarve\Converter(['post_profile' => 'full']);
    $evilHtml = $fullConverter->toHtml("```=html\n<div onclick=\"alert(1)\">x</div><script>alert(2)</script>\n```");
    $carve_check(
        'full profile: wp_kses drops script tags and event-handler attributes',
        !str_contains($evilHtml, '<script') && !preg_match('/<[^>]+\son[a-z]+\s*=/i', $evilHtml),
        $carve_snippet($evilHtml),
    );

    // The positive half: safe authored HTML and its sanitized inline styling
    // survive the wp_kses gate under the Full profile.
    $rawHtml = $fullConverter->toHtml("```=html\n<div class=\"callout\" style=\"color:red\">note</div>\n```");
    $carve_check(
        'full profile: wp_kses keeps safe authored raw HTML and inline styles',
        str_contains($rawHtml, '<div') && str_contains($rawHtml, 'note') && str_contains($rawHtml, 'color'),
        $carve_snippet($rawHtml),
    );

    // The default article profile denies raw HTML at the profile level, so the
    // same block is escaped to text - no live <script> ever reaches output.
    $articleEvil = $converter->toHtml("```=html\n<script>alert(3)</script>\n```");
    $carve_check(
        'article profile: raw HTML is escaped, not rendered as a live tag',
        !str_contains($articleEvil, '<script'),
        $carve_snippet($articleEvil),
    );

    // wp_kses runs inside toHtml, so cached/pre-filter output is already clean.
    $carve_check(
        'Converter::sanitizeHtml drops disallowed attributes',
        !str_contains(\WpCarve\Converter::sanitizeHtml('<p onclick="x()">hi</p>'), 'onclick'),
    );
}

// --- kses allowlist regression guards -----------------------------------------
// The recent fixes (radio-group name for tab/code-group panels, media-embed
// iframes, and moving diagram JSON configs into a data attribute after wp_kses
// strips the <script> carrier) all depend on the custom allowlist keeping
// attributes core's `post` context drops. Guard them directly at the sanitizer
// so a future allowlist change that regresses them fails here.
$carve_check(
    'kses keeps the radio group name (tab / code-group panel switching)',
    str_contains(\WpCarve\Converter::sanitizeHtml('<input type="radio" name="grp" class="c" checked>'), 'name="grp"'),
);
$carve_check(
    'kses keeps label for/class',
    str_contains(\WpCarve\Converter::sanitizeHtml('<label for="x" class="c">t</label>'), 'for="x"'),
);
$carve_check(
    'kses keeps a media-embed iframe with allow/loading',
    (static function (): bool {
        $h = \WpCarve\Converter::sanitizeHtml('<iframe src="https://example.com/e" allow="fullscreen" loading="lazy"></iframe>');

        return str_contains($h, '<iframe') && str_contains($h, 'allow="fullscreen"') && str_contains($h, 'loading="lazy"');
    })(),
);
$carve_check(
    'kses keeps the data-carve-json diagram config attribute',
    str_contains(\WpCarve\Converter::sanitizeHtml('<div class="chart" data-carve-json="{&quot;a&quot;:1}"></div>'), 'data-carve-json'),
);

// End-to-end: a ```chart fence must survive wp_kses as a data attribute (the
// <script> carrier is stripped) and ship the accessible data-table fallback.
$chartConverter = new \WpCarve\Converter(['chart_enabled' => true]);
$chartHtml = $chartConverter->toHtml("```chart\n{\"type\":\"bar\",\"data\":{\"labels\":[\"A\"],\"datasets\":[{\"label\":\"S\",\"data\":[1]}]}}\n```");
$carve_check(
    'chart config survives kses as a data attribute, not a script',
    str_contains($chartHtml, 'data-carve-json') && !str_contains($chartHtml, '<script'),
    $carve_snippet($chartHtml),
);
$carve_check(
    'chart renders an accessible data-table fallback',
    str_contains($chartHtml, 'wpcarve-chart-data') && str_contains($chartHtml, '<table'),
    $carve_snippet($chartHtml),
);

// --- Summary ------------------------------------------------------------------
fwrite(STDOUT, "\n");
if ($carve_failures !== []) {
    fwrite(STDERR, 'Integration checks FAILED: ' . count($carve_failures) . "\n");
    foreach ($carve_failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}
fwrite(STDOUT, "All integration checks passed.\n");
