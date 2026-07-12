<?php

declare(strict_types=1);

namespace WpCarve\Meta;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Post;
use WpCarve\Converter;
use WpCarve\Plugin;
use WpCarve\Settings;

/**
 * Innovation E: render Carve to HTML at save time and cache it in post meta, so
 * front-end views read pre-rendered HTML instead of parsing on every request.
 */
class RenderCache
{
    /**
     * @var string
     */
    private const META_KEY = '_wpcarve_html';

    /**
     * @var string
     */
    private const VERSION_KEY = '_wpcarve_html_version';

    /**
     * @var string
     */
    private const SAFE_KEY = '_wpcarve_html_safe';

    public function __construct(private Converter $converter)
    {
    }

    public function register(): void
    {
        if (!Settings::get('render_cache')) {
            return;
        }

        add_action('save_post', [$this, 'onSave'], 20, 2);
    }

    public function onSave(int $postId, WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($postId)) {
            return;
        }
        if (!get_post_meta($postId, '_wpcarve_enabled', true)) {
            delete_post_meta($postId, self::META_KEY);

            return;
        }

        // Cache with the SAME per-author safe mode the front end uses, or a warm
        // cache would serve raw HTML for a low-privilege author (bypassing the
        // safeForAuthor gate on the cache-hit path).
        $safe = Plugin::safeForAuthor((int)$post->post_author);
        $html = $this->converter->toHtml($post->post_content, 'post', null, $safe);
        // update_post_meta() expects slashed input and unslashes once when
        // storing; without wp_slash a single backslash (e.g. the `\(` math
        // delimiters carve-php emits) would be eaten. wp_slash protects them.
        update_post_meta($postId, self::META_KEY, wp_slash($html));
        update_post_meta($postId, self::VERSION_KEY, self::signature());
        update_post_meta($postId, self::SAFE_KEY, $safe ? '1' : '0');
    }

    /**
     * Cache fingerprint: the plugin version plus a hash of every
     * render-affecting setting. A plugin/engine upgrade OR any change to a
     * rendering setting (TOC, smart quotes, torchlight theme, diagram toggles,
     * ...) changes this, so read() treats the stored HTML as stale and
     * re-renders on the fly - fixing the case where a settings change left every
     * already-saved post serving its old cached output.
     */
    private static function signature(): string
    {
        return WPCARVE_VERSION . ':' . Settings::renderSignature();
    }

    /**
     * Read the cached HTML for a post, or null when no valid cache exists.
     *
     * $expectedSafe (the current per-author safe mode) invalidates a cache entry
     * that was rendered under a different safe mode - so a stale UNSAFE render
     * can't survive after safe mode is turned on or the author loses
     * unfiltered_html.
     */
    public static function read(int $postId, ?bool $expectedSafe = null): ?string
    {
        if (!Settings::get('render_cache')) {
            return null;
        }
        if (get_post_meta($postId, self::VERSION_KEY, true) !== self::signature()) {
            // Engine/plugin upgraded, or a render-affecting setting changed since
            // caching; re-render on the fly.
            return null;
        }
        if ($expectedSafe !== null && (get_post_meta($postId, self::SAFE_KEY, true) === '1') !== $expectedSafe) {
            return null;
        }
        $html = get_post_meta($postId, self::META_KEY, true);

        return is_string($html) && $html !== '' ? $html : null;
    }
}
