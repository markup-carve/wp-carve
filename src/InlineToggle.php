<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The shared toggle arithmetic behind the inline-mark toolbar buttons.
 *
 * One place rather than per-screen copies: the block source editor, the classic
 * source editor and the comment toolbar all offer the same marks, and a second
 * click on any of them has to remove the mark instead of doubling the
 * delimiter, which in Carve renders as literal text.
 */
final class InlineToggle
{
    /**
     * @var string
     */
    public const HANDLE = 'wpcarve-inline-toggle';

    /**
     * @var string
     */
    private const PATH = 'assets/js/inline-toggle.js';

    /**
     * Register the script and return its handle, for use in a dependency list.
     * Safe to call more than once.
     */
    public static function register(): string
    {
        if (!wp_script_is(self::HANDLE, 'registered')) {
            $mtime = @filemtime(WPCARVE_DIR . self::PATH);
            wp_register_script(
                self::HANDLE,
                WPCARVE_URL . self::PATH,
                [],
                $mtime ? (string)$mtime : WPCARVE_VERSION,
                true,
            );
        }

        return self::HANDLE;
    }
}
