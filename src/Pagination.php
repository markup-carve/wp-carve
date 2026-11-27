<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Post;

/**
 * `<!--nextpage-->` support for whole-post Carve mode.
 *
 * WordPress splits post_content on the marker in setup_postdata() and serves
 * one chunk per page; wp_link_pages() prints the page links. The post render
 * has to follow that split, or every page shows the whole post.
 */
class Pagination
{
    /**
     * @var string
     */
    public const MARKER = '<!--nextpage-->';

    /**
     * The Carve source of the page WordPress is showing, or null when the
     * post is not paginated (or the globals belong to another post).
     */
    public static function currentPageSource(WP_Post $post): ?string
    {
        $pages = $GLOBALS['pages'] ?? null;
        if (empty($GLOBALS['multipage']) || !is_array($pages) || count($pages) < 2) {
            return null;
        }
        // setup_postdata() sets $id with $pages; a render for another post
        // (a widget, a nested query) must not pick up this post's pages.
        if ((int)($GLOBALS['id'] ?? 0) !== $post->ID) {
            return null;
        }
        $page = min(max(1, (int)($GLOBALS['page'] ?? 1)), count($pages));

        return (string)$pages[$page - 1];
    }

    /**
     * Whole-post source with the markers taken out, for renders that show the
     * post as one piece (excerpts). Unremoved, a marker renders as visible
     * `<!--nextpage-->` text because raw HTML is off.
     */
    public static function withoutMarkers(string $source): string
    {
        if (!str_contains($source, self::MARKER)) {
            return $source;
        }

        return str_replace(self::MARKER, "\n", $source);
    }
}
