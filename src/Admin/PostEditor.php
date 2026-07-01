<?php

declare(strict_types=1);

namespace WpCarve\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Post;
use WpCarve\Converter;
use WpCarve\Plugin;
use WpCarve\Settings;

/**
 * Proper editing surface for whole-post Carve mode.
 *
 * When a post has `_wp_carve_enabled` (the PostMode toggle), its body is raw
 * Carve source, so the stock rich-text / block editor is meaningless (bold,
 * italics, "convert to blocks"). This swaps such posts to the classic editor
 * rendered as a plain code editor (CodeMirror, no rich toolbar) with a live
 * Carve preview underneath.
 */
class PostEditor
{
    public function __construct(private Converter $converter)
    {
    }

    public function register(): void
    {
        add_filter('use_block_editor_for_post', [$this, 'forceClassic'], 10, 2);
        add_filter('user_can_richedit', [$this, 'disableRichEdit']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('edit_form_after_editor', [$this, 'previewMarkup']);
        add_action('admin_post_wp_carve_to_block', [$this, 'convertToBlock']);
    }

    /**
     * Carve-mode posts use the classic editor (no block UI) so the body is a
     * single editable source field rather than rich-text blocks.
     */
    public function forceClassic(bool $use, WP_Post $post): bool
    {
        if (get_post_meta($post->ID, '_wp_carve_enabled', true)) {
            return false;
        }

        return $use;
    }

    public function disableRichEdit(bool $default): bool
    {
        return $this->editingCarvePost() ? false : $default;
    }

    public function enqueue(string $hook): void
    {
        if (($hook !== 'post.php' && $hook !== 'post-new.php') || !$this->editingCarvePost()) {
            return;
        }

        // CodeMirror as a plain-text source editor (may be false if the user
        // disabled the syntax highlighter in their profile - fall back to the
        // bare textarea, the script handles both).
        $codeEditor = wp_enqueue_code_editor(['type' => 'text/plain']);

        wp_enqueue_style('wp-carve', WP_CARVE_URL . 'assets/css/carve.css', [], $this->assetVersion('assets/css/carve.css'));
        // CodeMirror replaces the textarea, so the classic editor's media
        // buttons, Visual/Text tabs and quicktags toolbar are dead weight for
        // raw Carve source - hide them on this screen only.
        wp_add_inline_style(
            'wp-carve',
            '#wp-content-editor-tools,#qt_content_toolbar{display:none!important}'
            . '#wp-content-editor-container{border-top:1px solid #dcdcde}',
        );

        $deps = ['jquery', 'wp-api-fetch'];
        if ($codeEditor !== false) {
            $deps[] = 'code-editor';
        }

        $engine = WP_CARVE_DIR . 'assets/js/vendor/carve.js';
        if (Settings::get('live_preview') && is_readable($engine)) {
            wp_enqueue_script('wp-carve-engine', WP_CARVE_URL . 'assets/js/vendor/carve.js', [], WP_CARVE_VERSION, true);
            $deps[] = 'wp-carve-engine';
        }

        wp_enqueue_script(
            'wp-carve-code-editor',
            WP_CARVE_URL . 'assets/js/code-editor.js',
            $deps,
            $this->assetVersion('assets/js/code-editor.js'),
            true,
        );
        wp_localize_script('wp-carve-code-editor', 'wpCarve', [
            'restRender' => esc_url_raw(rest_url('carve/v1/render')),
            'livePreview' => (bool)Settings::get('live_preview'),
            'codeEditor' => $codeEditor === false ? null : $codeEditor,
        ]);
    }

    public function previewMarkup(WP_Post $post): void
    {
        if (!$this->editingCarvePost()) {
            return;
        }

        $initial = $this->converter->toHtml((string)$post->post_content, 'post', null, Plugin::safeForAuthor((int)$post->post_author));
        $toBlock = wp_nonce_url(
            admin_url('admin-post.php?action=wp_carve_to_block&post=' . $post->ID),
            'wp_carve_to_block_' . $post->ID,
        );
        printf(
            '<div class="wp-carve-live-preview-wrap">'
            . '<p class="description">%s '
            . '<a href="%s" class="button button-small wp-carve-to-block">%s</a></p>'
            . '<div id="wp-carve-live-preview" class="wp-carve">%s</div></div>',
            esc_html__('Live Carve preview', 'carve-markup'),
            esc_url($toBlock),
            esc_html__('Move into a Carve block', 'carve-markup'),
            // Already-sanitized Carve render (safe mode honors the post profile).
            $initial,
        );
    }

    /**
     * Losslessly wrap the whole-post Carve source in a single carve/markup
     * block and leave whole-post mode, so the post reopens in the normal block
     * editor. The source is unchanged - just moved from post_content into the
     * block's `carve` attribute (Carve in, Carve out, no rendering).
     */
    public function convertToBlock(): void
    {
        $id = isset($_GET['post']) ? (int)$_GET['post'] : 0;
        check_admin_referer('wp_carve_to_block_' . $id);
        if (!$id || !current_user_can('edit_post', $id)) {
            wp_die(esc_html__('You are not allowed to edit this post.', 'carve-markup'));
        }

        $post = get_post($id);
        if ($post) {
            // serialize_block() escapes the JSON attributes (-->, <, & ...) so a
            // literal comment terminator in the source can't break the block.
            $block = serialize_block([
                'blockName' => 'carve/markup',
                'attrs' => ['carve' => (string)$post->post_content],
                'innerBlocks' => [],
                'innerHTML' => '',
                'innerContent' => [],
            ]);
            // wp_update_post unslashes its input, so slash the block markup to
            // keep the JSON escapes (e.g. \n in the carve attribute) intact.
            wp_update_post(['ID' => $id, 'post_content' => wp_slash($block)]);
            delete_post_meta($id, '_wp_carve_enabled');
        }

        wp_safe_redirect(admin_url('post.php?post=' . $id . '&action=edit'));
        exit;
    }

    private function editingCarvePost(): bool
    {
        if (!is_admin()) {
            return false;
        }
        $id = isset($_GET['post']) ? (int)$_GET['post'] : 0;
        if (!$id && isset($_POST['post_ID'])) {
            $id = (int)$_POST['post_ID'];
        }

        return $id > 0 && (bool)get_post_meta($id, '_wp_carve_enabled', true);
    }

    private function assetVersion(string $relPath): string
    {
        $mtime = @filemtime(WP_CARVE_DIR . $relPath);

        return $mtime ? (string)$mtime : WP_CARVE_VERSION;
    }
}
