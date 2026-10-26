<?php

// Run with wp eval-file against an active Carve plugin.
$fallback_check = static function (string $name, bool $passed): void {
    if (!$passed) {
        throw new RuntimeException($name);
    }
    fwrite(STDOUT, "PASS {$name}\n");
};

// Saved HTML remains available to WordPress when Carve is inactive.
$fallback_id = wp_insert_post([
    'post_title' => 'Carve fallback fixture',
    'post_content' => serialize_block([
        'blockName' => 'carve/markup',
        'attrs' => ['carve' => '# Saved fallback'],
        'innerBlocks' => [],
        'innerHTML' => '',
        'innerContent' => [],
    ]),
    'post_status' => 'draft',
]);
$fallback_post = get_post($fallback_id);
$fallback_check('save stores rendered block HTML', str_contains($fallback_post->post_content, 'Saved fallback') && str_contains($fallback_post->post_content, '<h1'));
$first_fallback = $fallback_post->post_content;
wp_update_post(['ID' => $fallback_id, 'post_content' => $first_fallback]);
$fallback_check('resave keeps current fallback', get_post($fallback_id)->post_content === $first_fallback);
wp_delete_post($fallback_id, true);
