<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use WpCarve\Meta\RenderCache;
use WpCarve\Settings;

/**
 * The render cache must invalidate not only on a plugin/engine upgrade but on
 * any change to a render-affecting setting - otherwise a settings change (new
 * TOC, a different smart-quotes locale, a torchlight theme) would leave every
 * already-saved post serving its stale cached HTML.
 */
class RenderCacheTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_wpcarve_test_options'] = [];
        $GLOBALS['_wpcarve_test_meta'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_wpcarve_test_options'], $GLOBALS['_wpcarve_test_meta']);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function setSettings(array $settings): void
    {
        $GLOBALS['_wpcarve_test_options'][Settings::OPTION] = $settings;
    }

    public function testSignatureIsStableForUnchangedSettings(): void
    {
        $this->setSettings(['torchlight_theme' => 'github-light']);
        $first = Settings::renderSignature();
        $second = Settings::renderSignature();

        $this->assertSame($first, $second);
    }

    public function testRenderAffectingSettingChangesTheSignature(): void
    {
        $this->setSettings(['torchlight_theme' => 'github-light']);
        $before = Settings::renderSignature();

        $this->setSettings(['torchlight_theme' => 'github-dark']);
        $after = Settings::renderSignature();

        $this->assertNotSame($before, $after);
    }

    public function testSurfaceOnlySettingDoesNotChangeTheSignature(): void
    {
        $this->setSettings(['live_preview' => true, 'paste_ingest' => true]);
        $before = Settings::renderSignature();

        // Surface/editor-only toggles must not bust the render cache.
        $this->setSettings(['live_preview' => false, 'paste_ingest' => false]);
        $after = Settings::renderSignature();

        $this->assertSame($before, $after);
    }

    public function testReadReturnsCachedHtmlWhenSignatureMatches(): void
    {
        $postId = 42;
        update_post_meta($postId, '_wpcarve_html', '<p>cached</p>');
        update_post_meta($postId, '_wpcarve_html_version', WPCARVE_VERSION . ':' . Settings::renderSignature());
        update_post_meta($postId, '_wpcarve_html_safe', '1');

        $this->assertSame('<p>cached</p>', RenderCache::read($postId, true));
    }

    public function testReadInvalidatesWhenARenderSettingChanged(): void
    {
        $postId = 42;
        update_post_meta($postId, '_wpcarve_html', '<p>cached</p>');
        // Cached under the current signature...
        update_post_meta($postId, '_wpcarve_html_version', WPCARVE_VERSION . ':' . Settings::renderSignature());
        update_post_meta($postId, '_wpcarve_html_safe', '1');

        // ...then a render-affecting setting changes: the stored HTML is stale.
        $this->setSettings(['smart_quotes' => true, 'smart_quotes_locale' => 'de']);

        $this->assertNull(RenderCache::read($postId, true));
    }

    public function testReadInvalidatesOnSafeModeMismatch(): void
    {
        $postId = 42;
        update_post_meta($postId, '_wpcarve_html', '<p>cached</p>');
        update_post_meta($postId, '_wpcarve_html_version', WPCARVE_VERSION . ':' . Settings::renderSignature());
        update_post_meta($postId, '_wpcarve_html_safe', '0');

        // Rendered unsafe, now an unsafe render is expected to differ from safe.
        $this->assertNull(RenderCache::read($postId, true));
    }
}
