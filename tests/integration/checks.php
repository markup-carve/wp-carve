<?php

// No declare(strict_types) here: this file is executed via `wp eval-file`, which
// wraps it in eval() where a strict_types declaration is illegal.

/**
 * Integration checks for Carve Markup (wp-carve).
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
$carve_check('plugin constant defined', defined('WP_CARVE_VERSION'));
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

// --- the_content renders an opt-in post --------------------------------------
$postId = wp_insert_post([
    'post_title' => 'Carve integration',
    'post_status' => 'publish',
    'post_content' => "## Sub heading\n\nSome /emphasis/ and *strong* text.",
]);
update_post_meta($postId, '_wp_carve_enabled', '1');
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
