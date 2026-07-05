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
