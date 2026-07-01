<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

use WP_CLI;
use WP_Post;
use WpCarve\Admin\ImportExport;
use WpCarve\Admin\PostEditor;
use WpCarve\Admin\PostMode;
use WpCarve\Admin\SettingsPage;
use WpCarve\Blocks\CarveBlock;
use WpCarve\Blocks\SlidesBlock;
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
        if (!empty($settings['enable_excerpts'])) {
            add_filter('get_the_excerpt', [$this, 'maybeRenderExcerpt'], 9);
        }

        // Gutenberg block (raw Carve attribute -> server render). Innovation A
        // (live preview) is wired in the block's editor script.
        (new CarveBlock($this->converter))->register();
        (new SlidesBlock($this->converter))->register();

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
            // Whole-post Carve mode gets a plain code editor + live preview
            // instead of the rich-text/block editor.
            (new PostEditor($this->converter))->register();
            (new ImportExport())->register();
        }
        add_action('enqueue_block_editor_assets', [$this, 'enqueueEditorAssets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontendAssets']);
        add_filter('script_loader_tag', [$this, 'deferFrontendScripts'], 10, 2);
        add_action('wp_head', [$this, 'autoOgImage'], 5);
        add_filter('wp_carve_rendered_html', [$this, 'oembedBridge']);

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
        $safe = self::safeForAuthor((int)get_post_field('post_author', get_the_ID()));

        return $this->wrap($this->converter->toHtml($raw, 'post', null, $safe));
    }

    /**
     * Wrap rendered Carve HTML so the `.wp-carve` stylesheet (admonitions,
     * permalinks, code) applies on every surface.
     */
    private function wrap(string $html): string
    {
        return $html === '' ? '' : '<div class="wp-carve">' . $html . '</div>';
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

        return $this->wrap($this->converter->toHtml($raw, 'comment'));
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

    public function maybeRenderExcerpt(string $excerpt): string
    {
        $post = get_post();
        if (!$post || !get_post_meta($post->ID, '_wp_carve_enabled', true)) {
            return $excerpt;
        }
        $src = trim((string)$post->post_excerpt) !== '' ? $post->post_excerpt : $post->post_content;
        $html = $this->converter->toHtml($src, 'post');

        return wp_trim_words(wp_strip_all_tags($html), 55);
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
            $rendered = $this->converter->toHtml($post->post_content, 'post', null, self::safeForAuthor((int)$post->post_author));
        }

        // Carve already produced block HTML; keep wpautop away from it.
        remove_filter('the_content', 'wpautop');

        return $this->wrap($rendered);
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
        wp_enqueue_style('wp-carve', WP_CARVE_URL . 'assets/css/carve.css', [], $this->assetVersion('assets/css/carve.css'));
        wp_enqueue_script('wp-carve-slides', WP_CARVE_URL . 'assets/js/slides.js', [], $this->assetVersion('assets/js/slides.js'), true);
        wp_localize_script('wp-carve-editor', 'wpCarve', [
            'restRender' => esc_url_raw(rest_url('carve/v1/render')),
            'restIngest' => esc_url_raw(rest_url('carve/v1/ingest')),
            'nonce' => wp_create_nonce('wp_rest'),
            'livePreview' => (bool)Settings::get('live_preview'),
            'pasteIngest' => (bool)Settings::get('paste_ingest'),
            // Foundation Tiptap visual editor: URL of the lazy-loaded ES module
            // (empty string hides the Visual tab in the block). The ?ver query
            // is threaded through the whole module graph (via import.meta.url)
            // so edits to any tiptap file bust the browser's ES-module cache.
            'visualEditor' => Settings::get('visual_editor_mode') !== 'disabled'
                ? esc_url_raw(WP_CARVE_URL . 'assets/js/tiptap/visual-editor.js') . '?ver=' . $this->tiptapVersion()
                : '',
            // Default mode when a Carve block is opened.
            'startMode' => Settings::get('visual_editor_mode') === 'enabled_default' ? 'visual' : 'write',
        ]);
    }

    /**
     * Cache-busting version for a bundled asset: its mtime, so edits show
     * immediately and a browser never holds a stale copy. Falls back to the
     * plugin version.
     */

    /**
     * Defer the plugin's front-end scripts (they enhance already-rendered
     * markup, so nothing needs them before paint). Editor scripts are left
     * untouched. Uses the tag filter so it works on WordPress < 6.3 too.
     */
    public function deferFrontendScripts(string $tag, string $handle): string
    {
        if (is_admin() || strncmp($handle, 'wp-carve', 8) !== 0) {
            return $tag;
        }
        if (str_contains($tag, ' defer') || str_contains($tag, ' async')) {
            return $tag;
        }

        return str_replace('<script ', '<script defer ', $tag);
    }

    /**
     * oEmbed fallback: when the media-embed extension is off, a standalone
     * `:youtube[ID]` / `:vimeo[ID]` / `:media[URL]` renders as an `ext-*` span.
     * Turn those into WordPress core oEmbeds so any oEmbed provider works even
     * without carve-php-media-embed. No-op when media-embed produced iframes.
     */
    public function oembedBridge(string $html): string
    {
        if (!apply_filters('wp_carve_media_oembed', true) || !str_contains($html, 'ext-')) {
            return $html;
        }

        $out = preg_replace_callback(
            '#<p>\s*<span class="ext-(youtube|vimeo|media)">([^<]+)</span>\s*</p>#i',
            static function (array $m): string {
                $type = strtolower($m[1]);
                $arg = html_entity_decode(trim($m[2]), ENT_QUOTES, 'UTF-8');
                $url = match ($type) {
                    'youtube' => 'https://www.youtube.com/watch?v=' . $arg,
                    'vimeo' => 'https://vimeo.com/' . $arg,
                    default => $arg,
                };
                $embed = wp_oembed_get($url);

                return $embed ? '<div class="wp-carve-oembed">' . $embed . '</div>' : $m[0];
            },
            $html,
        );

        return $out ?? $html;
    }

    /**
     * Fallback OG image for Carve posts without a featured image: the first
     * `![](url)` in the source. Skipped if a featured image exists or the
     * `wp_carve_auto_og_image` filter returns false.
     */
    public function autoOgImage(): void
    {
        if (!is_singular() || has_post_thumbnail()) {
            return;
        }
        if (!apply_filters('wp_carve_auto_og_image', true)) {
            return;
        }
        $post = get_post();
        if (!$post instanceof WP_Post) {
            return;
        }
        $isCarve = get_post_meta($post->ID, '_wp_carve_enabled', true)
            || has_block('carve/markup', $post)
            || has_block('carve/slides', $post);
        if (!$isCarve) {
            return;
        }
        if (!preg_match('/!\[[^\]]*\]\(\s*([^)\s]+)/', (string)$post->post_content, $m)) {
            return;
        }

        printf('<meta property="og:image" content="%s">' . "\n", esc_url($m[1]));
    }

    /**
     * Effective safe mode for content by a given author. Safe mode stays forced
     * on unless the site setting is off AND the author may post unfiltered HTML
     * - so a low-privilege author can never get raw-HTML passthrough even when
     * an admin disabled safe mode globally.
     */
    public static function safeForAuthor(?int $authorId): bool
    {
        if ((bool)Settings::get('safe_mode')) {
            return true;
        }

        return !($authorId && user_can($authorId, 'unfiltered_html'));
    }

    private function assetVersion(string $relPath): string
    {
        $mtime = @filemtime(WP_CARVE_DIR . $relPath);

        return $mtime ? (string)$mtime : WP_CARVE_VERSION;
    }

    /**
     * Cache-busting version for the whole tiptap ES-module graph: the newest
     * mtime across its files, so editing any one busts the browser cache for
     * all of them (the query is forwarded through import.meta.url).
     */
    private function tiptapVersion(): string
    {
        $newest = 0;
        $dir = WP_CARVE_DIR . 'assets/js/tiptap';
        // Two levels (tiptap/*.js + tiptap/extensions/*.js) without GLOB_BRACE,
        // which is missing on some PHP builds (musl/Alpine).
        $files = array_merge(glob($dir . '/*.js') ?: [], glob($dir . '/*/*.js') ?: []);
        foreach ($files as $file) {
            $newest = max($newest, (int)@filemtime($file));
        }

        return $newest > 0 ? (string)$newest : WP_CARVE_VERSION;
    }

    public function enqueueFrontendAssets(): void
    {
        wp_enqueue_style('wp-carve', WP_CARVE_URL . 'assets/css/carve.css', [], $this->assetVersion('assets/css/carve.css'));

        if (!is_singular()) {
            return;
        }

        // Comment toolbar (independent of whether the post itself is Carve).
        if (Settings::get('enable_comments') && comments_open()) {
            wp_enqueue_script('wp-carve-comment-toolbar', WP_CARVE_URL . 'assets/js/comment-toolbar.js', [], $this->assetVersion('assets/js/comment-toolbar.js'), true);
        }

        $post = get_post();
        // Carve is present either as whole-post mode (meta) or a Carve block;
        // all such surfaces need the shared enhancements.
        $hasCarveBlock = $post && (has_block('carve/markup', $post) || has_block('carve/slides', $post));
        if (!$post || (!get_post_meta($post->ID, '_wp_carve_enabled', true) && !$hasCarveBlock)) {
            return;
        }
        $content = (string)$post->post_content;

        // Code-block enhancements (copy button, optional line numbers) - only
        // when the content actually has a fenced block.
        if (str_contains($content, '```') || str_contains($content, '~~~')) {
            wp_enqueue_script('wp-carve-code', WP_CARVE_URL . 'assets/js/code-blocks.js', [], $this->assetVersion('assets/js/code-blocks.js'), true);
        }

        // Heading permalink click-to-copy.
        if (Settings::get('permalinks_enabled')) {
            wp_enqueue_script('wp-carve-permalink', WP_CARVE_URL . 'assets/js/permalink.js', [], $this->assetVersion('assets/js/permalink.js'), true);
        }

        if (has_block('carve/slides', $post)) {
            wp_enqueue_script('wp-carve-slides', WP_CARVE_URL . 'assets/js/slides.js', [], $this->assetVersion('assets/js/slides.js'), true);
        }

        // Diagram renderers (Mermaid, Chart.js, Vega-Lite, ...). Each type's
        // library loads only when the type is enabled AND the page uses it; the
        // shared diagrams.js initializes whichever libraries are present. Local
        // vendored builds (no CDN) keep requests on this host.
        $needDiagrams = false;
        /** @var array<string, array<int, string>> $diagramInits */
        $diagramInits = [];
        foreach (Diagrams::all() as $name => $diagram) {
            if (!Settings::get(Diagrams::settingKey($name))) {
                continue;
            }
            // $content is the raw Carve source, so match the fence word (which
            // is also the rendered CSS class), not the rendered markup.
            $class = (string)($diagram['class'] ?? $name);
            if (!str_contains($content, $class)) {
                continue;
            }
            $needDiagrams = true;
            $i = 0;
            $lastHandle = null;
            foreach ((array)($diagram['libs'] ?? []) as $lib) {
                $handle = 'wp-carve-diagram-' . $name . '-' . $i;
                $src = (string)apply_filters(
                    'wp_carve_diagram_src',
                    WP_CARVE_URL . 'assets/js/vendor/' . $lib,
                    $name,
                    $lib,
                );
                wp_enqueue_script($handle, esc_url_raw($src), [], WP_CARVE_VERSION, true);
                $lastHandle = $handle;
                $i++;
            }
            foreach ((array)($diagram['src'] ?? []) as $url) {
                $handle = 'wp-carve-diagram-' . $name . '-ext-' . $i;
                wp_enqueue_script($handle, esc_url_raw((string)$url), [], WP_CARVE_VERSION, true);
                $lastHandle = $handle;
                $i++;
            }
            // A custom renderer's init runs after its own libraries (attached to
            // the last one); with no libraries it rides the shared diagrams.js.
            if (!empty($diagram['init']) && is_string($diagram['init'])) {
                $diagramInits[$lastHandle ?? 'wp-carve-diagrams'][] = $diagram['init'];
            }
        }
        if ($needDiagrams) {
            wp_enqueue_script('wp-carve-diagrams', WP_CARVE_URL . 'assets/js/diagrams.js', [], WP_CARVE_VERSION, true);
            foreach ($diagramInits as $handle => $inits) {
                foreach ($inits as $init) {
                    wp_add_inline_script($handle, $init);
                }
            }
        }

        // KaTeX for math. carve-php emits \(…\)/\[…\] delimiters; KaTeX
        // auto-render handles them. Vendored locally (css + js + fonts) so the
        // font requests the stylesheet triggers stay on this host.
        if (str_contains($content, '$`')) {
            $base = rtrim((string)apply_filters('wp_carve_katex_base', WP_CARVE_URL . 'assets/js/vendor/katex'), '/');
            wp_enqueue_style('wp-carve-katex', esc_url_raw($base . '/katex.min.css'), [], WP_CARVE_VERSION);
            wp_enqueue_script('wp-carve-katex', esc_url_raw($base . '/katex.min.js'), [], WP_CARVE_VERSION, true);
            wp_enqueue_script('wp-carve-katex-auto', esc_url_raw($base . '/contrib/auto-render.min.js'), ['wp-carve-katex'], WP_CARVE_VERSION, true);
            wp_add_inline_script(
                'wp-carve-katex-auto',
                'document.addEventListener("DOMContentLoaded",function(){if(window.renderMathInElement){document.querySelectorAll(".wp-carve").forEach(function(e){renderMathInElement(e,{throwOnError:false});});}});',
            );
        }
    }
}
