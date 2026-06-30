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
