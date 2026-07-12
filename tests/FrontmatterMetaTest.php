<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use WP_Post;
use WpCarve\Meta\FrontmatterMeta;

/**
 * Frontmatter-to-meta must be non-destructive: it stores the parsed frontmatter,
 * fills the excerpt only when the post has none, and records SEO description and
 * canonical, without ever clobbering author-set fields. It also clears its meta
 * when the frontmatter is removed.
 *
 * @uses \WpCarve\Meta\FrontmatterMeta
 */
class FrontmatterMetaTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_wpcarve_test_meta'] = [];
        $GLOBALS['_wpcarve_test_posts'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_wpcarve_test_meta'], $GLOBALS['_wpcarve_test_posts']);
    }

    private function post(int $id, string $content, string $excerpt = ''): WP_Post
    {
        $post = new WP_Post();
        $post->ID = $id;
        $post->post_content = $content;
        $post->post_excerpt = $excerpt;

        return $post;
    }

    public function testYamlFrontmatterMapsToMeta(): void
    {
        $post = $this->post(1, "---yaml\nexcerpt: A short teaser\ndescription: SEO desc\ncanonical: https://example.com/x\n---\n\n# Body\n");
        update_post_meta(1, '_wpcarve_enabled', '1');

        (new FrontmatterMeta())->onSave(1, $post);

        $stored = json_decode((string)get_post_meta(1, '_wpcarve_frontmatter', true), true);
        $this->assertSame('SEO desc', $stored['description']);
        $this->assertSame('A short teaser', get_post_meta(1, '_wpcarve_excerpt', true));
        $this->assertSame('SEO desc', get_post_meta(1, '_wpcarve_seo_description', true));
        $this->assertSame('https://example.com/x', get_post_meta(1, '_wpcarve_canonical', true));
    }

    public function testExcerptIsNotOverwrittenWhenPostAlreadyHasOne(): void
    {
        $post = $this->post(2, "---yaml\nexcerpt: From frontmatter\n---\n\n# Body\n", 'Author excerpt');
        update_post_meta(2, '_wpcarve_enabled', '1');

        (new FrontmatterMeta())->onSave(2, $post);

        // The frontmatter is still stored, but the excerpt meta is not written
        // because the post already carries an author excerpt.
        $this->assertNotSame('', get_post_meta(2, '_wpcarve_frontmatter', true));
        $this->assertSame('', get_post_meta(2, '_wpcarve_excerpt', true));
    }

    public function testJsonFrontmatterMapsToMeta(): void
    {
        $post = $this->post(3, "---json\n{\"description\":\"JSON desc\",\"canonical\":\"https://e.test/p\"}\n---\n\nBody\n");
        update_post_meta(3, '_wpcarve_enabled', '1');

        (new FrontmatterMeta())->onSave(3, $post);

        $this->assertSame('JSON desc', get_post_meta(3, '_wpcarve_seo_description', true));
        $this->assertSame('https://e.test/p', get_post_meta(3, '_wpcarve_canonical', true));
    }

    public function testNoFrontmatterClearsStoredMeta(): void
    {
        // A prior save left frontmatter meta behind...
        update_post_meta(4, '_wpcarve_enabled', '1');
        update_post_meta(4, '_wpcarve_frontmatter', '{"description":"old"}');

        // ...and the content no longer has any frontmatter.
        (new FrontmatterMeta())->onSave(4, $this->post(4, "# Just a heading\n\nNo frontmatter here.\n"));

        $this->assertSame('', get_post_meta(4, '_wpcarve_frontmatter', true));
    }

    public function testDisabledPostIsIgnored(): void
    {
        // _wpcarve_enabled is not set: the hook must do nothing.
        (new FrontmatterMeta())->onSave(5, $this->post(5, "---yaml\ndescription: x\n---\n\nBody\n"));

        $this->assertSame('', get_post_meta(5, '_wpcarve_frontmatter', true));
    }
}
