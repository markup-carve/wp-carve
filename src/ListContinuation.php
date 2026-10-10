<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The Enter-continues-a-list edit shared by the block and classic source
 * editors, so both follow the same marker rules.
 */
final class ListContinuation
{
    /**
     * @var string
     */
    public const HANDLE = 'wpcarve-list-continuation';

    /**
     * @var string
     */
    private const PATH = 'assets/js/list-continuation.js';

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
