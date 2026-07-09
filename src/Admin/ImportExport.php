<?php

declare(strict_types=1);

namespace WpCarve\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use WP_Post;

/**
 * Import a Markdown / Djot / HTML / Carve file as a new Carve post, and export a
 * post's Carve source as a `.crv` file.
 *
 * Import lives on Tools -> Carve Import; export is a row action on the posts
 * list. Both are admin-post handlers guarded by nonce + capability.
 */
class ImportExport
{
    /**
     * @var int
     */
    private const MAX_IMPORT_BYTES = 2 * 1024 * 1024;

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_wpcarve_import', [$this, 'handleImport']);
        add_action('admin_post_wpcarve_export', [$this, 'handleExport']);
        add_filter('post_row_actions', [$this, 'rowAction'], 10, 2);
        add_filter('page_row_actions', [$this, 'rowAction'], 10, 2);
    }

    public function menu(): void
    {
        add_management_page(
            __('Carve Import', 'carve-markup'),
            __('Carve Import', 'carve-markup'),
            'edit_posts',
            'wpcarve-import',
            [$this, 'importPage'],
        );
    }

    public function importPage(): void
    {
        if (!current_user_can('edit_posts')) {
            return;
        }
        $url = admin_url('admin-post.php');
        echo '<div class="wrap"><h1>' . esc_html__('Carve Import', 'carve-markup') . '</h1>';
        echo '<p>' . esc_html__('Upload a Markdown, Djot, HTML or Carve file. It is converted to Carve and saved as a draft with "Render as Carve" enabled.', 'carve-markup') . '</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url($url) . '">';
        wp_nonce_field('wpcarve_import');
        echo '<input type="hidden" name="action" value="wpcarve_import">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="wpcarve_file">' . esc_html__('File', 'carve-markup') . '</label></th>';
        echo '<td><input type="file" name="wpcarve_file" id="wpcarve_file" accept=".md,.markdown,.djot,.dj,.crv,.html,.htm,.txt" required></td></tr>';
        echo '<tr><th><label for="wpcarve_title">' . esc_html__('Title', 'carve-markup') . '</label></th>';
        echo '<td><input type="text" name="wpcarve_title" id="wpcarve_title" class="regular-text" placeholder="' . esc_attr__('Defaults to the file name', 'carve-markup') . '"></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Import', 'carve-markup'));
        echo '</form></div>';
    }

    public function handleImport(): void
    {
        check_admin_referer('wpcarve_import');
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You are not allowed to import.', 'carve-markup'));
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The upload error code is cast to int and compared numerically.
        if (!isset($_FILES['wpcarve_file']) || !is_array($_FILES['wpcarve_file']) || (int)($_FILES['wpcarve_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            wp_die(esc_html__('No file was uploaded.', 'carve-markup'));
        }
        $tmp = isset($_FILES['wpcarve_file']['tmp_name'])
            ? sanitize_text_field(wp_unslash($_FILES['wpcarve_file']['tmp_name']))
            : '';
        $name = sanitize_file_name(wp_unslash($_FILES['wpcarve_file']['name'] ?? 'import'));
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            wp_die(esc_html__('Invalid upload.', 'carve-markup'));
        }
        // Cap the import size (source documents are small); avoids reading a huge
        // file into memory.
        if ((int)filesize($tmp) > self::MAX_IMPORT_BYTES) {
            wp_die(esc_html__('That file is too large to import.', 'carve-markup'));
        }
        $raw = (string)file_get_contents($tmp);
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $carve = $this->toCarve($raw, $ext);

        $title = sanitize_text_field(wp_unslash($_POST['wpcarve_title'] ?? ''));
        if ($title === '') {
            $title = (string)pathinfo($name, PATHINFO_FILENAME);
        }

        $postId = wp_insert_post([
            'post_title' => $title,
            'post_content' => wp_slash($carve),
            'post_status' => 'draft',
        ], true);
        if (is_wp_error($postId) || !$postId) {
            wp_die(esc_html__('Could not create the post.', 'carve-markup'));
        }
        update_post_meta((int)$postId, '_wpcarve_enabled', 1);

        wp_safe_redirect(admin_url('post.php?post=' . (int)$postId . '&action=edit'));
        exit;
    }

    private function toCarve(string $raw, string $ext): string
    {
        return match ($ext) {
            'md', 'markdown', 'txt' => (new MarkdownToCarve())->convert($raw),
            'djot', 'dj' => (new DjotToCarve())->convert($raw),
            'html', 'htm' => (new HtmlToCarve())->convert($raw),
            default => $raw, // .crv (already Carve)
        };
    }

    /**
     * @param array<string, string> $actions
     * @param \WP_Post $post
     *
     * @return array<string, string>
     */
    public function rowAction(array $actions, WP_Post $post): array
    {
        if (!current_user_can('edit_post', $post->ID)) {
            return $actions;
        }
        $isCarve = get_post_meta($post->ID, '_wpcarve_enabled', true)
            || has_block('carve/markup', $post)
            || has_block('carve/slides', $post);
        if (!$isCarve) {
            return $actions;
        }
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=wpcarve_export&post=' . $post->ID),
            'wpcarve_export_' . $post->ID,
        );
        $actions['wpcarve_export'] = '<a href="' . esc_url($url) . '">' . esc_html__('Export Carve', 'carve-markup') . '</a>';

        return $actions;
    }

    public function handleExport(): void
    {
        $id = isset($_GET['post']) ? (int)$_GET['post'] : 0;
        check_admin_referer('wpcarve_export_' . $id);
        if (!$id || !current_user_can('edit_post', $id)) {
            wp_die(esc_html__('You are not allowed to export this post.', 'carve-markup'));
        }
        $post = get_post($id);
        if (!$post instanceof WP_Post) {
            wp_die(esc_html__('Post not found.', 'carve-markup'));
        }
        $source = $this->extractSource($post);
        $slug = $post->post_name !== '' ? $post->post_name : 'carve-' . $id;

        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . esc_attr($slug) . '.crv"');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw Carve source streamed as a text/plain file download, not HTML.
        echo $source;
        exit;
    }

    private function extractSource(WP_Post $post): string
    {
        $content = (string)$post->post_content;
        if (!has_block('carve/markup', $post)) {
            return $content;
        }
        // Pull the carve attribute out of each carve/markup block.
        $out = [];
        foreach (parse_blocks($content) as $block) {
            if (($block['blockName'] ?? '') === 'carve/markup' && isset($block['attrs']['carve'])) {
                $out[] = (string)$block['attrs']['carve'];
            }
        }

        return $out !== [] ? implode("\n\n", $out) : $content;
    }
}
