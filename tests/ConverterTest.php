<?php

declare(strict_types=1);

namespace WpCarve\Test;

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

    public function testEditorContextOmitsNonRoundTrippableMarkup(): void
    {
        // The visual editor seeds from rendered HTML and serializes it back to
        // Carve on every edit. A generated TOC nav or heading permalink anchor
        // would be frozen into the source on that round trip (and then a fresh
        // TOC would stack on top each render). The 'editor' context must render
        // like a post but omit that generated markup; 'post' keeps it.
        $converter = new Converter([
            'toc_enabled' => true,
            'toc_position' => 'top',
            'permalinks_enabled' => true,
        ]);
        $carve = "# Title\n\n## Getting started\n\ntext\n\n## Configuration\n\nmore";

        $post = $converter->toHtml($carve, 'post');
        $editor = $converter->toHtml($carve, 'editor');

        // 'post' seeds the frontend: generated TOC + permalink anchors are present.
        $this->assertStringContainsString('class="toc"', $post);
        $this->assertStringContainsString('class="permalink"', $post);

        // 'editor' seeds the visual editor: nothing generated to freeze into source.
        $this->assertStringNotContainsString('class="toc"', $editor);
        $this->assertStringNotContainsString('class="permalink"', $editor);

        // The authored content itself still renders in the editor context.
        $this->assertStringContainsString('<h2', $editor);
        $this->assertStringContainsString('Getting started', $editor);
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
