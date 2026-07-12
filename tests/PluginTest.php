<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use WpCarve\Plugin;
use WpCarve\Settings;

/**
 * Sanitization is unconditional: safeForAuthor always requests a safe render,
 * regardless of author capability or any stored setting. There is no raw-HTML
 * passthrough.
 */
class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_wpcarve_test_options'] = [];
        $GLOBALS['_wpcarve_test_caps'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_wpcarve_test_options'], $GLOBALS['_wpcarve_test_caps']);
    }

    public function testExcludeShortcodeFromTexturize(): void
    {
        $plugin = new Plugin();
        $this->assertSame(['wpvideo', 'carve'], $plugin->excludeShortcodeFromTexturize(['wpvideo']));
    }

    public function testRestoreShortcodeSourceStraightensFenceLineQuotes(): void
    {
        // wptexturize output for `::: tab "Overview"` inside [carve]…[/carve]:
        // wpautop tags plus curled quotes (literal or entity-encoded).
        $texturized = "<p>::: tab &#8220;Overview&#8221;<br />\nBody.<br />\n:::</p>";

        $this->assertSame(
            "::: tab \"Overview\"\nBody.\n:::",
            Plugin::restoreShortcodeSource($texturized),
        );
    }

    public function testRestoreShortcodeSourceKeepsProseQuotesCurly(): void
    {
        // Typographic quotes OUTSIDE a fence line are the author's prose;
        // only ::: opener/closer lines are straightened.
        $in = "::: note \u{201C}T\u{201D}\nShe said \u{201C}hi\u{201D}.\n:::";

        $this->assertSame(
            "::: note \"T\"\nShe said \u{201C}hi\u{201D}.\n:::",
            Plugin::restoreShortcodeSource($in),
        );
    }

    public function testRestoreShortcodeSourceLeavesCodeBlocksVerbatim(): void
    {
        // A code sample documenting a curly-quoted fence line stays verbatim.
        $in = "```\n::: note \u{201C}T\u{201D}\n```\n::: tab \u{201C}Overview\u{201D}\nBody.\n:::";

        $this->assertSame(
            "```\n::: note \u{201C}T\u{201D}\n```\n::: tab \"Overview\"\nBody.\n:::",
            Plugin::restoreShortcodeSource($in),
        );
    }

    public function testTrustedAuthorIsStillSafe(): void
    {
        $GLOBALS['_wpcarve_test_caps'][9] = ['unfiltered_html' => true];

        // Even an unfiltered_html author gets a sanitized render.
        $this->assertTrue(Plugin::safeForAuthor(9));
    }

    public function testUntrustedAuthorIsSafe(): void
    {
        $this->assertTrue(Plugin::safeForAuthor(7));
    }

    public function testUnknownAuthorIsSafe(): void
    {
        $this->assertTrue(Plugin::safeForAuthor(0));
    }

    public function testStoredSettingCannotDisableSafety(): void
    {
        // A legacy/tampered option value never lifts sanitization.
        $GLOBALS['_wpcarve_test_options'][Settings::OPTION] = ['safe_mode' => false];
        $GLOBALS['_wpcarve_test_caps'][9] = ['unfiltered_html' => true];

        $this->assertTrue(Plugin::safeForAuthor(9));
    }
}
