<?php

declare(strict_types=1);

namespace WpCarve\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The media library picker's assets, for every editor screen that inserts an
 * image.
 *
 * One place rather than per-screen copies: the classic source editor, the block
 * source editor and the block visual editor all insert images, and the mapping
 * from a library selection to Carve source lives in one script they share.
 */
final class MediaPicker
{
    /**
     * @var string
     */
    public const HANDLE = 'wpcarve-media-picker';

    /**
     * @var string
     */
    private const PATH = 'assets/js/media-picker.js';

    /**
     * Enqueue the picker on an admin editor screen. Safe to call more than
     * once, and never on the front end: the modal is an authoring affordance
     * and `wp_enqueue_media` pulls in a large dependency tree.
     */
    public static function enqueue(): void
    {
        if (!is_admin()) {
            return;
        }

        // Both editors normally bring the media modal with them, but the
        // classic screen hides its media buttons here and the block screen can
        // be reached by surfaces that do not. Enqueuing is idempotent.
        wp_enqueue_media();

        $mtime = @filemtime(WPCARVE_DIR . self::PATH);
        wp_enqueue_script(
            self::HANDLE,
            WPCARVE_URL . self::PATH,
            [],
            $mtime ? (string)$mtime : WPCARVE_VERSION,
            true,
        );
        wp_localize_script(self::HANDLE, 'wpCarveMediaL10n', [
            'frameTitle' => __('Select or upload an image', 'carve-markup'),
            'frameButton' => __('Insert into Carve', 'carve-markup'),
        ]);
    }
}
