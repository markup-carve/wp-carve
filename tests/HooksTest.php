<?php

declare(strict_types=1);

namespace WpCarve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\ExternalLinksExtension;
use MarkupCarve\Carve\Extension\HeadingNumbersExtension;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use WpCarve\Converter;

/**
 * The hooks docs/hooks.md documents, exercised through the plugin.
 *
 * These could not exist before: the test bootstrap stubbed `apply_filters` to
 * return its value and `do_action` to do nothing, so every documented hook was
 * unreachable and a change that stopped firing one broke every integration
 * silently. Each test here registers a real callback and asserts the plugin's
 * OUTPUT changed, which is the only form that can tell a fired hook from a
 * dropped one.
 */
#[UsesClass(Converter::class)]
class HooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wpcarve_test_reset_hooks();
    }

    protected function tearDown(): void
    {
        wpcarve_test_reset_hooks();
        parent::tearDown();
    }

    public function testConverterActionCanRegisterAnEngineExtension(): void
    {
        $html = (new Converter([]))->toHtml('# Top');
        $this->assertStringNotContainsString('section-number', $html);

        add_action('wpcarve_converter', static function (CarveConverter $converter, string $context): void {
            if ($context === 'post') {
                $converter->addExtension(new HeadingNumbersExtension());
            }
        }, 10, 2);

        $this->assertStringContainsString('section-number', (new Converter([]))->toHtml('# Top'));
    }

    public function testConverterActionReceivesTheContextItDocuments(): void
    {
        $seen = [];
        add_action('wpcarve_converter', static function (CarveConverter $converter, string $context) use (&$seen): void {
            $seen[] = $context;
        }, 10, 2);

        $converter = new Converter([]);
        $converter->toHtml('text');
        $converter->toHtml('more text', 'comment');

        // Gating on the context is what docs/hooks.md tells integrators to do,
        // so a hook fired without one would make every documented example wrong.
        $this->assertContains('post', $seen);
        $this->assertContains('comment', $seen);
    }

    public function testAnExtensionRegisteredForPostDoesNotLeakIntoComments(): void
    {
        add_action('wpcarve_converter', static function (CarveConverter $converter, string $context): void {
            if ($context === 'post') {
                $converter->addExtension(new ExternalLinksExtension(internalHosts: ['example.org'], target: '_blank'));
            }
        }, 10, 2);

        $source = 'See [docs](https://elsewhere.test/a).';
        $this->assertStringContainsString('target="_blank"', (new Converter([]))->toHtml($source));

        // The converter is built once per context and cached, so a leak here
        // would mean every comment inherited the post configuration.
        $this->assertStringNotContainsString('target="_blank"', (new Converter([]))->toHtml($source, 'comment'));
    }

    public function testSourceFilterRewritesTheCarveBeforeParsing(): void
    {
        add_filter('wpcarve_source', static fn (string $carve): string => str_replace('PLACEHOLDER', '*bold*', $carve));

        $this->assertStringContainsString('<strong>bold</strong>', (new Converter([]))->toHtml('PLACEHOLDER'));
    }

    public function testRenderedHtmlFilterRewritesTheOutput(): void
    {
        add_filter('wpcarve_rendered_html', static fn (string $html): string => $html . '<!-- seen -->');

        $this->assertStringContainsString('<!-- seen -->', (new Converter([]))->toHtml('text'));
    }

    public function testFiltersRunInPriorityOrder(): void
    {
        add_filter('wpcarve_rendered_html', static fn (string $html): string => $html . 'B', 20);
        add_filter('wpcarve_rendered_html', static fn (string $html): string => $html . 'A', 5);

        $html = (new Converter([]))->toHtml('text');

        $this->assertStringEndsWith('AB', $html, 'priority 5 has to run before priority 20');
    }
}
