<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use WpCarve\Plugin;
use WpCarve\Settings;

/**
 * Guards the raw-HTML capability gate (safeForAuthor): safe mode may only be
 * lifted for authors who can post unfiltered HTML, and only when the global
 * setting is off.
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

    public function testSafeModeOnAlwaysForcesSafe(): void
    {
        $GLOBALS['_wpcarve_test_options'][Settings::OPTION] = ['safe_mode' => true];
        $GLOBALS['_wpcarve_test_caps'][5] = ['unfiltered_html' => true];

        // Even a trusted author is safe while the setting is on.
        $this->assertTrue(Plugin::safeForAuthor(5));
    }

    public function testSafeModeOffStillSafeForUntrustedAuthor(): void
    {
        $GLOBALS['_wpcarve_test_options'][Settings::OPTION] = ['safe_mode' => false];

        // No unfiltered_html capability -> still forced safe.
        $this->assertTrue(Plugin::safeForAuthor(7));
    }

    public function testSafeModeOffAllowsRawForTrustedAuthor(): void
    {
        $GLOBALS['_wpcarve_test_options'][Settings::OPTION] = ['safe_mode' => false];
        $GLOBALS['_wpcarve_test_caps'][9] = ['unfiltered_html' => true];

        $this->assertFalse(Plugin::safeForAuthor(9));
    }

    public function testUnknownAuthorIsSafe(): void
    {
        $GLOBALS['_wpcarve_test_options'][Settings::OPTION] = ['safe_mode' => false];

        // Author id 0 (no post context) fails closed to safe.
        $this->assertTrue(Plugin::safeForAuthor(0));
    }
}
