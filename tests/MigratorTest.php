<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use WpCarve\Migration\Migrator;

#[UsesClass(Migrator::class)]
class MigratorTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_wpcarve_test_posts'] = [];
        $GLOBALS['_wpcarve_test_meta'] = [];
    }

    public function testMissingPostCannotMigrate(): void
    {
        $analysis = (new Migrator())->analyze(999);

        $this->assertFalse($analysis['can_auto_migrate']);
        $this->assertSame('none', $analysis['source']);
    }

    public function testEmptyContentCannotMigrate(): void
    {
        wpcarve_test_set_post(1, ['post_content' => '   ']);

        $analysis = (new Migrator())->analyze(1);

        $this->assertFalse($analysis['can_auto_migrate']);
        $this->assertSame('none', $analysis['source']);
    }

    public function testBlockEditorContentIsSkipped(): void
    {
        wpcarve_test_set_post(2, ['post_content' => "<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->"]);

        $analysis = (new Migrator())->analyze(2);

        $this->assertFalse($analysis['can_auto_migrate']);
        $this->assertSame('blocks', $analysis['source']);
    }

    public function testForeignShortcodeIsSkipped(): void
    {
        wpcarve_test_set_post(3, ['post_content' => 'Before [gallery ids="1,2"] after']);

        $analysis = (new Migrator())->analyze(3);

        $this->assertFalse($analysis['can_auto_migrate']);
        $this->assertSame('shortcodes', $analysis['source']);
    }

    public function testOwnCarveShortcodeIsNotComplex(): void
    {
        wpcarve_test_set_post(4, ['post_content' => "# Heading\n\n[carve]more[/carve]"]);

        $migrator = new Migrator();
        $analysis = $migrator->analyze(4);

        $this->assertSame('markdown', $analysis['source']);
        $this->assertTrue($analysis['can_auto_migrate']);
        $migrator->migrate(4);
        $this->assertStringContainsString('[carve]more[/carve]', (string)get_post(4)->post_content);
    }

    public function testMarkdownIsDetected(): void
    {
        wpcarve_test_set_post(5, ['post_content' => "# Heading\n\n- a\n- b\n\n**bold**"]);

        $analysis = (new Migrator())->analyze(5);

        $this->assertSame('markdown', $analysis['source']);
        $this->assertTrue($analysis['can_auto_migrate']);
        $this->assertSame('OK', $analysis['reason']);
        $this->assertSame('markdown-bullet-list', $analysis['report']['diagnostics'][1]['code']);
        $this->assertSame('preserved', $analysis['report']['diagnostics'][1]['fidelity']);
    }

    public function testHtmlIsDetected(): void
    {
        wpcarve_test_set_post(6, ['post_content' => '<p>Just a <strong>paragraph</strong>.</p>']);

        $analysis = (new Migrator())->analyze(6);

        $this->assertSame('html', $analysis['source']);
    }

    public function testMigrateConvertsAndFlagsPost(): void
    {
        wpcarve_test_set_post(7, ['post_content' => "# Heading\n\nsome **text**"]);

        $len = (new Migrator())->migrate(7, true);

        $this->assertNotNull($len);
        $this->assertGreaterThan(0, $len);
        $this->assertSame(1, $GLOBALS['_wpcarve_test_meta'][7]['_wpcarve_enabled']);
        $report = json_decode(stripslashes($GLOBALS['_wpcarve_test_meta'][7]['_wpcarve_import_report']), true);
        $this->assertSame(2, $report['schemaVersion']);
    }

    public function testMigrateTurnsAGitHubAlertIntoAnAdmonition(): void
    {
        wpcarve_test_set_post(8, ['post_content' => "# Heading\n\n> [!NOTE]\n> Read **this** first.\n"]);

        (new Migrator())->migrate(8, true);

        $this->assertStringContainsString("::: note\nRead *this* first.\n:::", get_post(8)->post_content);
    }

    public function testMigrateReadsNoDjotCarrier(): void
    {
        wpcarve_test_set_post(9, ['post_content' => '<p>See <span data-djot-ref="n1">x</span>.</p>']);

        (new Migrator())->migrate(9, true);

        $this->assertStringContainsString('{data-djot-ref=n1}', get_post(9)->post_content);
    }

    public function testMigrateReturnsNullForBlockPostWithoutForce(): void
    {
        wpcarve_test_set_post(8, ['post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->']);

        $this->assertNull((new Migrator())->migrate(8));
    }
}
