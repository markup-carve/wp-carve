<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WpCarve\Converter;
use WpCarve\Plugin;
use WpCarve\Settings;

/**
 * Whole-post Carve mode on a post split with `<!--nextpage-->`.
 */
class PaginationTest extends TestCase
{
    /**
     * @var int
     */
    private const POST = 70;

    /**
     * @var string
     */
    private const SOURCE = "# Chapter\n\nFirst page.\n\n<!--nextpage-->\n\nSecond page.\n";

    protected function setUp(): void
    {
        wpcarve_test_reset_hooks();
        wpcarve_test_set_post(self::POST, ['post_content' => self::SOURCE]);
        update_post_meta(self::POST, '_wpcarve_enabled', '1');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pages'], $GLOBALS['page'], $GLOBALS['multipage'], $GLOBALS['id']);
    }

    public function testEachPageRendersOnlyItsOwnChunk(): void
    {
        // What setup_postdata() leaves behind for this post.
        $GLOBALS['pages'] = ["# Chapter\n\nFirst page.", 'Second page.'];
        $GLOBALS['multipage'] = 1;
        $GLOBALS['id'] = self::POST;

        $GLOBALS['page'] = 1;
        $first = $this->render();
        $GLOBALS['page'] = 2;
        $second = $this->render();

        $this->assertStringContainsString('First page.', $first);
        $this->assertStringNotContainsString('Second page.', $first);
        $this->assertStringContainsString('Second page.', $second);
        $this->assertStringNotContainsString('First page.', $second);
        $this->assertStringNotContainsString('nextpage', $first . $second);
    }

    public function testPageLinksAppendedBeforeTheFilterAreKept(): void
    {
        $GLOBALS['pages'] = ["# Chapter\n\nFirst page.", 'Second page.'];
        $GLOBALS['multipage'] = 1;
        $GLOBALS['id'] = self::POST;
        $GLOBALS['page'] = 1;
        $links = '<p class="post-nav-links">Pages: 1 <a href="/x/2/">2</a></p>';
        $GLOBALS['_wpcarve_test_page_links'] = $links;
        try {
            // The post-content block: the chunk plus core's links.
            $block = $this->plugin()->maybeRenderPost("# Chapter\n\nFirst page." . $links);
            // A classic theme: links printed later by the template.
            $classic = $this->plugin()->maybeRenderPost("# Chapter\n\nFirst page.");
        } finally {
            unset($GLOBALS['_wpcarve_test_page_links']);
        }

        $this->assertStringEndsWith($links, $block);
        $this->assertSame(1, substr_count($block, 'post-nav-links'));
        $this->assertStringNotContainsString('post-nav-links', $classic);
    }

    public function testPagesOfAnotherPostAreIgnored(): void
    {
        $GLOBALS['pages'] = ['Other post, page one.', 'Other post, page two.'];
        $GLOBALS['multipage'] = 1;
        $GLOBALS['page'] = 2;
        $GLOBALS['id'] = self::POST + 1;

        $html = $this->render();

        $this->assertStringContainsString('First page.', $html);
        $this->assertStringNotContainsString('Other post', $html);
    }

    public function testALoneLeadingMarkerNeverRendersAsText(): void
    {
        // WordPress drops a leading marker and keeps a single page, so it
        // sets no page globals for this post.
        wpcarve_test_set_post(self::POST, ['post_content' => "<!--nextpage-->\n\nOnly page."]);

        $html = $this->render();

        $this->assertStringContainsString('Only page.', $html);
        $this->assertStringNotContainsString('nextpage', $html);
    }

    public function testTheExcerptDropsTheMarker(): void
    {
        $plugin = $this->plugin();

        $excerpt = $plugin->maybeRenderExcerpt('');

        $this->assertStringContainsString('First page.', $excerpt);
        $this->assertStringContainsString('Second page.', $excerpt);
        $this->assertStringNotContainsString('nextpage', $excerpt);
    }

    public function testAProtectedPostKeepsThePasswordForm(): void
    {
        wpcarve_test_set_post(self::POST, ['post_content' => self::SOURCE, 'post_password' => 'secret']);
        $form = '<form class="post-password-form"></form>';
        $plugin = $this->plugin();

        $this->assertSame($form, $plugin->maybeRenderPost($form));
        $this->assertSame('protected', $plugin->maybeRenderExcerpt('protected'));

        $GLOBALS['_wpcarve_test_password_ok'] = true;
        try {
            $this->assertStringContainsString('First page.', $plugin->maybeRenderPost($form));
        } finally {
            unset($GLOBALS['_wpcarve_test_password_ok']);
        }
    }

    private function render(): string
    {
        return $this->plugin()->maybeRenderPost('');
    }

    private function plugin(): Plugin
    {
        $plugin = new Plugin();
        (new ReflectionProperty($plugin, 'converter'))->setValue($plugin, new Converter(Settings::all()));

        return $plugin;
    }
}
