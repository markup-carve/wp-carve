<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use WpCarve\Blocks\SlidesBlock;
use WpCarve\Converter;

/**
 * @uses \WpCarve\Blocks\SlidesBlock
 */
class SlidesBlockTest extends TestCase
{
    public function testRenderSplitsSlidesOutsideCodeFences(): void
    {
        $block = new SlidesBlock(new Converter([]));
        $source = "# One\n\n```text\n---\n```\n\n---\n\n## Two";

        $html = $block->render([
            'align' => 'wide',
            'carve' => $source,
            'layout' => 'wide',
            'theme' => 'night',
        ]);

        $this->assertStringContainsString('wpcarve-slides--night', $html);
        $this->assertStringContainsString('wpcarve-slides--wide', $html);
        $this->assertStringContainsString('alignwide', $html);
        $this->assertStringContainsString('data-count="2"', $html);
        $this->assertSame(2, substr_count($html, 'data-slide="'));
        $this->assertStringContainsString('class="language-text"', $html);
        $this->assertStringContainsString("---\n</code>", $html);
    }
}
