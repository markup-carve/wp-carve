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
$carve_check('carve/admonition block registered', $registry->is_registered('carve/admonition'));
$carve_check('carve/code-group block registered', $registry->is_registered('carve/code-group'));
$carve_check('carve/table-spans block registered', $registry->is_registered('carve/table-spans'));
$carve_check(
    'native blocks carry the shared stylesheet',
    in_array('wpcarve', $registry->get_registered('carve/admonition')?->style_handles ?? [], true),
);

$native = render_block([
    'blockName' => 'carve/admonition',
    'attrs' => ['carve' => "::: note\nNative block\n:::\n"],
    'innerBlocks' => [],
    'innerHTML' => '',
    'innerContent' => [],
]);
$carve_check('native Carve block renders its source', str_contains($native, 'Native block'));

$native_code_group = render_block([
    'blockName' => 'carve/code-group',
    'attrs' => ['carve' => "::: code-group\n``` php\n\$a = 1;\n```\n\n``` js\nconst a = 1;\n```\n:::\n"],
    'innerBlocks' => [],
    'innerHTML' => '',
    'innerContent' => [],
]);
$carve_check('native code group renders tab panels', str_contains($native_code_group, 'code-group-panel'));

$native_table = render_block([
    'blockName' => 'carve/table-spans',
    'attrs' => ['carve' => "|= Heading |= Value |\n| Group | First |\n| ^ | Second |\n| Wide | < |\n"],
    'innerBlocks' => [],
    'innerHTML' => '',
    'innerContent' => [],
]);
$carve_check(
    'native span table renders both span directions',
    str_contains($native_table, 'rowspan=') && str_contains($native_table, 'colspan='),
);

$native_serialized = serialize_block([
    'blockName' => 'carve/admonition',
    'attrs' => ['carve' => "::: tip\nKept source\n:::\n"],
    'innerBlocks' => [],
    'innerHTML' => '',
    'innerContent' => [],
]);
$native_post_id = wp_insert_post([
    'post_title' => 'Native Carve integration fixture',
    'post_content' => $native_serialized,
    'post_status' => 'draft',
]);
$native_post = get_post($native_post_id);
$carve_check(
    'native-only posts are detected as Carve',
    $native_post instanceof WP_Post && \WpCarve\Plugin::postHasCarveBlock($native_post),
);
wp_delete_post($native_post_id, true);
$carve_check(
    'native source extraction is lossless',
    \WpCarve\Plugin::carveFromBlocks($native_serialized) === "::: tip\nKept source\n:::",
);
$nested_native = serialize_block([
    'blockName' => 'core/group',
    'attrs' => [],
    'innerBlocks' => [[
        'blockName' => 'carve/admonition',
        'attrs' => ['carve' => "::: warning\nNested source\n:::\n"],
        'innerBlocks' => [],
        'innerHTML' => '',
        'innerContent' => [],
    ]],
    'innerHTML' => '',
    'innerContent' => [null],
]);
$mixed_nested = $native_serialized . "\n" . $nested_native;
$carve_check(
    'source extraction includes nested native blocks',
    str_contains(\WpCarve\Plugin::carveFromBlocks($mixed_nested), 'Nested source'),
);

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

// --- Deterministic REST / UGC / embed fuzz battery ---------------------------
// Generated combinations keep this cheap enough for every supported WordPress
// matrix cell while exercising malformed delimiters, controls, raw markup,
// URL schemes, and embed-shaped input through the real REST dispatcher.
$carve_fuzz_user = get_current_user_id();
wp_set_current_user(1);
$carve_atoms = [
    '<script>alert(1)</script>', '<img src=x onerror=alert(1)>',
    '[x](javascript:alert(1))', ':media[javascript:alert(1)]',
    ':youtube[../../etc/passwd]', "\0\x1f", '```=html', '{{{{{{',
];
for ($i = 0; $i < 64; $i++) {
    $payload = $carve_atoms[$i % count($carve_atoms)]
        . str_repeat(['*', '_', '`', ':', '[', '<'][$i % 6], $i % 23)
        . $carve_atoms[intdiv($i, count($carve_atoms)) % count($carve_atoms)]
        . "\n\nfuzz-marker-{$i}";
    $request = new WP_REST_Request('POST', '/carve/v1/render');
    $request->set_param('carve', $payload);
    $request->set_param('context', $i % 2 === 0 ? 'comment' : 'post');
    $response = rest_get_server()->dispatch($request);
    $render_data = $response->get_data();
    $html = (string)($render_data['html'] ?? '');
    $safe = $response->get_status() === 200
        && array_key_exists('html', $render_data)
        && str_contains($html, "fuzz-marker-{$i}")
        && !preg_match('/<(?:script|iframe|object|embed)\b|<[a-z][^>]*(?:\s|\/)on[a-z]+\s*=|\bsrcdoc\s*=|(?:href|src|formaction)=["\']?(?:javascript:|data:text\/html)/i', $html);
    $carve_check("REST fuzz {$i}", $safe, $carve_snippet($html));

    $ingest = new WP_REST_Request('POST', '/carve/v1/ingest');
    $ingest->set_param('source', $payload);
    $ingest->set_param('from', ['auto', 'markdown', 'djot', 'bbcode', 'html'][$i % 5]);
    $ingested = rest_get_server()->dispatch($ingest);
    $data = $ingested->get_data();
    $carve_check("ingest fuzz {$i}", $ingested->get_status() === 200 && is_string($data['carve'] ?? null));
}

$oversized_render = new WP_REST_Request('POST', '/carve/v1/render');
$oversized_render->set_param('carve', str_repeat('x', 1000001));
$carve_check(
    'REST render rejects oversized work',
    rest_get_server()->dispatch($oversized_render)->get_status() === 413,
);
$small_render_limit = static fn (): int => 12;
add_filter('wpcarve_render_max_bytes', $small_render_limit);
$filtered_render = new WP_REST_Request('POST', '/carve/v1/render');
$filtered_render->set_param('carve', str_repeat('x', 13));
$carve_check('REST render limit is filterable', rest_get_server()->dispatch($filtered_render)->get_status() === 413);
remove_filter('wpcarve_render_max_bytes', $small_render_limit);
wp_set_current_user($carve_fuzz_user);

// The unauthenticated comment endpoint gets the same hostile grammar shapes.
$disable_fuzz_limit = static fn (): int => 0;
add_filter('wpcarve_preview_rate_limit', $disable_fuzz_limit);
for ($i = 0; $i < 16; $i++) {
    $request = new WP_REST_Request('POST', '/carve/v1/preview-comment');
    $request->set_param('carve', $carve_atoms[$i % count($carve_atoms)] . str_repeat(':', $i));
    $response = rest_get_server()->dispatch($request);
    $html = (string)($response->get_data()['html'] ?? '');
    $carve_check(
        "public UGC fuzz {$i}",
        $response->get_status() === 200
            && !preg_match('/<(?:script|iframe|object|embed)\b|<[a-z][^>]*(?:\s|\/)on[a-z]+\s*=|\bsrcdoc\s*=|(?:href|src|formaction)=["\']?(?:javascript:|data:text\/html)/i', $html),
        $carve_snippet($html),
    );
}
remove_filter('wpcarve_preview_rate_limit', $disable_fuzz_limit);

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
$documentEditor = new \WpCarve\Admin\PostEditor(new \WpCarve\Converter([]));
$carve_check('Carve Documents use the dedicated source editor', $documentEditor->forceClassic(true, get_post($postId)) === false);
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

// End-to-end: a highlighted `{.diff}` fence must keep BOTH the token spans and
// the diff row classes through wp_kses. toHtml() sanitizes on the way out, so
// anything missing here was dropped by the real filter, not by the extension.
if (class_exists(\Torchlight\Engine\Engine::class)) {
    $carve_diff_converter = new \WpCarve\Converter([
        'torchlight_enabled' => true,
        'torchlight_theme' => 'github-light',
    ]);
    $carve_diff_html = $carve_diff_converter->toHtml("{.diff}\n``` php\n- old();\n+ fresh();\n```\n");
    $carve_check(
        'kses keeps the diff row classes on a highlighted fence',
        str_contains($carve_diff_html, 'has-diff')
            && str_contains($carve_diff_html, 'line diff add')
            && str_contains($carve_diff_html, 'line diff remove')
            && str_contains($carve_diff_html, 'class="diff-marker"')
            && str_contains($carve_diff_html, 'phiki language-php'),
        $carve_snippet($carve_diff_html),
    );
}

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

// --- Paste ingest: converts, and caps oversize input --------------------------
// The ingest route needs edit_posts; run it as the admin user.
$carve_prev_ingest_user = get_current_user_id();
wp_set_current_user(1);

$ingest_ok = new WP_REST_Request('POST', '/carve/v1/ingest');
$ingest_ok->set_param('source', '# Hello world');
$ingest_ok->set_param('from', 'markdown');
$ingest_ok_res = rest_get_server()->dispatch($ingest_ok);
$carve_check(
    'ingest converts a small Markdown paste',
    $ingest_ok_res->get_status() === 200 && str_contains((string)($ingest_ok_res->get_data()['carve'] ?? ''), 'Hello world'),
    $carve_snippet((string)($ingest_ok_res->get_data()['carve'] ?? '')),
);

$ingest_big = new WP_REST_Request('POST', '/carve/v1/ingest');
$ingest_big->set_param('source', str_repeat('a', 512001));
$ingest_big_res = rest_get_server()->dispatch($ingest_big);
$carve_check('ingest rejects an oversize paste with 413', $ingest_big_res->get_status() === 413);

wp_set_current_user($carve_prev_ingest_user);

// --- Include directives: the unfiltered_html gate ------------------------------
$carve_inc_base = trailingslashit(get_temp_dir()) . 'wpcarve-includes-' . wp_generate_password(8, false);
$carve_inc_root = $carve_inc_base . '/root';
wp_mkdir_p($carve_inc_root);
file_put_contents($carve_inc_root . '/chapter.crv', "Included chapter text.\n");
file_put_contents($carve_inc_base . '/secret.crv', "Secret outside the root.\n");
$carve_inc_settings = get_option(\WpCarve\Settings::OPTION, []);
update_option(\WpCarve\Settings::OPTION, ['include_root' => $carve_inc_root] + (is_array($carve_inc_settings) ? $carve_inc_settings : []));
$carve_inc_prev_user = get_current_user_id();
$carve_inc_author = wp_insert_user([
    'user_login' => 'carve-inc-author-' . wp_generate_password(6, false),
    'user_pass' => wp_generate_password(),
    'role' => 'author',
]);
$carve_inc_render = static function (int $id): string {
    update_post_meta($id, '_wpcarve_enabled', '1');
    $GLOBALS['post'] = get_post($id);
    setup_postdata($GLOBALS['post']);

    return (string)apply_filters('the_content', get_post($id)->post_content);
};

wp_set_current_user(1);
$carve_inc_admin_post = wp_insert_post(['post_title' => 'Trusted include', 'post_status' => 'publish', 'post_content' => "{{ chapter.crv }}\n\n{{ ../secret.crv }}\n"]);
$carve_check('an unfiltered_html save stores the trust bit', get_post_meta($carve_inc_admin_post, '_wpcarve_include_trusted', true) === '1');
$carve_inc_html = $carve_inc_render($carve_inc_admin_post);
$carve_check('a trusted post expands its include', str_contains($carve_inc_html, 'Included chapter text.'), $carve_snippet($carve_inc_html));
$carve_check('traversal out of the root stays unread', !str_contains($carve_inc_html, 'Secret outside the root.'), $carve_snippet($carve_inc_html));
$carve_check('no server path reaches the rendered post', !str_contains($carve_inc_html, $carve_inc_base));

$carve_check(
    'a direct meta write of the trust bit is refused',
    update_post_meta($carve_inc_admin_post, '_wpcarve_include_trusted', 'x') === false
        && get_post_meta($carve_inc_admin_post, '_wpcarve_include_trusted', true) === '1',
);

wp_set_current_user((int)$carve_inc_author);
wp_update_post(['ID' => $carve_inc_admin_post, 'post_content' => "{{ chapter.crv }}\n"]);
$carve_check('a save without unfiltered_html drops the trust bit', get_post_meta($carve_inc_admin_post, '_wpcarve_include_trusted', true) === '');
$carve_inc_html = $carve_inc_render($carve_inc_admin_post);
$carve_check('the post stops expanding after that save', !str_contains($carve_inc_html, 'Included chapter text.'), $carve_snippet($carve_inc_html));

$carve_inc_rest = new WP_REST_Request('POST', '/wp/v2/posts');
$carve_inc_rest->set_param('title', 'REST include');
$carve_inc_rest->set_param('status', 'draft');
$carve_inc_rest->set_param('content', "{{ chapter.crv }}\n");
$carve_inc_rest->set_param('meta', ['_wpcarve_include_trusted' => '1']);
$carve_inc_rest_res = rest_get_server()->dispatch($carve_inc_rest);
$carve_inc_rest_id = (int)($carve_inc_rest_res->get_data()['id'] ?? 0);
// Either outcome keeps the bit off: the post is created without it, or the
// request is refused outright.
$carve_check(
    'a REST write carrying the trust bit is ignored',
    $carve_inc_rest_id > 0
        ? get_post_meta($carve_inc_rest_id, '_wpcarve_include_trusted', true) === ''
        : $carve_inc_rest_res->get_status() >= 400,
    'status ' . $carve_inc_rest_res->get_status(),
);

$carve_inc_input = wp_insert_post([
    'post_title' => 'meta_input include',
    'post_status' => 'draft',
    'post_content' => "{{ chapter.crv }}\n",
    'meta_input' => ['_wpcarve_include_trusted' => '1'],
]);
$carve_check('meta_input carrying the trust bit is ignored', get_post_meta($carve_inc_input, '_wpcarve_include_trusted', true) === '');
$carve_inc_html = $carve_inc_render($carve_inc_input);
$carve_check('the author post renders the directive literally', !str_contains($carve_inc_html, 'Included chapter text.'), $carve_snippet($carve_inc_html));

$carve_inc_preview = new WP_REST_Request('POST', '/carve/v1/render');
$carve_inc_preview->set_param('carve', "{{ chapter.crv }}\n");
$carve_check(
    'the preview does not expand for a user without unfiltered_html',
    !str_contains((string)(rest_get_server()->dispatch($carve_inc_preview)->get_data()['html'] ?? ''), 'Included chapter text.'),
);
wp_set_current_user(1);
$carve_inc_preview_data = rest_get_server()->dispatch($carve_inc_preview)->get_data();
$carve_check(
    'the preview expands for a user with unfiltered_html',
    str_contains((string)($carve_inc_preview_data['html'] ?? ''), 'Included chapter text.'),
);
$carve_inc_preview->set_param('post_id', $carve_inc_admin_post);
$carve_check(
    'an admin preview of a post last saved without unfiltered_html stays literal',
    !str_contains((string)(rest_get_server()->dispatch($carve_inc_preview)->get_data()['html'] ?? ''), 'Included chapter text.'),
);
wp_set_current_user((int)$carve_inc_author);
$carve_inc_auto_draft = wp_insert_post(['post_title' => 'Auto draft', 'post_status' => 'auto-draft', 'post_author' => (int)$carve_inc_author]);
wp_set_current_user(1);
$carve_inc_preview->set_param('post_id', $carve_inc_auto_draft);
$carve_check(
    'an auto-draft preview follows the previewing user',
    str_contains((string)(rest_get_server()->dispatch($carve_inc_preview)->get_data()['html'] ?? ''), 'Included chapter text.'),
);
wp_delete_post($carve_inc_auto_draft, true);

wp_delete_post($carve_inc_admin_post, true);
wp_delete_post($carve_inc_input, true);
if ($carve_inc_rest_id > 0) {
    wp_delete_post($carve_inc_rest_id, true);
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user((int)$carve_inc_author);
update_option(\WpCarve\Settings::OPTION, $carve_inc_settings);
wp_set_current_user($carve_inc_prev_user);

// --- img fence: the sanitized SVG data URI survives wp_kses ------------------
// `data:` is not in wp_allowed_protocols(), so without the mask in
// Converter::sanitizeHtml() kses rewrites the URI to the relative
// `image/svg+xml,%3Csvg...` and every img fence renders a 404. Only a real
// WordPress can show this - the unit suite has no wp_kses.
$carve_svg_src = "```img\n<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 10 10\">"
    . "<title>A dot</title><circle cx=\"5\" cy=\"5\" r=\"4\" fill=\"#333\"/></svg>\n```\n";
// Through render_block, not do_shortcode: shortcode content is pre-filtered and
// the raw <svg> never reaches the parser intact. The block carries its source as
// an attribute, so this is the engine plus the real wp_kses and nothing else.
$carve_svg_render = static fn (string $source): string => render_block([
    'blockName' => 'carve/markup',
    'attrs' => ['carve' => $source],
    'innerBlocks' => [],
    'innerHTML' => '',
    'innerContent' => [],
]);
$carve_svg_html = $carve_svg_render($carve_svg_src);
$carve_check(
    'img fence keeps its data: URI through wp_kses',
    str_contains($carve_svg_html, 'src="data:image/svg+xml,%3Csvg'),
    $carve_snippet($carve_svg_html),
);
$carve_check(
    'img fence emits no relative src',
    !str_contains($carve_svg_html, 'src="image/svg+xml'),
    $carve_snippet($carve_svg_html),
);
$carve_check(
    'img fence alt comes from the svg title',
    str_contains($carve_svg_html, 'alt="A dot"'),
    $carve_snippet($carve_svg_html),
);

// The mask is anchored to <img>. An iframe pointed at the same URI WOULD run
// script in the SVG document, so it must keep being stripped.
$carve_svg_iframe = \WpCarve\Converter::sanitizeHtml(
    '<iframe src="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%3E%3C%2Fsvg%3E"></iframe>',
);
$carve_check(
    'an iframe data: URI is still stripped',
    !str_contains($carve_svg_iframe, 'data:image/svg+xml'),
    $carve_snippet($carve_svg_iframe),
);

// Same for a link: nothing but an <img> src is unmasked.
$carve_svg_link = \WpCarve\Converter::sanitizeHtml(
    '<a href="data:image/svg+xml,%3Csvg%3E%3C%2Fsvg%3E">x</a>',
);
$carve_check(
    'a link data: URI is still stripped',
    !str_contains($carve_svg_link, 'data:image/svg+xml'),
    $carve_snippet($carve_svg_link),
);

// Only image/svg+xml is unmasked. The claim this change makes is a narrow one,
// so the narrowness gets pinned: any other media type on an img src stays
// stripped.
// A real svg img rides along in the same string on purpose: sanitizeHtml()
// short-circuits when the input holds no svg data URI at all, so without it the
// media-type pattern would never be the thing deciding and this check could not
// see a loosened one.
$carve_svg_other_type = \WpCarve\Converter::sanitizeHtml(
    '<img src="data:image/svg+xml,%3Csvg%3E%3C%2Fsvg%3E" alt="ok">'
    . '<img src="data:text/html,%3Cb%3Ex%3C%2Fb%3E" alt="x">',
);
$carve_check(
    'another data: media type on an img is still stripped',
    str_contains($carve_svg_other_type, 'data:image/svg+xml')
        && !str_contains($carve_svg_other_type, 'data:text/html'),
    $carve_snippet($carve_svg_other_type),
);

// Unencoded markup in the value stays stripped. Measured: what rejects this is
// the <img> tag match, which cannot span the `<` of the payload - loosening the
// value charset alone does not let it through. So the charset restriction is
// redundant defense here rather than the thing doing the work, and this check
// pins the outcome, not that one clause.
$carve_svg_raw_value = \WpCarve\Converter::sanitizeHtml(
    '<img src="data:image/svg+xml,%3Csvg%3E%3C%2Fsvg%3E" alt="ok">'
    . '<img src="data:image/svg+xml,<svg onload=alert(1)></svg>" alt="x">',
);
$carve_check(
    'a data: URI with unencoded markup is still stripped',
    str_contains($carve_svg_raw_value, 'src="data:image/svg+xml,%3Csvg')
        && !str_contains($carve_svg_raw_value, 'data:image/svg+xml,<svg'),
    $carve_snippet($carve_svg_raw_value),
);

// A scripted SVG reaches the browser inert twice over: the engine's sanitizer
// drops the <script> subtree, and an <img>-referenced SVG executes nothing.
$carve_svg_script = $carve_svg_render(
    "```img\n<svg xmlns=\"http://www.w3.org/2000/svg\"><script>alert(1)</script>"
    . "<circle cx=\"5\" cy=\"5\" r=\"4\"/></svg>\n```\n",
);
$carve_check(
    'a scripted img fence carries no script in its URI',
    str_contains($carve_svg_script, 'src="data:image/svg+xml,')
        && !str_contains(rawurldecode($carve_svg_script), '<script'),
    $carve_snippet($carve_svg_script),
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
