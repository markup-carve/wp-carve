<?php

declare(strict_types=1);

namespace {
function get_comment(): ?object
{
    return $GLOBALS['_wpcarve_budget_comment'] ?? null;
}

function get_comment_meta(int $id, string $key, bool $single = false): mixed
{
    return $GLOBALS['_wpcarve_budget_meta'][$id][$key] ?? '';
}

function update_comment_meta(int $id, string $key, mixed $value): void
{
    $GLOBALS['_wpcarve_budget_meta'][$id][$key] = $value;
}

function delete_comment_meta(int $id, string $key): void
{
    unset($GLOBALS['_wpcarve_budget_meta'][$id][$key]);
}

}

namespace WpCarve\Test {

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WpCarve\Converter;
use WpCarve\Plugin;
use WpCarve\Settings;

class UntrustedBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_wpcarve_test_options'] = [];
        $GLOBALS['_wpcarve_budget_meta'] = [];
        $GLOBALS['_wpcarve_budget_comment'] = (object)['comment_ID' => 19];
    }

    private function plugin(): Plugin
    {
        $plugin = new Plugin();
        (new ReflectionProperty($plugin, 'converter'))->setValue($plugin, new Converter(Settings::defaults()));

        return $plugin;
    }

    public function testOversizeCommentFallsBackAndRecordsReason(): void
    {
        $GLOBALS['_wpcarve_test_options'][Settings::OPTION] = ['untrusted_max_bytes' => 8];
        \update_comment_meta(19, '_wpcarve_raw', '<b>long text</b>');

        $html = $this->plugin()->renderComment('stored');
        $this->assertStringContainsString('&lt;b&gt;long text&lt;/b&gt;', $html);
        $this->assertSame('source_size', \get_comment_meta(19, '_wpcarve_budget', true));
    }

    public function testUnderLimitCommentRendersNormally(): void
    {
        \update_comment_meta(19, '_wpcarve_raw', 'A *strong* word.');

        $html = $this->plugin()->renderComment('stored');
        $this->assertStringContainsString('<strong>strong</strong>', $html);
        $this->assertSame('', \get_comment_meta(19, '_wpcarve_budget', true));
    }

    public function testDefaultsArePresent(): void
    {
        $this->assertSame(65536, Settings::defaults()['untrusted_max_bytes']);
        $this->assertSame(4, Settings::defaults()['untrusted_max_nesting']);
    }

    public function testDepthFallbackIsReevaluatedAfterRaisingBudget(): void
    {
        $source = "> a\n> > b\n> > > c";
        \update_comment_meta(19, '_wpcarve_raw', $source);
        $GLOBALS['_wpcarve_test_options'][Settings::OPTION] = ['untrusted_max_nesting' => 1];
        $plugin = $this->plugin();

        $this->assertStringContainsString('&gt; a', $plugin->renderComment('stored'));
        $this->assertSame('render_depth', \get_comment_meta(19, '_wpcarve_budget', true));

        $GLOBALS['_wpcarve_test_options'][Settings::OPTION] = ['untrusted_max_nesting' => 4];
        $this->assertStringContainsString('<blockquote>', $plugin->renderComment('stored'));
        $this->assertSame('', \get_comment_meta(19, '_wpcarve_budget', true));
    }
}

}
