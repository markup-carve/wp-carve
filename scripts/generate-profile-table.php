<?php

declare(strict_types=1);

// Load the actual WordPress sanitizer before the unit bootstrap defines fallbacks.
$root = dirname(__DIR__);
require $root . '/vendor/johnpbloch/wordpress-core/wp-includes/kses.php';
require $root . '/tests/bootstrap.php';

use WpCarve\Converter;

if (!function_exists('wp_allowed_protocols')) {
    function wp_allowed_protocols(): array
    {
        return ['http', 'https', 'ftp', 'mailto', 'tel'];
    }
}

// carve-php 0.2 keeps profiles as spec tables without a public per-node API,
// so every cell is measured by rendering its sample.
$profiles = ['full', 'article', 'comment', 'minimal', 'none'];

// Each fixture is source, a rendered HTML signature, and a unique visible token.
$samples = [
    'paragraph' => ["Alpha paragraph.\n", '<p', 'Alpha paragraph'],
    'heading' => ["# Alpha heading\n", '<h1', 'Alpha heading'],
    'code_block' => ["``` php\nalpha();\n```\n", '<pre', 'alpha()'],
    'block_quote' => ["> Alpha quote\n", '<blockquote', 'Alpha quote'],
    'list' => ["- Alpha item\n", '<ul', 'Alpha item'],
    'list_item' => ["- Alpha item\n", '<li', 'Alpha item'],
    'table' => ["|= Alpha |= Beta |\n| one | two |\n", '<table', 'Alpha'],
    'table_row' => ["|= Alpha |= Beta |\n| one | two |\n", '<tr', 'Alpha'],
    'table_cell' => ["|= Alpha |= Beta |\n| one | two |\n", '<td', 'one'],
    'thematic_break' => ["***\n", '<hr', '***'],
    'div' => ["::: sidebar\nAlpha container\n:::\n", '<div', 'Alpha container'],
    'admonition' => ["::: note\nAlpha note\n:::\n", 'admonition', 'Alpha note'],
    'directive' => ["::: toc\n:::\n", 'toc', 'toc'],
    'raw_block' => ["```=html\n<div>Alpha raw</div>\n```\n", '<div', 'Alpha raw'],
    'footnote' => ["Alpha note[^one].\n\n[^one]: Alpha footnote\n", 'footnote', 'Alpha footnote'],
    'frontmatter' => ["---\ntitle: Alpha frontmatter\n---\n\nBody\n", 'frontmatter', 'Alpha frontmatter'],
    'definition_list' => [":: Alpha term\n: Alpha definition\n", '<dl', 'Alpha definition'],
    'definition_term' => [":: Alpha term\n: Alpha definition\n", '<dt', 'Alpha term'],
    'definition_description' => [":: Alpha term\n: Alpha definition\n", '<dd', 'Alpha definition'],
    'section' => ["# Alpha section\n\nAlpha body\n", '<section', 'Alpha body'],
    'line_block' => ["::: |\nAlpha line\nBeta line\n:::\n", 'line-block', 'Alpha line'],
    'comment' => ["%%%\nAlpha comment\n%%%\n", '<!--', 'Alpha comment'],
    'figure' => ["![Alpha image](https://example.test/alpha.png)\n^ Alpha caption\n", '<figure', 'Alpha caption'],
    'figure_group' => ["![Alpha image](https://example.test/alpha.png)\n^ Alpha caption\n", '<figure', 'Alpha caption'],
    'caption' => ["![Alpha image](https://example.test/alpha.png)\n^ Alpha caption\n", '<figcaption', 'Alpha caption'],
    'abbreviation_def' => ["*[HTML]: Alpha expansion\n\nHTML\n", '<abbr', 'Alpha expansion'],
    'link_reference_definition' => ["[alpha]: https://example.test/alpha\n\n[Alpha][alpha]\n", '<a', 'Alpha'],
    'citation_definition' => ["[@alpha]: {author=Alpha year=2024}\n\n[@alpha]\n", 'data-cite-key', 'Alpha'],
    'text' => ["Alpha text\n", '<p', 'Alpha text'],
    'inline_extension' => [":youtube[dQw4w9WgXcQ]\n", 'ext-youtube', 'dQw4w9WgXcQ'],
    'emphasis' => ["/Alpha emphasis/\n", '<em', 'Alpha emphasis'],
    'strong' => ["*Alpha strong*\n", '<strong', 'Alpha strong'],
    'underline' => ["{_Alpha underline_}\n", '<u', 'Alpha underline'],
    'strike' => ["{~Alpha strike~}\n", '<s', 'Alpha strike'],
    'mention' => ["@alpha\n", 'mention', '@alpha'],
    'code' => ["`Alpha code`\n", '<code', 'Alpha code'],
    'literal_inline' => ["!`Alpha literal`\n", 'Alpha literal', 'Alpha literal'],
    'link' => ["[Alpha link](https://example.test/alpha)\n", '<a', 'Alpha link'],
    'autolink' => ["<https://example.test/alpha>\n", '<a', 'example.test/alpha'],
    'image' => ["![Alpha image](https://example.test/alpha.png)\n", '<img', 'Alpha image'],
    'non_breaking_space' => ["Alpha\\ Beta\n", '&nbsp;', 'Alpha'],
    'soft_break' => ["Alpha\nBeta\n", "Alpha\nBeta", 'Alpha'],
    'hard_break' => ["Alpha\\\nBeta\n", '<br', 'Alpha'],
    'raw_inline' => ["`<span>Alpha raw</span>`{=html}\n", '<span', 'Alpha raw'],
    'escaped_text' => ["\\*Alpha escaped\\*\n", '*Alpha escaped*', 'Alpha escaped'],
    'footnote_ref' => ["Alpha[^one]\n\n[^one]: Beta footnote\n", 'footnote', 'Alpha'],
    'inline_footnote' => ["Alpha^[Beta footnote]\n", 'doc-noteref', 'Beta footnote'],
    'heading_ref' => ["# Alpha heading\n\n</#Alpha-heading>\n", '<a', 'Alpha heading'],
    'citation_group' => ["[@alpha]\n\n[@alpha]: {author=Alpha year=2024}\n", 'data-cite-key', 'Alpha'],
    'citation' => ["[@alpha]\n\n[@alpha]: {author=Alpha year=2024}\n", 'data-cite-key', 'Alpha'],
    'span' => ["[Alpha span]{.sample}\n", '<span', 'Alpha span'],
    'superscript' => ["{^Alpha super^}\n", '<sup', 'Alpha super'],
    'subscript' => ["{,Alpha sub,}\n", '<sub', 'Alpha sub'],
    'highlight' => ["{=Alpha highlight=}\n", '<mark', 'Alpha highlight'],
    'insert' => ["{+Alpha insert+}\n", '<ins', 'Alpha insert'],
    'delete' => ["{-Alpha delete-}\n", '<del', 'Alpha delete'],
    'substitution' => ["{-Alpha old-}{+Beta new+}\n", '<del', 'Alpha old'],
    'critic_comment' => ["{#Alpha critic#}\n", 'critic-comment', 'Alpha critic'],
    'math' => ["$`x+y`\n", 'math', 'x+y'],
    'abbreviation' => ["*[HTML]: Alpha expansion\n\nHTML\n", '<abbr', 'HTML'],
];

// Probe the opt-in diagram renderer separately from ordinary code samples.
$optional = new Converter(['post_profile' => 'article', 'plantuml_enabled' => true]);
$plantumlHtml = $optional->toHtml("```plantuml\nAlice -> Bob: hello\n```", 'post');
if (!str_contains($plantumlHtml, 'class="plantuml"')) {
    throw new RuntimeException('Optional network renderer behavior changed; review the request markers');
}

$types = array_keys($samples);
$rows = [];
$networkFindings = [];
foreach ($types as $type) {
    $sample = $samples[$type];
    $cells = [];
    // Some renderings enqueue local assets or use a browser-side service after conversion.
    $network = in_array($type, ['code_block', 'inline_extension', 'math', 'raw_block', 'raw_inline'], true);
    foreach ($profiles as $name) {
        [$source, $signature, $visible] = $sample;
        $html = (new Converter(['post_profile' => $name, 'comment_profile' => $name, 'plantuml_enabled' => true]))
            ->toHtml($source, $name === 'comment' ? 'comment' : 'post');
        $rendered = str_contains($html, $signature) || ($type === 'inline_extension' && str_contains($html, '<iframe'));
        $outcome = $rendered ? 'renders'
            : (str_contains(html_entity_decode(strip_tags($html)), $visible) ? 'literal' : 'stripped');
        if (preg_match('/<(?:img|iframe|script|link|video|audio|source)\b[^>]+(?:src|href)="(?:https?:)?\/\//i', $html) === 1) {
            $network = true;
            if (in_array($name, ['article', 'comment'], true)) {
                $networkFindings[] = $name . ': ' . $type;
            }
        }
        $cells[] = $outcome;
    }
    $rows[] = '| `' . $type . '` | ' . implode(' | ', $cells)
        . ' | ' . ($network ? 'Yes' : 'No') . ' |';
}

$table = "<!-- profile-capabilities:start -->\n"
    . "| Construct | `full` | `article` | `comment` | `minimal` | `none` | Browser request |\n"
    . "| --- | --- | --- | --- | --- | --- | --- |\n"
    . implode("\n", $rows) . "\n\n"
    . "Every cell is measured by rendering the construct's sample through the plugin.\n"
    . "\n<!-- profile-capabilities:end -->";
$path = $root . '/docs/profiles.md';
$document = file_get_contents($path);
if ($document === false) {
    throw new RuntimeException('Cannot read profiles guide');
}
$updated = preg_replace('/<!-- profile-capabilities:start -->.*?<!-- profile-capabilities:end -->/s', $table, $document, 1, $count);
if ($count !== 1 || $updated === null) {
    throw new RuntimeException('Expected one capability table marker pair');
}
if (in_array('--check', $argv, true)) {
    if ($updated !== $document) {
        fwrite(STDERR, "Profile table differs from generated output.\n");
        exit(1);
    }
} else {
    file_put_contents($path, $updated);
}
fwrite(STDOUT, count($rows) . ' rows; network: ' . implode(', ', array_unique($networkFindings)) . "\n");
