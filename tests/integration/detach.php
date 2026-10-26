<?php

// Run with wp eval-file against an active Carve plugin.
$firstSource = "# First source\nBackslash \\ value";
$id = wp_insert_post([
    'post_title' => 'Carve detach fixture',
    'post_status' => 'draft',
    'post_content' => wp_slash(serialize_blocks([
        ['blockName' => 'carve/markup', 'attrs' => ['carve' => $firstSource], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []],
        ['blockName' => 'carve/admonition', 'attrs' => ['carve' => "::: note\nSecond source\n:::"], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []],
    ])),
]);
$result = (new \WpCarve\Blocks\DeactivationConverter())->convertPost($id);
if ($result['blocks'] !== 2 || count($result['archives']) !== 2) {
    throw new RuntimeException('Expected two converted blocks and two source files');
}
$post = get_post($id);
$blocks = parse_blocks($post->post_content);
if ($blocks[0]['blockName'] !== 'core/html' || $blocks[1]['blockName'] !== 'core/html') {
    throw new RuntimeException('Carve blocks were not converted');
}
if (!str_contains($post->post_content, 'First source') || !str_contains($post->post_content, 'Second source')) {
    throw new RuntimeException('Rendered HTML missing');
}
$stored = get_post_meta($id, '_wpcarve_archived_sources', true);
if ($stored[0]['source'] !== $firstSource || $stored[1]['source'] !== "::: note\nSecond source\n:::") {
    throw new RuntimeException('Archived source differs from the block attributes');
}
if ($stored[0]['filename'] !== $result['archives'][0]['filename'] || !str_ends_with($stored[0]['filename'], '.crv')) {
    throw new RuntimeException('Source copy name is not downloadable as Carve');
}
if ((new \WpCarve\Blocks\DeactivationConverter())->convertPost($id)['blocks'] !== 0) {
    throw new RuntimeException('Conversion is not idempotent');
}
wp_delete_post($id, true);
echo "PASS Carve blocks convert to HTML and retain source downloads\n";
