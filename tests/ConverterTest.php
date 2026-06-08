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

    public function testMarkdownModeConvertsMarkdownEmphasis(): void
    {
        // In Markdown *word* is strong-equivalent; Carve uses underscores for
        // emphasis. With markdown_mode on, Markdown syntax must still render.
        $converter = new Converter(['markdown_mode' => true]);

        $html = $converter->toHtml('A **bold** word.');

        $this->assertStringContainsString('<strong>bold</strong>', $html);
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
}
