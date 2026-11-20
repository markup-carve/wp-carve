<?php

declare(strict_types=1);

namespace WpCarve\Test;

use MarkupCarve\Carve\Extension\Claim;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\RenderHost;
use MarkupCarve\Carve\Node;
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

    /**
     * 0.2 has no converter object (C2-T-005: extensions are an option of the
     * library calls), so the 0.1 `wpcarve_converter` action became the
     * `wpcarve_extensions` filter over that option.
     */
    private static function shoutFence(): ExtensionDefinition
    {
        return new ExtensionDefinition(
            'test.shout',
            '1',
            matcher: ['form' => 'fence', 'name' => 'shout'],
            transform: static function (Claim $claim): Node {
                $node = new Node('block_extension', []);
                $node->f = ['name' => 'test.shout', 'version' => '1', 'fallback' => [$claim->copyNode()]];

                return $node;
            },
            renderer: static fn (Node $node, RenderHost $host): string => '<p class="shouted">LOUD</p>',
        );
    }

    public function testExtensionsFilterCanRegisterAnEngineExtension(): void
    {
        $source = "```shout\nhi\n```\n";
        $this->assertStringNotContainsString('shouted', (new Converter([]))->toHtml($source));

        add_filter('wpcarve_extensions', static function (array $extensions, string $context): array {
            if ($context === 'post') {
                $extensions[] = self::shoutFence();
            }

            return $extensions;
        }, 10, 2);

        $this->assertStringContainsString('shouted', (new Converter([]))->toHtml($source));
    }

    public function testExtensionsFilterReceivesTheContextItDocuments(): void
    {
        $seen = [];
        add_filter('wpcarve_extensions', static function (array $extensions, string $context) use (&$seen): array {
            $seen[] = $context;

            return $extensions;
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
        add_filter('wpcarve_extensions', static function (array $extensions, string $context): array {
            if ($context === 'post') {
                $extensions[] = self::shoutFence();
            }

            return $extensions;
        }, 10, 2);

        $source = "```shout\nhi\n```\n";
        $this->assertStringContainsString('shouted', (new Converter([]))->toHtml($source));

        // The options are built once per context and cached, so a leak here
        // would mean every comment inherited the post configuration.
        $this->assertStringNotContainsString('shouted', (new Converter([]))->toHtml($source, 'comment'));
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
