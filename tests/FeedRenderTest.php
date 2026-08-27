<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use WpCarve\Converter;

/**
 * The 'feed' render context.
 *
 * A feed reader runs none of this plugin's JavaScript, so the interactive
 * render hands it an empty container where a diagram was - the diagram is not
 * broken-looking, it is absent. These assert the degradation, and assert it
 * against the interactive render of the same source so a change that made both
 * paths identical would fail rather than pass quietly.
 */
#[UsesClass(Converter::class)]
class FeedRenderTest extends TestCase
{
    private const CHART = "``` chart\n{ \"type\": \"bar\" }\n```";

    private const MERMAID = "``` mermaid\ngraph TD; A-->B\n```";

    public function testTheInteractiveRenderOfAChartHasNoTextContent(): void
    {
        // The premise, pinned: without this the feed test below could pass
        // while the problem it exists for had gone away.
        $html = (new Converter(['chart_enabled' => true]))->toHtml(self::CHART, 'post');

        $this->assertStringContainsString('class="chart"', $html);
        $this->assertStringNotContainsString('language-chart', $html);
    }

    public function testAChartDegradesToItsSourceInAFeed(): void
    {
        $html = (new Converter(['chart_enabled' => true]))->toHtml(self::CHART, 'feed');

        $this->assertStringContainsString('language-chart', $html);
        $this->assertStringContainsString('bar', $html);
        $this->assertStringNotContainsString('data-carve-json', $html);
    }

    public function testADiagramDegradesToItsSourceInAFeed(): void
    {
        $html = (new Converter(['mermaid_enabled' => true, 'diagrams_enabled' => true]))
            ->toHtml(self::MERMAID, 'feed');

        $this->assertStringContainsString('graph TD', $html);
        $this->assertStringContainsString('language-mermaid', $html);
    }

    public function testTheFeedAndPostRendersActuallyDiffer(): void
    {
        $converter = new Converter(['chart_enabled' => true]);

        $this->assertNotSame(
            $converter->toHtml(self::CHART, 'post'),
            $converter->toHtml(self::CHART, 'feed'),
            'a feed render identical to the post render means the context is not reaching the renderer',
        );
    }

    public function testOrdinaryProseIsUnchangedInAFeed(): void
    {
        // Static mode is for client-rendered constructs. Everything else has to
        // come through untouched, or the feed would be a second, divergent
        // rendering of the site rather than the same one degraded.
        $converter = new Converter([]);
        $source = "# Title\n\nSome *bold* text with a [link](/u).\n\n- one\n- two\n";

        $this->assertSame(
            $converter->toHtml($source, 'post'),
            $converter->toHtml($source, 'feed'),
        );
    }
}
