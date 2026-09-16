<?php

declare(strict_types=1);

namespace WpCarve\Meta;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Post;
use WpCarve\Converter;
use WpCarve\Includes\IncludePolicy;
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

    /**
     * @var string
     */
    private const INCLUDES_KEY = '_wpcarve_html_includes';

    /**
     * @var string
     */
    public const INCLUDE_DEPS_KEY = '_wpcarve_include_deps';

    /**
     * @var string
     */
    public const INCLUDE_WARNINGS_KEY = '_wpcarve_include_warnings';

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
        $includes = IncludePolicy::reportForPost($post);
        $html = $this->converter->toHtml($post->post_content, 'post', null, $safe, includes: $includes);
        // update_post_meta() expects slashed input and unslashes once when
        // storing; without wp_slash a single backslash (e.g. the `\(` math
        // delimiters carve-php emits) would be eaten. wp_slash protects them.
        update_post_meta($postId, self::META_KEY, wp_slash($html));
        update_post_meta($postId, self::VERSION_KEY, self::signature());
        update_post_meta($postId, self::SAFE_KEY, $safe ? '1' : '0');
        update_post_meta($postId, self::INCLUDES_KEY, $includes !== null ? '1' : '0');

        if ($includes === null || $includes->dependencies() === []) {
            delete_post_meta($postId, self::INCLUDE_DEPS_KEY);
        } else {
            update_post_meta($postId, self::INCLUDE_DEPS_KEY, wp_slash((string)wp_json_encode([
                'dependencies' => $includes->dependencies(),
                'lookups' => $includes->lookups(),
                'fingerprint' => IncludePolicy::fingerprint($includes->root(), $includes->lookups()),
            ])));
        }
        $warnings = $includes?->warnings() ?? [];
        if ($warnings === []) {
            delete_post_meta($postId, self::INCLUDE_WARNINGS_KEY);
        } else {
            update_post_meta($postId, self::INCLUDE_WARNINGS_KEY, wp_slash((string)wp_json_encode($warnings)));
        }
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
        $includesExpected = IncludePolicy::enabled() && IncludePolicy::postTrusted($postId);
        if ((get_post_meta($postId, self::INCLUDES_KEY, true) === '1') !== $includesExpected) {
            return null;
        }
        if (!self::includeTargetsUnchanged($postId)) {
            return null;
        }
        $html = get_post_meta($postId, self::META_KEY, true);

        return is_string($html) && $html !== '' ? $html : null;
    }

    /**
     * Keyed on every lookup the render made, resolved or not: creating a
     * missing file is what makes an include start working.
     */
    private static function includeTargetsUnchanged(int $postId): bool
    {
        $stored = get_post_meta($postId, self::INCLUDE_DEPS_KEY, true);
        if ($stored === '' || $stored === null) {
            return true;
        }
        $deps = is_string($stored) ? json_decode($stored, true) : null;
        if (!is_array($deps) || !is_array($deps['lookups'] ?? null)) {
            return false;
        }
        $lookups = [];
        foreach ($deps['lookups'] as $lookup) {
            if (!is_array($lookup) || !is_string($lookup[0] ?? null)) {
                return false;
            }
            $lookups[] = [$lookup[0], is_string($lookup[1] ?? null) ? $lookup[1] : null];
        }

        return ($deps['fingerprint'] ?? null) === IncludePolicy::fingerprint(IncludePolicy::root(), $lookups);
    }
}
