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

    public function testCommentContextForcesSafeMode(): void
    {
        // Even with safe_mode disabled in settings, the comment context must not
        // emit raw HTML (it is always rendered in safe mode).
        $converter = new Converter(['safe_mode' => false, 'comment_profile' => 'comment']);

        $html = $converter->toHtml('<script>alert(1)</script>', 'comment');

        $this->assertStringNotContainsString('<script>', $html);
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
}
