<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

use WP_CLI;
use WpCarve\Admin\PostMode;
use WpCarve\Admin\SettingsPage;
use WpCarve\Blocks\CarveBlock;
use WpCarve\CLI\MigrateCommand;
use WpCarve\Ingest\PasteController;
use WpCarve\Meta\FrontmatterMeta;
use WpCarve\Meta\RenderCache;
use WpCarve\Rest\RenderController;

/**
 * Plugin bootstrap: registers all WordPress hooks and wires the feature units.
 */
class Plugin
{
    private Converter $converter;

    public function boot(): void
    {
        load_plugin_textdomain('carve-markup', false, dirname(plugin_basename(WP_CARVE_FILE)) . '/languages');

        $this->converter = Converter::fromSettings();
        $settings = Settings::all();

        // --- Rendering surfaces ---
        if (!empty($settings['enable_shortcode'])) {
            add_shortcode('carve', [$this, 'renderShortcode']);
        }

        if (!empty($settings['enable_comments'])) {
            add_filter('comment_text', [$this, 'renderComment'], 9);
            add_filter('preprocess_comment', [$this, 'storeRawComment']);
        }

        // Whole-post "render as Carve" mode (per-post meta toggle).
        (new PostMode())->register();
        add_filter('the_content', [$this, 'maybeRenderPost'], 9);

        // Gutenberg block (raw Carve attribute -> server render). Innovation A
        // (live preview) is wired in the block's editor script.
        (new CarveBlock($this->converter))->register();

        // --- Innovations ---
        // E: render-on-save caching + REST.
        (new RenderCache($this->converter))->register();
        (new RenderController($this->converter))->register();
        // B: multi-format paste ingest (Markdown/Djot/BBCode/HTML -> Carve).
        if (!empty($settings['paste_ingest'])) {
            (new PasteController())->register();
        }
        // C: frontmatter -> post meta / SEO.
        if (!empty($settings['frontmatter_meta'])) {
            (new FrontmatterMeta())->register();
        }

        // --- Admin + assets + CLI ---
        if (is_admin()) {
            (new SettingsPage())->register();
        }
        add_action('enqueue_block_editor_assets', [$this, 'enqueueEditorAssets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontendAssets']);

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('carve', new MigrateCommand());
        }
    }

    public function renderShortcode(mixed $atts, ?string $content = null): string
    {
        if ($content === null) {
            return '';
        }
        // Shortcode content arrives texturized/auto-paragraphed; undo that.
        $raw = html_entity_decode(wp_strip_all_tags($content, false), ENT_QUOTES, 'UTF-8');

        return $this->converter->toHtml($raw, 'post');
    }

    public function renderComment(string $text): string
    {
        $comment = get_comment();
        if (!$comment) {
            return $text;
        }
        $raw = get_comment_meta((int)$comment->comment_ID, '_wp_carve_raw', true);
        if (!is_string($raw) || $raw === '') {
            return $text;
        }

        return $this->converter->toHtml($raw, 'comment');
    }

    /**
     * @param array<string, mixed> $commentdata
     *
     * @return array<string, mixed>
     */
    public function storeRawComment(array $commentdata): array
    {
        // Persist the raw Carve so re-rendering is lossless. The stored
        // comment_content stays as the rendered/escaped text WordPress expects.
        if (isset($commentdata['comment_content'])) {
            $commentdata['comment_meta']['_wp_carve_raw'] = wp_unslash((string)$commentdata['comment_content']);
        }

        return $commentdata;
    }

    public function maybeRenderPost(string $content): string
    {
        $post = get_post();
        if (!$post || !get_post_meta($post->ID, '_wp_carve_enabled', true)) {
            return $content;
        }

        $cached = RenderCache::read($post->ID);
        if ($cached !== null) {
            $rendered = $cached;
        } else {
            $rendered = $this->converter->toHtml($post->post_content, 'post');
        }

        // Carve already produced block HTML; keep wpautop away from it.
        remove_filter('the_content', 'wpautop');

        return $rendered;
    }

    public function enqueueEditorAssets(): void
    {
        $asset = WP_CARVE_DIR . 'assets/blocks/carve/index.asset.php';
        $deps = ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'];
        $ver = WP_CARVE_VERSION;
        if (is_readable($asset)) {
            $data = require $asset;
            $deps = $data['dependencies'] ?? $deps;
            $ver = $data['version'] ?? $ver;
        }
        // Optional in-browser Carve engine (innovation A). Built by `npm run
        // build` into assets/js/vendor/carve.js (sets window.wpCarveEngine). When
        // absent, the editor falls back to the REST render endpoint.
        $engine = WP_CARVE_DIR . 'assets/js/vendor/carve.js';
        if (Settings::get('live_preview') && is_readable($engine)) {
            wp_enqueue_script('wp-carve-engine', WP_CARVE_URL . 'assets/js/vendor/carve.js', [], WP_CARVE_VERSION, true);
            $deps[] = 'wp-carve-engine';
        }

        wp_enqueue_script(
            'wp-carve-editor',
            WP_CARVE_URL . 'assets/blocks/carve/index.js',
            $deps,
            $ver,
            true,
        );
        wp_enqueue_style('wp-carve', WP_CARVE_URL . 'assets/css/carve.css', [], WP_CARVE_VERSION);
        wp_localize_script('wp-carve-editor', 'wpCarve', [
            'restRender' => esc_url_raw(rest_url('carve/v1/render')),
            'restIngest' => esc_url_raw(rest_url('carve/v1/ingest')),
            'nonce' => wp_create_nonce('wp_rest'),
            'livePreview' => (bool)Settings::get('live_preview'),
            'pasteIngest' => (bool)Settings::get('paste_ingest'),
        ]);
    }

    public function enqueueFrontendAssets(): void
    {
        wp_enqueue_style('wp-carve', WP_CARVE_URL . 'assets/css/carve.css', [], WP_CARVE_VERSION);
    }
}
