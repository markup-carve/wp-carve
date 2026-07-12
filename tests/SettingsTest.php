<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use WpCarve\Settings;

/**
 * The settings store must return a fully-populated array (stored values over
 * typed defaults) and preserve the back-compat migration from the old boolean
 * `visual_editor` flag to the 3-way `visual_editor_mode`.
 *
 * @uses \WpCarve\Settings
 */
class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_wpcarve_test_options'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_wpcarve_test_options']);
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function store(array $stored): void
    {
        $GLOBALS['_wpcarve_test_options'][Settings::OPTION] = $stored;
    }

    public function testDefaultsFillMissingKeys(): void
    {
        $this->store(['post_profile' => 'full']);

        $all = Settings::all();
        // Stored value wins.
        $this->assertSame('full', $all['post_profile']);
        // Untouched keys fall back to defaults.
        $this->assertSame('comment', $all['comment_profile']);
        $this->assertTrue($all['render_cache']);
    }

    public function testNonArrayOptionFallsBackToDefaults(): void
    {
        $GLOBALS['_wpcarve_test_options'][Settings::OPTION] = 'corrupt';

        $this->assertSame(Settings::defaults(), Settings::all());
    }

    public function testLegacyVisualEditorTrueMapsToEnabled(): void
    {
        $this->store(['visual_editor' => true]);

        $all = Settings::all();
        $this->assertSame('enabled', $all['visual_editor_mode']);
        // The legacy key is dropped.
        $this->assertArrayNotHasKey('visual_editor', $all);
    }

    public function testLegacyVisualEditorFalseMapsToDisabled(): void
    {
        $this->store(['visual_editor' => false]);

        $this->assertSame('disabled', Settings::all()['visual_editor_mode']);
    }

    public function testExplicitModeWinsOverLegacyFlag(): void
    {
        $this->store(['visual_editor' => false, 'visual_editor_mode' => 'enabled_default']);

        $this->assertSame('enabled_default', Settings::all()['visual_editor_mode']);
    }

    public function testGetReturnsNullForUnknownKey(): void
    {
        $this->assertNull(Settings::get('does_not_exist'));
    }

    public function testUpdateMergesOverExistingValues(): void
    {
        $this->store(['post_profile' => 'full', 'toc_enabled' => true]);

        Settings::update(['post_profile' => 'minimal']);

        $all = Settings::all();
        $this->assertSame('minimal', $all['post_profile']);
        // Previously stored value is preserved through the merge.
        $this->assertTrue($all['toc_enabled']);
    }

    public function testRenderSignatureIgnoresCommentOnlyKeys(): void
    {
        $this->store(['comment_profile' => 'minimal']);
        $before = Settings::renderSignature();

        $this->store(['comment_profile' => 'full']);
        $this->assertSame($before, Settings::renderSignature(), 'Comment-only settings must not change the post render signature.');
    }
}
