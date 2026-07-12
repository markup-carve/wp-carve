<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WpCarve\Ingest\PasteController;

/**
 * The paste-ingest format sniffer decides which carve-php converter runs for a
 * pasted blob when `from=auto`. A wrong guess silently converts with the wrong
 * grammar, so the detection order (bbcode, html, markdown, then djot) is worth
 * pinning.
 *
 * @uses \WpCarve\Ingest\PasteController
 */
class PasteControllerTest extends TestCase
{
    private function sniff(string $source): string
    {
        $method = new ReflectionMethod(PasteController::class, 'sniff');

        return (string)$method->invoke(new PasteController(), $source);
    }

    public function testDetectsBbcode(): void
    {
        $this->assertSame('bbcode', $this->sniff('[b]bold[/b] and [url=http://x]link[/url]'));
    }

    public function testDetectsHtml(): void
    {
        $this->assertSame('html', $this->sniff('<p>A paragraph with <strong>bold</strong>.</p>'));
    }

    public function testDetectsMarkdownByDoubleAsteriskBold(): void
    {
        $this->assertSame('markdown', $this->sniff("Some **bold** text\n\nAnd more."));
    }

    public function testDetectsMarkdownBySetextHeading(): void
    {
        $this->assertSame('markdown', $this->sniff("Title\n=====\n\nBody."));
    }

    public function testFallsBackToDjot(): void
    {
        // Single-asterisk strong is Djot, not Markdown; no HTML/BBCode markers.
        $this->assertSame('djot', $this->sniff("A line with *strong* and _emphasis_."));
    }

    public function testBbcodeWinsOverHtmlWhenBothPresent(): void
    {
        // BBCode is checked first, matching the converter's precedence.
        $this->assertSame('bbcode', $this->sniff('[quote]<p>x</p>[/quote]'));
    }
}
