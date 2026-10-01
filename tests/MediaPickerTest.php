<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use WpCarve\Admin\MediaPicker;

class MediaPickerTest extends TestCase
{
    protected function setUp(): void
    {
        wpcarve_test_reset_enqueued();
    }

    protected function tearDown(): void
    {
        wpcarve_test_reset_enqueued();
    }

    public function testEditorScreenGetsThePickerAndTheMediaModal(): void
    {
        MediaPicker::enqueue();

        $enqueued = $GLOBALS['wpcarve_test_enqueued'];
        self::assertArrayHasKey(MediaPicker::HANDLE, $enqueued['scripts']);
        self::assertSame(1, $enqueued['media']);
        self::assertStringEndsWith('assets/js/media-picker.js', $enqueued['scripts'][MediaPicker::HANDLE]['src']);
    }

    public function testThePickerCarriesItsOwnFrameStrings(): void
    {
        MediaPicker::enqueue();

        $localized = $GLOBALS['wpcarve_test_enqueued']['localized'][MediaPicker::HANDLE]['wpCarveMediaL10n'];
        self::assertArrayHasKey('frameTitle', $localized);
        self::assertArrayHasKey('frameButton', $localized);
    }

    public function testTheFrontEndNeverLoadsTheModal(): void
    {
        wpcarve_test_reset_enqueued(admin: false);

        MediaPicker::enqueue();

        $enqueued = $GLOBALS['wpcarve_test_enqueued'];
        self::assertSame([], $enqueued['scripts']);
        self::assertSame(0, $enqueued['media']);
    }

    public function testTheScriptIsVersionedSoAnUpdateIsNotServedStale(): void
    {
        MediaPicker::enqueue();

        $version = $GLOBALS['wpcarve_test_enqueued']['scripts'][MediaPicker::HANDLE]['ver'];
        self::assertNotSame(WPCARVE_VERSION, $version, 'the hand-maintained version only moves on releases');
        self::assertMatchesRegularExpression('/^\d+$/', (string)$version);
    }
}
