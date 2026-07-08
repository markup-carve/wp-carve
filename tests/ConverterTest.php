<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpCarve\Converter;

/**
 * @uses \WpCarve\Converter
 */
class ConverterTest extends TestCase
{
    public function testEmptyInputReturnsEmptyString(): void
    {
        $converter = new Converter([]);

        $this->assertSame('', $converter->toHtml('   '));
    }

    public function testRendersHeadingAndEmphasis(): void
    {
        $converter = new Converter([]);

        $html = $converter->toHtml("# Title\n\nSome *strong* here.");

        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('Title', $html);
        $this->assertStringContainsString('<strong>strong</strong>', $html);
    }

    public function testCommentContextStripsRawHtml(): void
    {
        // Comments are untrusted: the engine strips raw HTML outright (strict
        // safe mode), so nothing survives even before wp_kses runs.
        $converter = new Converter(['comment_profile' => 'comment']);

        $html = $converter->toHtml('<div>hi</div><script>alert(1)</script>', 'comment');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<div>', $html);
    }

    public function testFullProfileRendersRawHtml(): void
    {
        // Raw HTML is a Full-profile capability, written with Djot's explicit
        // `=html` raw block. The engine renders it verbatim (RAW_HTML_ALLOW); the
        // wp_kses gate - absent in this unit suite, covered by the WP-integration
        // checks - then strips scripts/handlers.
        $converter = new Converter(['post_profile' => 'full']);

        $html = $converter->toHtml("```=html\n<div class=\"callout\">hi</div>\n```", 'post');

        $this->assertStringContainsString('<div class="callout">', $html);
        $this->assertStringContainsString('hi', $html);
    }

    public function testArticleProfileDoesNotRenderRawHtml(): void
    {
        // The default article profile denies raw HTML at the profile level: the
        // same `=html` block is escaped to text, never a live element.
        $converter = new Converter(['post_profile' => 'article']);

        $html = $converter->toHtml("```=html\n<div class=\"callout\">hi</div>\n```", 'post');

        $this->assertStringNotContainsString('<div class="callout">', $html);
    }

    public function testAllowedHtmlExtendsCoreAllowlistWithCarveExtras(): void
    {
        $allowed = Converter::allowedHtml();

        // Base allowlist from wp_kses_allowed_html('post') is preserved.
        $this->assertArrayHasKey('a', $allowed);
        $this->assertArrayHasKey('pre', $allowed);
        // Carve-specific extras: task-list checkboxes and media-embed iframes.
        $this->assertArrayHasKey('input', $allowed);
        $this->assertTrue($allowed['input']['checked']);
        $this->assertArrayHasKey('label', $allowed);
        $this->assertArrayHasKey('iframe', $allowed);
        $this->assertTrue($allowed['iframe']['allowfullscreen']);
    }

    public function testSanitizeHtmlIsNoopWithoutWpKses(): void
    {
        // The unit suite has no wp_kses(); the sanitizer must pass HTML through
        // untouched instead of failing (WordPress always provides it at runtime).
        $html = '<p onclick="x()">hi</p>';

        $this->assertSame($html, Converter::sanitizeHtml($html));
    }

    public function testTableOfContentsExtensionEmitsTocList(): void
    {
        $converter = new Converter([
            'toc_enabled' => true,
            'toc_position' => 'top',
            'permalinks_enabled' => false,
        ]);

        $html = $converter->toHtml("# One\n\n## Two\n\n## Three\n\ntext");

        $this->assertStringContainsString('toc', $html);
    }

    public function testTableOfContentsIsCollapsibleDisclosure(): void
    {
        $converter = new Converter([
            'toc_enabled' => true,
            'toc_position' => 'top',
            'permalinks_enabled' => false,
        ]);

        $html = $converter->toHtml("# One\n\n## Two\n\n## Three\n\ntext");

        // The generated TOC is wrapped in a <details>/<summary> so it starts
        // collapsed and only opens on click; the plain <nav> must be gone and no
        // `open` attribute may leak in (closed by default).
        $this->assertStringContainsString('<details class="toc">', $html);
        $this->assertStringContainsString('<summary>Table of Contents</summary>', $html);
        $this->assertStringNotContainsString('<nav class="toc"', $html);
        $this->assertStringNotContainsString('<details class="toc" open', $html);
        // The heading list survives inside the disclosure.
        $this->assertStringContainsString('href="#Two"', $html);
    }

    public function testTabRadioNameSurvivesAllowlist(): void
    {
        // The CSS-only tabs widget groups its radios with a shared `name`; the
        // sanitization allowlist must keep it, or panel switching breaks.
        $this->assertArrayHasKey('name', Converter::allowedHtml()['input']);
    }

    /**
     * Round-trip-safety harness for the visual-editor seed.
     *
     * The Visual editor seeds from rendered HTML and serializes it back to Carve
     * on every edit, so any generated/injected markup in the seed gets frozen
     * into per-post source (a global setting leaking into the post, or generated
     * navigation stacking up each render). Each case below is markup that the
     * 'post' render injects; the 'editor' seed must NOT contain it. Add a row
     * here whenever a new render-side extension is introduced.
     *
     * @param array<string, mixed> $settings
     */
    #[DataProvider('editorSeedProvider')]
    public function testEditorSeedOmitsGeneratedMarkup(array $settings, string $carve, string $generatedMarker): void
    {
        $converter = new Converter($settings);

        // 'post' seeds the frontend: the generated markup is present.
        $this->assertStringContainsString(
            $generatedMarker,
            $converter->toHtml($carve, 'post'),
            'post context should inject the generated markup'
        );

        // 'editor' seeds the visual editor: nothing generated to freeze into source.
        $this->assertStringNotContainsString(
            $generatedMarker,
            $converter->toHtml($carve, 'editor'),
            'editor seed must not carry non-round-trippable markup'
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function editorSeedProvider(): array
    {
        return [
            // name              => [ settings,                              carve source,                              marker only 'post' should render ]
            'table of contents'  => [['toc_enabled' => true],               "# A\n\n## B\n\ntext",                      'class="toc"'],
            'heading permalinks' => [['permalinks_enabled' => true],        "# A\n\n## B",                              'class="permalink"'],
            'abbreviations'      => [['abbreviations' => 'HTML: HyperText Markup Language'], 'We use HTML often.',        '<abbr'],
            'diagram (mermaid)'  => [['mermaid_enabled' => true],           "``` mermaid\ngraph TD; A-->B;\n```",        'class="mermaid"'],
            'heading level shift' => [['heading_shift' => 2],               '# A',                                      '<h3'],
            // Details/spoiler render <details> on the front end but the editor
            // seed keeps the generic <div class="..."> so carveDiv round-trips.
            'details disclosure' => [[],                                    "::: details \"More\"\nBody.\n:::",         '<details'],
            'spoiler disclosure' => [[],                                    "::: spoiler \"Reveal\"\nSecret.\n:::",     '<details'],
        ];
    }

    public function testEditorContextPreservesAuthoredContent(): void
    {
        // Stripping generated markup must not drop the author's own content.
        $converter = new Converter(['toc_enabled' => true, 'permalinks_enabled' => true]);
        $editor = $converter->toHtml("# Title\n\n## Getting started\n\nBody text.", 'editor');

        $this->assertStringContainsString('<h2', $editor);
        $this->assertStringContainsString('Getting started', $editor);
        $this->assertStringContainsString('Body text.', $editor);
    }

    public function testTorchlightCodeBlockPreservesAttributesAndLineNumbers(): void
    {
        if (!class_exists(\Torchlight\Engine\Engine::class)) {
            $this->markTestSkipped('Torchlight Engine is not installed.');
        }

        $converter = new Converter([
            'torchlight_enabled' => true,
            'torchlight_theme' => 'github-light',
        ]);
        $source = <<<'CARVE'
{.line-numbers .sample data-line-start=42 data-hl="1" title="Example"}
``` php
echo 1; // [tl! highlight]
```
CARVE;

        $html = $converter->toHtml($source);

        $this->assertStringContainsString('<pre class="sample"', $html);
        $this->assertStringContainsString('data-title="Example"', $html);
        $this->assertStringContainsString('data-hl="1"', $html);
        $this->assertStringContainsString('class="line-number">42</span>', $html);
        $this->assertStringContainsString('line-highlight', $html);
        $this->assertStringNotContainsString('[tl! highlight]', $html);
        $this->assertStringNotContainsString('line-numbers', $html);
    }

    public function testTorchlightLineNumbersCanBeEnabledGlobally(): void
    {
        if (!class_exists(\Torchlight\Engine\Engine::class)) {
            $this->markTestSkipped('Torchlight Engine is not installed.');
        }

        $converter = new Converter([
            'torchlight_enabled' => true,
            'torchlight_theme' => 'github-light',
            'torchlight_line_numbers' => true,
        ]);

        $html = $converter->toHtml("``` php\necho 1;\n```");

        $this->assertStringContainsString('class="line-number">1</span>', $html);
    }

    public function testTorchlightPerBlockThemeOverridesGlobalSetting(): void
    {
        if (!class_exists(\Torchlight\Engine\Engine::class)) {
            $this->markTestSkipped('Torchlight Engine is not installed.');
        }

        $converter = new Converter([
            'torchlight_enabled' => true,
            'torchlight_theme' => 'github-light',
        ]);
        $source = <<<'CARVE'
{theme=dracula}
``` php
echo 1;
```
CARVE;

        $html = $converter->toHtml($source);

        // The per-block theme wins; the global github-light theme is not applied.
        $this->assertStringContainsString('dracula', $html);
        $this->assertStringNotContainsString('github', $html);
    }

    public function testCarveFenceHighlightsWithTorchlightWhenGrammarInstalled(): void
    {
        $grammar = dirname(__DIR__) . '/vendor/markup-carve/carve-grammars/textmate/carve.tmLanguage.json';
        if (!file_exists($grammar)) {
            $this->markTestSkipped('markup-carve/carve-grammars TextMate grammar not installed');
        }
        if (!class_exists(\Torchlight\Engine\Engine::class)) {
            $this->markTestSkipped('torchlight/engine not installed');
        }

        $converter = new Converter(['post_profile' => 'full', 'torchlight_enabled' => true]);
        $html = $converter->toHtml("``` carve\n# Heading\n\n*strong* text.\n```", 'post');

        // The carve grammar is registered, so the fence highlights as `carve`
        // instead of falling back to plaintext.
        $this->assertStringContainsString('language-carve', $html);
        $this->assertStringContainsString('torchlight', $html);
        // A matched grammar colors tokens distinctly; plaintext would be uniform.
        preg_match_all('/color:\s*#?[0-9a-fA-F]{3,8}/', $html, $m);
        $this->assertGreaterThan(1, count(array_unique($m[0])), 'expected varied token colors from the carve grammar');
    }
}
