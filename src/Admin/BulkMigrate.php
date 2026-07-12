<?php

declare(strict_types=1);

namespace WpCarve\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Post;
use WP_Post_Type;
use WP_Query;
use WpCarve\Migration\Migrator;

/**
 * Tools -> Carve Migrate: a no-CLI batch migration screen.
 *
 * Lists existing posts with the same safety analysis as `wp carve migrate`
 * (block-editor / shortcode content is flagged, Markdown vs HTML is detected),
 * so the listing itself is the dry-run preview. Selected posts are then
 * converted to Carve and flagged to render as Carve. Guarded by nonce and the
 * `edit_others_posts` capability; each post is additionally re-checked with
 * `edit_post` before it is touched.
 */
class BulkMigrate
{
    /**
     * @var int
     */
    private const PER_PAGE = 50;

    /**
     * @var string
     */
    private const CAP = 'edit_others_posts';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_wpcarve_bulk_migrate', [$this, 'handle']);
    }

    public function menu(): void
    {
        add_management_page(
            __('Carve Migrate', 'carve-markup'),
            __('Carve Migrate', 'carve-markup'),
            self::CAP,
            'wpcarve-migrate',
            [$this, 'page'],
        );
    }

    public function page(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }

        $postType = $this->requestedPostType();
        // Read-only listing navigation (GET): no state changes here, so no nonce
        // is required to page or switch post type.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only listing.
        $paged = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;

        echo '<div class="wrap"><h1>' . esc_html__('Carve Migrate', 'carve-markup') . '</h1>';
        echo '<p>' . esc_html__('Convert existing posts into Carve in bulk. Each post is analyzed first: posts using the block editor or non-trivial shortcodes are flagged and skipped unless you force them. This list is the dry-run preview - nothing changes until you migrate.', 'carve-markup') . '</p>';

        $this->maybeRenderResultNotice();
        $this->renderPostTypeFilter($postType);

        $migrator = new Migrator();
        $query = $this->queryCandidates($postType, $paged);

        if (!$query->posts) {
            echo '<p>' . esc_html__('No convertible posts found for this type.', 'carve-markup') . '</p></div>';

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wpcarve_bulk_migrate');
        echo '<input type="hidden" name="action" value="wpcarve_bulk_migrate">';
        echo '<input type="hidden" name="post_type" value="' . esc_attr($postType) . '">';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<td class="check-column"></td>';
        echo '<th>' . esc_html__('Post', 'carve-markup') . '</th>';
        echo '<th>' . esc_html__('Detected source', 'carve-markup') . '</th>';
        echo '<th>' . esc_html__('Status', 'carve-markup') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($query->posts as $post) {
            if (!$post instanceof WP_Post) {
                continue;
            }
            $analysis = $migrator->analyze($post->ID);
            $eligible = (bool)$analysis['can_auto_migrate'];
            if ($post->post_title !== '') {
                $title = $post->post_title;
            } else {
                /* translators: %d: post ID. */
                $title = sprintf(__('(no title) #%d', 'carve-markup'), $post->ID);
            }
            $editLink = get_edit_post_link($post->ID);

            echo '<tr>';
            echo '<th scope="row" class="check-column">';
            echo '<input type="checkbox" name="post_ids[]" value="' . esc_attr((string)$post->ID) . '"' . ($eligible ? ' checked' : '') . '>';
            echo '</th>';
            echo '<td>';
            echo $editLink
                ? '<a href="' . esc_url($editLink) . '">' . esc_html($title) . '</a>'
                : esc_html($title);
            echo '</td>';
            echo '<td>' . esc_html((string)$analysis['source']) . '</td>';
            echo '<td>' . ($eligible
                ? '<span class="dashicons dashicons-yes" aria-hidden="true"></span> ' . esc_html__('Ready', 'carve-markup')
                : esc_html((string)$analysis['reason']))
                . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p><label><input type="checkbox" name="force" value="1"> '
            . esc_html__('Force: also convert flagged posts (block editor / shortcodes). This can lose content - back up first.', 'carve-markup')
            . '</label></p>';

        $this->renderPagination($query, $postType, $paged);
        submit_button(__('Migrate selected posts', 'carve-markup'));
        echo '</form></div>';
    }

    public function handle(): void
    {
        check_admin_referer('wpcarve_bulk_migrate');
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('You are not allowed to migrate posts.', 'carve-markup'));
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Each id is coerced with absint below.
        $rawIds = isset($_POST['post_ids']) && is_array($_POST['post_ids']) ? $_POST['post_ids'] : [];
        $ids = array_values(array_unique(array_filter(array_map('absint', $rawIds))));
        $force = !empty($_POST['force']);
        $postType = isset($_POST['post_type']) ? sanitize_key((string)wp_unslash($_POST['post_type'])) : 'post';

        $migrator = new Migrator();
        $migrated = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            if (!current_user_can('edit_post', $id) || $migrator->migrate($id, $force) === null) {
                $skipped++;

                continue;
            }
            $migrated++;
        }

        wp_safe_redirect(add_query_arg(
            [
                'page' => 'wpcarve-migrate',
                'post_type' => $postType,
                'migrated' => $migrated,
                'skipped' => $skipped,
            ],
            admin_url('tools.php'),
        ));
        exit;
    }

    private function requestedPostType(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only listing filter.
        $requested = isset($_GET['post_type']) ? sanitize_key((string)wp_unslash($_GET['post_type'])) : 'post';
        $allowed = $this->migratablePostTypes();

        return in_array($requested, $allowed, true) ? $requested : (string)($allowed[0] ?? 'post');
    }

    /**
     * Public, non-block-only post types worth offering for migration.
     *
     * @return array<int, string>
     */
    private function migratablePostTypes(): array
    {
        $types = get_post_types(['public' => true], 'names');
        unset($types['attachment']);

        return array_values($types);
    }

    private function queryCandidates(string $postType, int $paged): WP_Query
    {
        return new WP_Query([
            'post_type' => $postType,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => self::PER_PAGE,
            'paged' => $paged,
            'orderby' => 'ID',
            'order' => 'ASC',
            'ignore_sticky_posts' => true,
            // Skip posts already rendering as Carve - there is nothing to migrate.
            'meta_query' => [
                'relation' => 'OR',
                ['key' => '_wpcarve_enabled', 'compare' => 'NOT EXISTS'],
                ['key' => '_wpcarve_enabled', 'value' => '1', 'compare' => '!='],
            ],
        ]);
    }

    private function renderPostTypeFilter(string $current): void
    {
        $types = $this->migratablePostTypes();
        if (count($types) < 2) {
            return;
        }
        echo '<form method="get" style="margin:1em 0">';
        echo '<input type="hidden" name="page" value="wpcarve-migrate">';
        echo '<label>' . esc_html__('Post type', 'carve-markup') . ' ';
        echo '<select name="post_type" onchange="this.form.submit()">';
        foreach ($types as $type) {
            $obj = get_post_type_object($type);
            $label = $obj instanceof WP_Post_Type ? $obj->labels->name : $type;
            echo '<option value="' . esc_attr($type) . '"' . selected($type, $current, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> ';
        submit_button(__('Filter', 'carve-markup'), 'secondary', '', false);
        echo '</form>';
    }

    private function renderPagination(WP_Query $query, string $postType, int $paged): void
    {
        $total = (int)$query->max_num_pages;
        if ($total < 2) {
            return;
        }
        $links = paginate_links([
            'base' => add_query_arg(['page' => 'wpcarve-migrate', 'post_type' => $postType, 'paged' => '%#%'], admin_url('tools.php')),
            'format' => '',
            'current' => $paged,
            'total' => $total,
            'type' => 'plain',
        ]);
        if (is_string($links) && $links !== '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() returns escaped anchor markup.
            echo '<div class="tablenav"><div class="tablenav-pages">' . $links . '</div></div>';
        }
    }

    private function maybeRenderResultNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only counters after a nonce-checked redirect.
        if (!isset($_GET['migrated']) && !isset($_GET['skipped'])) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only counter.
        $migrated = isset($_GET['migrated']) ? max(0, (int)$_GET['migrated']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only counter.
        $skipped = isset($_GET['skipped']) ? max(0, (int)$_GET['skipped']) : 0;

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: 1: number of migrated posts, 2: number of skipped posts. */
                __('Migrated %1$d post(s), skipped %2$d.', 'carve-markup'),
                $migrated,
                $skipped,
            )),
        );
    }
}
