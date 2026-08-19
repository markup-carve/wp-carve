<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use WpCarve\Settings;
use WpCarve\SourceFooter;

class SourceFooterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_wpcarve_test_options'] = [];
        $GLOBALS['_wpcarve_test_meta'] = [];
    }

    public function testDefaultsPublishNothing(): void
    {
        wpcarve_test_set_post(1, ['post_content' => '# Private source']);
        update_post_meta(1, '_wpcarve_enabled', true);

        $this->assertSame('<p>Rendered</p>', (new SourceFooter())->append('<p>Rendered</p>'));
    }

    public function testAttributionAndBothSourceActionsRender(): void
    {
        $source = "# Demo\n\n<script>must be escaped</script>";
        wpcarve_test_set_post(7, ['post_content' => $source]);
        update_post_meta(7, '_wpcarve_enabled', true);
        $GLOBALS['_wpcarve_test_options'][Settings::OPTION] = [
            'attribution_enabled' => true,
            'source_access' => 'both',
        ];

        $html = (new SourceFooter())->append('<article>Rendered</article>');

        $this->assertStringContainsString('Written with', $html);
        $this->assertStringContainsString(SourceFooter::PROJECT_URL, $html);
        $this->assertStringContainsString(SourceFooter::WORDPRESS_PLUGIN_URL, $html);
        $this->assertStringContainsString('Carve WordPress plugin', $html);
        $this->assertStringContainsString('View .crv source', $html);
        $this->assertStringContainsString('wpcarve_source=7', $html);
        $this->assertStringContainsString('&lt;script&gt;must be escaped&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>must be escaped</script>', $html);
    }

    public function testPerPostOverridesAreIndependent(): void
    {
        wpcarve_test_set_post(2, ['post_content' => '# Demo']);
        update_post_meta(2, '_wpcarve_enabled', true);
        update_post_meta(2, '_wpcarve_attribution', 'hide');
        update_post_meta(2, '_wpcarve_source_access', 'download');
        $GLOBALS['_wpcarve_test_options'][Settings::OPTION] = [
            'attribution_enabled' => true,
            'source_access' => 'view',
        ];

        $html = (new SourceFooter())->append('Rendered');

        $this->assertStringNotContainsString('Written with', $html);
        $this->assertStringContainsString('Download .crv', $html);
        $this->assertStringNotContainsString('View .crv source', $html);
    }

    public function testProtectedOrUnpublishedPostsNeverExposeSource(): void
    {
        foreach ([
            ['post_status' => 'draft'],
            ['post_password' => 'secret'],
        ] as $fields) {
            wpcarve_test_set_post(3, ['post_content' => '# Hidden'] + $fields);
            update_post_meta(3, '_wpcarve_enabled', true);
            update_post_meta(3, '_wpcarve_source_access', 'both');

            $this->assertSame('Rendered', (new SourceFooter())->append('Rendered'));
        }
    }

    public function testBlockSourceIsExtractedWithoutSerializedMarkup(): void
    {
        $serialized = '<!-- wp:carve/markup {"carve":"# Hi\\n\\nBody."} /-->';
        wpcarve_test_set_post(4, ['post_content' => $serialized]);

        $source = (new SourceFooter())->source(get_post(4));

        $this->assertSame("# Hi\n\nBody.", $source);
        $this->assertStringNotContainsString('wp:carve', $source);
    }
}
