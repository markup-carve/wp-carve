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
 * When a post has `_wpcarve_enabled` (the PostMode toggle), its body is raw
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
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_wpcarve_new_document', [$this, 'createDocument']);
        add_filter('use_block_editor_for_post', [$this, 'forceClassic'], 10, 2);
        add_filter('user_can_richedit', [$this, 'disableRichEdit']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('edit_form_after_title', [$this, 'documentHeader']);
        add_action('edit_form_after_editor', [$this, 'previewMarkup']);
        add_action('admin_post_wpcarve_to_block', [$this, 'convertToBlock']);
        add_action('admin_post_wpcarve_to_document', [$this, 'convertToDocument']);
    }

    public function menu(): void
    {
        add_posts_page(
            __('Add Carve Document', 'carve-markup'),
            __('Add Carve Document', 'carve-markup'),
            'edit_posts',
            'wpcarve-new-document',
            [$this, 'newDocumentPage'],
        );
    }

    public function newDocumentPage(): void
    {
        if (!current_user_can('edit_posts')) {
            return;
        }

        echo '<div class="wrap"><h1>' . esc_html__('Add Carve Document', 'carve-markup') . '</h1>';
        echo '<p>' . esc_html__('Create a source-first article whose body is stored as portable Carve markup. You can also import an existing .crv file under Tools → Carve Import.', 'carve-markup') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wpcarve_new_document');
        echo '<input type="hidden" name="action" value="wpcarve_new_document">';
        echo '<label for="wpcarve_document_title" class="screen-reader-text">' . esc_html__('Title', 'carve-markup') . '</label>';
        echo '<input id="wpcarve_document_title" name="title" type="text" class="regular-text" placeholder="' . esc_attr__('Document title', 'carve-markup') . '">';
        submit_button(__('Create Carve Document', 'carve-markup'));
        echo '</form></div>';
    }

    public function createDocument(): void
    {
        check_admin_referer('wpcarve_new_document');
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You are not allowed to create posts.', 'carve-markup'));
        }

        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $postId = wp_insert_post([
            'post_title' => $title,
            'post_content' => '',
            'post_status' => 'draft',
            'post_type' => 'post',
        ], true);
        if (is_wp_error($postId) || !$postId) {
            wp_die(esc_html__('Could not create the Carve document.', 'carve-markup'));
        }
        update_post_meta((int)$postId, '_wpcarve_enabled', 1);
        wp_safe_redirect(admin_url('post.php?post=' . (int)$postId . '&action=edit'));
        exit;
    }

    /**
     * Carve-mode posts use the classic editor (no block UI) so the body is a
     * single editable source field rather than rich-text blocks.
     */
    public function forceClassic(bool $use, WP_Post $post): bool
    {
        if (get_post_meta($post->ID, '_wpcarve_enabled', true)) {
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

        wp_enqueue_style('wpcarve', WPCARVE_URL . 'assets/css/carve.css', [], $this->assetVersion('assets/css/carve.css'));
        // CodeMirror replaces the textarea, so the classic editor's media
        // buttons, Visual/Text tabs and quicktags toolbar are dead weight for
        // raw Carve source - hide them on this screen only.
        wp_add_inline_style(
            'wpcarve',
            '#wp-content-editor-tools,#qt_content_toolbar{display:none!important}'
            . '#wp-content-editor-container{border-top:1px solid #dcdcde}',
        );

        $deps = ['jquery', 'wp-api-fetch'];
        if ($codeEditor !== false) {
            $deps[] = 'code-editor';
        }

        $engine = WPCARVE_DIR . 'assets/js/vendor/carve.js';
        if (Settings::get('live_preview') && is_readable($engine)) {
            wp_enqueue_script('wpcarve-engine', WPCARVE_URL . 'assets/js/vendor/carve.js', [], $this->assetVersion('assets/js/vendor/carve.js'), true);
            $deps[] = 'wpcarve-engine';
        }

        wp_enqueue_script(
            'wpcarve-code-editor',
            WPCARVE_URL . 'assets/js/code-editor.js',
            $deps,
            $this->assetVersion('assets/js/code-editor.js'),
            true,
        );
        wp_localize_script('wpcarve-code-editor', 'wpCarve', [
            'restRender' => esc_url_raw(rest_url('carve/v1/render')),
            'livePreview' => (bool)Settings::get('live_preview'),
            'codeEditor' => $codeEditor === false ? null : $codeEditor,
        ]);
    }

    public function documentHeader(WP_Post $post): void
    {
        if (!$this->editingCarvePost()) {
            return;
        }

        $download = wp_nonce_url(
            admin_url('admin-post.php?action=wpcarve_export&post=' . $post->ID),
            'wpcarve_export_' . $post->ID,
        );
        $toBlock = wp_nonce_url(
            admin_url('admin-post.php?action=wpcarve_to_block&post=' . $post->ID),
            'wpcarve_to_block_' . $post->ID,
        );
        echo '<section class="wpcarve-document-header" aria-label="' . esc_attr__('Carve Document editor', 'carve-markup') . '">';
        echo '<div class="wpcarve-document-heading"><strong>' . esc_html__('Carve Document', 'carve-markup') . '</strong>';
        echo '<span>' . esc_html__('Portable .crv source', 'carve-markup') . '</span>';
        echo '<a class="button button-small" href="' . esc_url($download) . '">' . esc_html__('Download .crv', 'carve-markup') . '</a>';
        echo '<a class="button button-small wpcarve-to-block" href="' . esc_url($toBlock) . '">' . esc_html__('Move into a Carve block', 'carve-markup') . '</a></div>';
        echo '<div class="wpcarve-document-modes" role="tablist" aria-label="' . esc_attr__('Editor view', 'carve-markup') . '">';
        foreach (['write' => __('Write', 'carve-markup'), 'split' => __('Split', 'carve-markup'), 'preview' => __('Preview', 'carve-markup')] as $mode => $label) {
            printf('<button type="button" class="button%s" data-wpcarve-mode="%s" role="tab">%s</button>', $mode === 'write' ? ' button-primary' : '', esc_attr($mode), esc_html($label));
        }
        echo '<label class="wpcarve-scroll-sync"><input type="checkbox" checked> ' . esc_html__('Scroll sync', 'carve-markup') . '</label>';
        echo '</div><div class="wpcarve-document-toolbar" role="toolbar" aria-label="' . esc_attr__('Carve formatting', 'carve-markup') . '">';
        $buttons = [
            ['', '', __('Heading 2', 'carve-markup'), 'H2', '## ', 'heading'],
            ['*', '*', __('Strong', 'carve-markup'), '<strong>B</strong>'],
            ['/', '/', __('Emphasis', 'carve-markup'), '<em>I</em>'],
            ['_', '_', __('Underline', 'carve-markup'), '<span class="wpcarve-tool-underline">U</span>'],
            ['`', '`', __('Inline code', 'carve-markup'), '&lt;/&gt;'],
            ['[', '](https://)', __('Link', 'carve-markup'), __('Link', 'carve-markup')],
            ['![', '](https://)', __('Image', 'carve-markup'), __('Image', 'carve-markup')],
            ['', '', __('Blockquote', 'carve-markup'), __('Quote', 'carve-markup'), '> ', 'prefix'],
            ['', '', __('Bullet list', 'carve-markup'), __('Bullets', 'carve-markup'), '- ', 'prefix'],
            ['', '', __('Ordered list', 'carve-markup'), __('Numbered', 'carve-markup'), '1. ', 'prefix'],
            ['', '', __('Task list', 'carve-markup'), __('Task', 'carve-markup'), '- [ ] ', 'prefix'],
            ['', '', __('Code block', 'carve-markup'), __('Code', 'carve-markup'), "```\n\n```", 'block'],
            ['', '', __('Table', 'carve-markup'), __('Table', 'carve-markup'), "|= Heading |= Value |\n| Cell | Cell |", 'block'],
        ];
        foreach ($buttons as $index => $button) {
            if ($index === 5 || $index === 7) {
                echo '<span class="wpcarve-tool-separator" aria-hidden="true"></span>';
            }
            $insert = $button[4] ?? '';
            printf(
                '<button type="button" class="button wpcarve-tool-button" title="%s" aria-label="%s" data-wpcarve-action="%s" data-wpcarve-open="%s" data-wpcarve-close="%s" data-wpcarve-insert="%s">%s</button>',
                esc_attr((string)$button[2]),
                esc_attr((string)$button[2]),
                esc_attr((string)($button[5] ?? 'wrap')),
                esc_attr((string)$button[0]),
                esc_attr((string)$button[1]),
                esc_attr((string)$insert),
                wp_kses_post((string)$button[3]),
            );
        }
        echo '<label class="screen-reader-text" for="wpcarve-more-insert">' . esc_html__('More Carve elements', 'carve-markup') . '</label>';
        echo '<select id="wpcarve-more-insert" class="wpcarve-more-insert">';
        echo '<option value="">' . esc_html__('More…', 'carve-markup') . '</option>';
        $more = [
            __('Admonition', 'carve-markup') => "::: note\n\n:::",
            __('Disclosure', 'carve-markup') => "::: details \"Summary\"\n\n:::",
            __('Media embed', 'carve-markup') => ':media[https://]',
            __('Divider', 'carve-markup') => '---',
            __('Footnote', 'carve-markup') => '^[note]',
            __('Inline math', 'carve-markup') => '$`x`',
            __('Citation', 'carve-markup') => '[@key]',
            __('Definition list', 'carve-markup') => ":: Term\n:  Definition",
        ];
        foreach ($more as $label => $insert) {
            echo '<option value="' . esc_attr($insert) . '">' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div></section>';
    }

    public function previewMarkup(WP_Post $post): void
    {
        if (!$this->editingCarvePost()) {
            return;
        }

        $initial = $this->converter->toHtml((string)$post->post_content, 'post', null, Plugin::safeForAuthor((int)$post->post_author));
        printf(
            '<div class="wpcarve-live-preview-wrap" data-wpcarve-panel="preview">'
            . '<p class="description"><strong>%s</strong></p>'
            . '<div id="wpcarve-live-preview" class="wpcarve">%s</div></div>',
            esc_html__('Live Carve preview', 'carve-markup'),
            wp_kses($initial, Converter::allowedHtml()),
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
        check_admin_referer('wpcarve_to_block_' . $id);
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
            delete_post_meta($id, '_wpcarve_enabled');
        }

        wp_safe_redirect(admin_url('post.php?post=' . $id . '&action=edit'));
        exit;
    }

    /**
     * Move a post containing exactly one Carve block back into source-first
     * Carve Document mode. Refuse mixed/multiple block posts: silently dropping
     * sibling blocks would make the reverse action destructive.
     */
    public function convertToDocument(): void
    {
        $id = isset($_GET['post']) ? (int)$_GET['post'] : 0;
        check_admin_referer('wpcarve_to_document_' . $id);
        if (!$id || !current_user_can('edit_post', $id)) {
            wp_die(esc_html__('You are not allowed to edit this post.', 'carve-markup'));
        }

        $post = get_post($id);
        $source = $post instanceof WP_Post ? $this->singleCarveBlockSource($post) : null;
        if ($source === null) {
            wp_die(esc_html__('This post must contain exactly one Carve block before it can become a Carve Document.', 'carve-markup'));
        }

        wp_update_post(['ID' => $id, 'post_content' => wp_slash($source)]);
        update_post_meta($id, '_wpcarve_enabled', 1);
        foreach (['_wpcarve_html', '_wpcarve_html_version', '_wpcarve_html_safe'] as $key) {
            delete_post_meta($id, $key);
        }

        wp_safe_redirect(admin_url('post.php?post=' . $id . '&action=edit'));
        exit;
    }

    public function singleCarveBlockSource(WP_Post $post): ?string
    {
        $blocks = parse_blocks((string)$post->post_content);
        $blocks = array_values(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['blockName'] ?? null) !== null
                || trim((string)($block['innerHTML'] ?? '')) !== '',
        ));
        if (
            count($blocks) !== 1
            || ($blocks[0]['blockName'] ?? '') !== 'carve/markup'
            || !isset($blocks[0]['attrs']['carve'])
            || !is_string($blocks[0]['attrs']['carve'])
        ) {
            return null;
        }

        return $blocks[0]['attrs']['carve'];
    }

    private function editingCarvePost(): bool
    {
        if (!is_admin()) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: detect which post the editor is on to render a preview; no state change.
        $id = isset($_GET['post']) ? (int)$_GET['post'] : 0;
        if (!$id) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only post-ID detection for the editor screen; no state change.
            $id = isset($_POST['post_ID']) ? (int)$_POST['post_ID'] : 0;
        }

        return $id > 0 && (bool)get_post_meta($id, '_wpcarve_enabled', true);
    }

    private function assetVersion(string $relPath): string
    {
        $mtime = @filemtime(WPCARVE_DIR . $relPath);

        return $mtime ? (string)$mtime : WPCARVE_VERSION;
    }
}
