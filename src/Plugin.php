<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

use WP_CLI;
use WP_Post;
use WP_Query;
use WpCarve\Admin\BulkMigrate;
use WpCarve\Admin\ImportExport;
use WpCarve\Admin\PostEditor;
use WpCarve\Admin\PostMode;
use WpCarve\Admin\SettingsPage;
use WpCarve\Blocks\CarveBlock;
use WpCarve\Blocks\SlidesBlock;
use WpCarve\CLI\LintCommand;
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
        // Translations are loaded automatically: WordPress.org serves them for
        // the plugin slug, and core's just-in-time loader handles the rest.
        $this->converter = Converter::fromSettings();
        $settings = Settings::all();

        // --- Rendering surfaces ---
        if (!empty($settings['enable_shortcode'])) {
            add_shortcode('carve', [$this, 'renderShortcode']);
            add_filter('no_texturize_shortcodes', [$this, 'excludeShortcodeFromTexturize']);
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
            (new BulkMigrate())->register();
        }
        add_action('enqueue_block_editor_assets', [$this, 'enqueueEditorAssets']);
        add_action('enqueue_block_assets', [$this, 'enqueueEditorCanvasStyles']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontendAssets']);
        add_filter('script_loader_tag', [$this, 'deferFrontendScripts'], 10, 2);
        add_action('wp_head', [$this, 'autoOgImage'], 5);
        add_filter('wpcarve_rendered_html', [$this, 'oembedBridge'], 10, 3);

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('carve', new MigrateCommand());
            WP_CLI::add_command('carve lint', new LintCommand());
        }
    }

    public function renderShortcode(mixed $atts, ?string $content = null): string
    {
        if ($content === null) {
            return '';
        }
        $raw = self::restoreShortcodeSource($content);
        $safe = self::safeForAuthor((int)get_post_field('post_author', get_the_ID()));

        return $this->wrap($this->converter->toHtml($raw, 'post', null, $safe));
    }

    /**
     * wptexturize runs on the_content at priority 10, before shortcodes
     * execute at 11 - without this exclusion it curls the straight quotes of
     * a fence title (`::: tab "Overview"`), the line stops being a fence, and
     * the whole block degrades to a literal paragraph.
     *
     * @param array<string> $shortcodes
     *
     * @return array<string>
     */
    public function excludeShortcodeFromTexturize(array $shortcodes): array
    {
        $shortcodes[] = 'carve';

        return $shortcodes;
    }

    /**
     * Undo what the_content filters did to `[carve]` shortcode content before
     * the shortcode ran: wpautop's tags, entity encoding, and - on fence
     * opener lines only - typographic quotes. The no_texturize_shortcodes
     * exclusion prevents the curling on this site, but content can still
     * arrive pre-curled (widgets calling do_shortcode on texturized text, or
     * quotes pasted from a word processor); on a `:::` line a typographic
     * quote is never intended, so straightening is lossless there.
     */
    public static function restoreShortcodeSource(string $content): string
    {
        $raw = html_entity_decode(wp_strip_all_tags($content, false), ENT_QUOTES, 'UTF-8');

        // Straighten quotes on `:::` lines outside fenced code blocks only -
        // a code sample documenting a curly-quoted fence line must stay
        // verbatim.
        $lines = explode("\n", $raw);
        $codeFence = null;
        foreach ($lines as $i => $line) {
            if ($codeFence !== null) {
                if (
                    preg_match('/^[ \t]*(`{3,}|~{3,})[ \t]*$/', $line, $m) === 1
                    && $m[1][0] === $codeFence[0]
                    && strlen($m[1]) >= strlen($codeFence)
                ) {
                    $codeFence = null;
                }

                continue;
            }
            if (preg_match('/^[ \t]*(`{3,}|~{3,})/', $line, $m) === 1) {
                $codeFence = $m[1];

                continue;
            }
            if (preg_match('/^[ \t]*:{3,}/', $line) === 1) {
                $lines[$i] = str_replace(['“', '”', '„'], '"', $line);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Wrap rendered Carve HTML so the `.wpcarve` stylesheet (admonitions,
     * permalinks, code) applies on every surface. The rendered markup passes
     * through wp_kses here as well, so the value returned from every content
     * callback (the_content, [carve] shortcode, comments) is escaped at the
     * point of output, not only inside the converter.
     */
    private function wrap(string $html): string
    {
        return $html === '' ? '' : '<div class="wpcarve">' . Converter::sanitizeHtml($html) . '</div>';
    }

    public function renderComment(string $text): string
    {
        $comment = get_comment();
        if (!$comment) {
            return $text;
        }
        $raw = get_comment_meta((int)$comment->comment_ID, '_wpcarve_raw', true);
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
            $commentdata['comment_meta']['_wpcarve_raw'] = wp_unslash((string)$commentdata['comment_content']);
        }

        return $commentdata;
    }

    /**
     * Whether whole-post Carve rendering is enabled for this post's type. The
     * "Posts" / "Pages" toggles gate those types; custom types rely on the
     * per-post opt-in meta alone.
     */
    private function typeEnabled(WP_Post $post): bool
    {
        if ($post->post_type === 'post') {
            return (bool)Settings::get('enable_posts');
        }
        if ($post->post_type === 'page') {
            return (bool)Settings::get('enable_pages');
        }

        return true;
    }

    public function maybeRenderExcerpt(string $excerpt): string
    {
        $post = get_post();
        if (!$post || !$this->typeEnabled($post)) {
            return $excerpt;
        }

        $src = '';
        if (trim((string)$post->post_excerpt) !== '') {
            $src = (string)$post->post_excerpt;
        } elseif (get_post_meta($post->ID, '_wpcarve_enabled', true)) {
            $src = (string)$post->post_content;
        } elseif (has_block('carve/markup', $post)) {
            // Block posts previously fell through to core, which drops the
            // dynamic block entirely (empty excerpt) - or, when the comment
            // delimiters are malformed (an unescaped --> inside the attribute
            // JSON ends the comment early), leaks the raw serialized block
            // into the excerpt. Build the excerpt from the carve source.
            $src = self::carveFromBlocks((string)$post->post_content);
        }
        if (trim($src) === '') {
            return $excerpt;
        }
        $html = $this->converter->toHtml($src, 'post', null, self::safeForAuthor((int)$post->post_author));

        return wp_trim_words(wp_strip_all_tags($html), 55);
    }

    /**
     * Extract the carve source from carve/markup blocks. parse_blocks() covers
     * well-formed comments; a malformed one (raw `-->` inside the JSON) parses
     * as freeform, so the attribute JSON string - which itself stays valid -
     * is salvaged directly. Never returns serialized block markup.
     */
    public static function carveFromBlocks(string $content): string
    {
        $src = '';
        foreach (parse_blocks($content) as $block) {
            if (($block['blockName'] ?? '') === 'carve/markup') {
                $src .= (string)($block['attrs']['carve'] ?? '') . "\n\n";
            }
        }
        if (trim($src) === '' && preg_match_all('/"carve"\s*:\s*("(?:[^"\\\\]|\\\\.)*")/s', $content, $m)) {
            foreach ($m[1] as $json) {
                $decoded = json_decode($json);
                if (is_string($decoded)) {
                    $src .= $decoded . "\n\n";
                }
            }
        }

        return trim($src);
    }

    public function maybeRenderPost(string $content): string
    {
        $post = get_post();
        if (!$post || !get_post_meta($post->ID, '_wpcarve_enabled', true) || !$this->typeEnabled($post)) {
            return $content;
        }

        $safe = self::safeForAuthor((int)$post->post_author);
        $cached = RenderCache::read($post->ID, $safe);
        if ($cached !== null) {
            $rendered = $cached;
        } else {
            $rendered = $this->converter->toHtml($post->post_content, 'post', null, $safe);
        }

        // Carve already produced block HTML; keep wpautop away from it.
        remove_filter('the_content', 'wpautop');

        return $this->wrap($rendered);
    }

    /**
     * Styles that must exist INSIDE the editor-canvas iframe. Styles from
     * enqueue_block_editor_assets land in the parent admin document only;
     * without KaTeX's stylesheet in the iframe the MathML fallback renders
     * visibly next to the typeset math in the preview pane.
     */
    public function enqueueEditorCanvasStyles(): void
    {
        if (!is_admin()) {
            return;
        }
        $katexBase = rtrim((string)apply_filters('wpcarve_katex_base', WPCARVE_URL . 'assets/js/vendor/katex'), '/');
        wp_enqueue_style('wpcarve-katex', esc_url_raw($katexBase . '/katex.min.css'), [], WPCARVE_VERSION);
    }

    public function enqueueEditorAssets(): void
    {
        $asset = WPCARVE_DIR . 'assets/blocks/carve/index.asset.php';
        $deps = ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'];
        $ver = WPCARVE_VERSION;
        if (is_readable($asset)) {
            $data = require $asset;
            $deps = $data['dependencies'] ?? $deps;
            $ver = $data['version'] ?? $ver;
        }
        // Cache-bust on file changes: the hand-maintained asset version only
        // moves on releases, which strands editors on stale block JS during
        // development and after in-place updates.
        $ver .= '-' . $this->assetVersion('assets/blocks/carve/index.js');
        // The preview pane shows the full display render, so it needs the same
        // client renderers as the front end: KaTeX for math and the enabled
        // diagram libraries (Chart.js, Mermaid, ...). Content is dynamic in the
        // editor, so enabled types load unconditionally; the preview effect in
        // index.js re-runs the initializers after each render.
        $katexBase = rtrim((string)apply_filters('wpcarve_katex_base', WPCARVE_URL . 'assets/js/vendor/katex'), '/');
        wp_enqueue_script('wpcarve-katex', esc_url_raw($katexBase . '/katex.min.js'), [], WPCARVE_VERSION, true);
        wp_enqueue_script('wpcarve-katex-auto', esc_url_raw($katexBase . '/contrib/auto-render.min.js'), ['wpcarve-katex'], WPCARVE_VERSION, true);
        $needDiagrams = false;
        foreach (Diagrams::all() as $name => $diagram) {
            if (!Settings::get(Diagrams::settingKey($name))) {
                continue;
            }
            $needDiagrams = true;
            $i = 0;
            foreach ((array)($diagram['libs'] ?? []) as $lib) {
                $src = (string)apply_filters('wpcarve_diagram_src', WPCARVE_URL . 'assets/js/vendor/' . $lib, $name, $lib);
                wp_enqueue_script('wpcarve-diagram-' . $name . '-' . $i, esc_url_raw($src), [], WPCARVE_VERSION, true);
                $i++;
            }
        }
        if ($needDiagrams) {
            wp_enqueue_script('wpcarve-diagrams', WPCARVE_URL . 'assets/js/diagrams.js', [], $this->assetVersion('assets/js/diagrams.js'), true);
        }

        // Optional in-browser Carve engine (innovation A). Built by `npm run
        // build` into assets/js/vendor/carve.js (sets window.wpCarveEngine). When
        // absent, the editor falls back to the REST render endpoint.
        $engine = WPCARVE_DIR . 'assets/js/vendor/carve.js';
        if (Settings::get('live_preview') && is_readable($engine)) {
            wp_enqueue_script('wpcarve-engine', WPCARVE_URL . 'assets/js/vendor/carve.js', [], WPCARVE_VERSION, true);
            $deps[] = 'wpcarve-engine';
        }

        wp_enqueue_script(
            'wpcarve-editor',
            WPCARVE_URL . 'assets/blocks/carve/index.js',
            $deps,
            $ver,
            true,
        );
        wp_enqueue_style('wpcarve', WPCARVE_URL . 'assets/css/carve.css', [], $this->assetVersion('assets/css/carve.css'));
        wp_enqueue_script('wpcarve-slides', WPCARVE_URL . 'assets/js/slides.js', [], $this->assetVersion('assets/js/slides.js'), true);
        wp_localize_script('wpcarve-editor', 'wpCarve', [
            'restRender' => esc_url_raw(rest_url('carve/v1/render')),
            'restIngest' => esc_url_raw(rest_url('carve/v1/ingest')),
            'nonce' => wp_create_nonce('wp_rest'),
            'livePreview' => (bool)Settings::get('live_preview'),
            'pasteIngest' => (bool)Settings::get('paste_ingest'),
            // Tiptap visual editor: the locally-bundled ES module (esbuild;
            // no CDN). Empty string hides the Visual tab. Lazy-loaded via
            // dynamic import() from the block only when Visual mode is used.
            'visualEditor' => Settings::get('visual_editor_mode') !== 'disabled'
                ? esc_url_raw(WPCARVE_URL . 'assets/js/vendor/carve-editor.js') . '?ver=' . $this->assetVersion('assets/js/vendor/carve-editor.js')
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
        if (is_admin() || strncmp($handle, 'wpcarve', 8) !== 0) {
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
    public function oembedBridge(string $html, string $carve = '', string $context = 'post'): string
    {
        // Never fetch on behalf of untrusted commenters, and only when enabled.
        if ($context === 'comment' || !apply_filters('wpcarve_media_oembed', true) || !str_contains($html, 'ext-')) {
            return $html;
        }

        $out = preg_replace_callback(
            '#<p>\s*<span class="ext-(youtube|vimeo|media)">([^<]+)</span>\s*</p>#i',
            static function (array $m): string {
                $type = strtolower($m[1]);
                $arg = html_entity_decode(trim($m[2]), ENT_QUOTES, 'UTF-8');
                if ($type === 'media') {
                    // Arbitrary author-supplied URL: require a valid public http(s)
                    // URL. wp_http_validate_url rejects loopback/private/link-local
                    // hosts, closing the SSRF vector.
                    $url = wp_http_validate_url($arg);
                    if ($url === false) {
                        return $m[0];
                    }
                } else {
                    // Provider id only - restrict to a safe id charset.
                    if (!preg_match('/^[A-Za-z0-9_-]+$/', $arg)) {
                        return $m[0];
                    }
                    $url = $type === 'youtube'
                        ? 'https://www.youtube.com/watch?v=' . $arg
                        : 'https://vimeo.com/' . $arg;
                }
                $embed = wp_oembed_get($url);

                return $embed ? '<div class="wpcarve-oembed">' . $embed . '</div>' : $m[0];
            },
            $html,
        );

        return $out ?? $html;
    }

    /**
     * Fallback OG image for Carve posts without a featured image: the first
     * `![](url)` in the source. Skipped if a featured image exists or the
     * `wpcarve_auto_og_image` filter returns false.
     */
    public function autoOgImage(): void
    {
        if (!is_singular() || has_post_thumbnail()) {
            return;
        }
        if (!apply_filters('wpcarve_auto_og_image', true)) {
            return;
        }
        $post = get_post();
        if (!$post instanceof WP_Post) {
            return;
        }
        $isCarve = get_post_meta($post->ID, '_wpcarve_enabled', true)
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
     * Safe mode is unconditional for every author on every surface: rendered
     * Carve always passes through wp_kses, so raw HTML/script/style can never be
     * emitted. Retained (always returning true) so existing call sites stay
     * explicit about requesting a sanitized render.
     */
    public static function safeForAuthor(?int $authorId): bool
    {
        return true;
    }

    private function assetVersion(string $relPath): string
    {
        $mtime = @filemtime(WPCARVE_DIR . $relPath);

        return $mtime ? (string)$mtime : WPCARVE_VERSION;
    }

    /**
     * Whether the current front-end view renders any Carve, so the stylesheet
     * is worth loading. Filterable via `wpcarve_enqueue_styles` for surfaces the
     * detection cannot see (e.g. a `[carve]` shortcode injected by a widget or
     * page builder outside the queried post content).
     */
    private function shouldEnqueueStyles(): bool
    {
        return (bool)apply_filters('wpcarve_enqueue_styles', $this->pageUsesCarve());
    }

    private function pageUsesCarve(): bool
    {
        // Comment Carve needs the stylesheet on any singular page that either
        // shows the toolbar (comments open, its preview renders into `.wpcarve`)
        // or already displays stored Carve comments (rendered via comment_text
        // even after the thread is closed) - independent of whether the post
        // itself is Carve.
        if (is_singular() && Settings::get('enable_comments') && (comments_open() || (int)get_comments_number() > 0)) {
            return true;
        }

        $shortcode = (bool)Settings::get('enable_shortcode');
        foreach ($this->queriedPosts() as $post) {
            if (
                get_post_meta($post->ID, '_wpcarve_enabled', true)
                || has_block('carve/markup', $post)
                || has_block('carve/slides', $post)
            ) {
                return true;
            }
            if ($shortcode && has_shortcode((string)$post->post_content, 'carve')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The posts the current request will display: the single queried post on a
     * singular view, or the main query's loop on an archive/home listing (so a
     * `[carve]` shortcode inside any listed post is detected).
     *
     * @return array<int, \WP_Post>
     */
    private function queriedPosts(): array
    {
        if (is_singular()) {
            $post = get_post();

            return $post instanceof WP_Post ? [$post] : [];
        }

        $query = $GLOBALS['wp_query'] ?? null;
        $posts = $query instanceof WP_Query ? $query->posts : [];

        return array_values(array_filter($posts, static fn ($post): bool => $post instanceof WP_Post));
    }

    public function enqueueFrontendAssets(): void
    {
        // The stylesheet used to load on every front-end view. Only enqueue it
        // where Carve is actually rendered (a Carve post/block, a `[carve]`
        // shortcode in the queried content, or a comment preview) so non-Carve
        // pages ship no extra CSS.
        if ($this->shouldEnqueueStyles()) {
            wp_enqueue_style('wpcarve', WPCARVE_URL . 'assets/css/carve.css', [], $this->assetVersion('assets/css/carve.css'));
        }

        if (!is_singular()) {
            return;
        }

        // Comment toolbar (independent of whether the post itself is Carve).
        if (Settings::get('enable_comments') && comments_open()) {
            wp_enqueue_script('wpcarve-comment-toolbar', WPCARVE_URL . 'assets/js/comment-toolbar.js', [], $this->assetVersion('assets/js/comment-toolbar.js'), true);
            wp_localize_script('wpcarve-comment-toolbar', 'wpCarveComment', [
                'previewUrl' => rest_url('carve/v1/preview-comment'),
                'previewLabel' => __('Preview', 'carve-markup'),
                'writeLabel' => __('Write', 'carve-markup'),
                'emptyText' => __('Nothing to preview yet.', 'carve-markup'),
                'loadingText' => __('Rendering preview…', 'carve-markup'),
                'errorText' => __('Preview failed - please try again.', 'carve-markup'),
            ]);
        }

        $post = get_post();
        // Carve is present either as whole-post mode (meta) or a Carve block;
        // all such surfaces need the shared enhancements.
        $hasCarveBlock = $post && (has_block('carve/markup', $post) || has_block('carve/slides', $post));
        if (!$post || (!get_post_meta($post->ID, '_wpcarve_enabled', true) && !$hasCarveBlock)) {
            return;
        }
        $content = (string)$post->post_content;

        // Code-block enhancements (copy button, optional line numbers) - only
        // when the content actually has a fenced block.
        if (str_contains($content, '```') || str_contains($content, '~~~')) {
            wp_enqueue_script('wpcarve-code', WPCARVE_URL . 'assets/js/code-blocks.js', [], $this->assetVersion('assets/js/code-blocks.js'), true);
        }

        // Heading permalink click-to-copy.
        if (Settings::get('permalinks_enabled')) {
            wp_enqueue_script('wpcarve-permalink', WPCARVE_URL . 'assets/js/permalink.js', [], $this->assetVersion('assets/js/permalink.js'), true);
        }

        if (has_block('carve/slides', $post)) {
            wp_enqueue_script('wpcarve-slides', WPCARVE_URL . 'assets/js/slides.js', [], $this->assetVersion('assets/js/slides.js'), true);
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
                $handle = 'wpcarve-diagram-' . $name . '-' . $i;
                $src = (string)apply_filters(
                    'wpcarve_diagram_src',
                    WPCARVE_URL . 'assets/js/vendor/' . $lib,
                    $name,
                    $lib,
                );
                wp_enqueue_script($handle, esc_url_raw($src), [], WPCARVE_VERSION, true);
                $lastHandle = $handle;
                $i++;
            }
            foreach ((array)($diagram['src'] ?? []) as $url) {
                $handle = 'wpcarve-diagram-' . $name . '-ext-' . $i;
                wp_enqueue_script($handle, esc_url_raw((string)$url), [], WPCARVE_VERSION, true);
                $lastHandle = $handle;
                $i++;
            }
            // A custom renderer's init runs after its own libraries (attached to
            // the last one); with no libraries it rides the shared diagrams.js.
            if (!empty($diagram['init']) && is_string($diagram['init'])) {
                $diagramInits[$lastHandle ?? 'wpcarve-diagrams'][] = $diagram['init'];
            }
        }
        if ($needDiagrams) {
            wp_enqueue_script('wpcarve-diagrams', WPCARVE_URL . 'assets/js/diagrams.js', [], WPCARVE_VERSION, true);
            // Config + labels for the hover copy/download controls on rendered
            // diagrams. `export` gates the controls (off by default).
            wp_localize_script('wpcarve-diagrams', 'wpCarveDiagramsL10n', [
                'export' => (bool)Settings::get('diagram_export'),
                'download' => __('Download', 'carve-markup'),
                'copy' => __('Copy SVG', 'carve-markup'),
                'copied' => __('Copied', 'carve-markup'),
            ]);
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
            $base = rtrim((string)apply_filters('wpcarve_katex_base', WPCARVE_URL . 'assets/js/vendor/katex'), '/');
            wp_enqueue_style('wpcarve-katex', esc_url_raw($base . '/katex.min.css'), [], WPCARVE_VERSION);
            wp_enqueue_script('wpcarve-katex', esc_url_raw($base . '/katex.min.js'), [], WPCARVE_VERSION, true);
            wp_enqueue_script('wpcarve-katex-auto', esc_url_raw($base . '/contrib/auto-render.min.js'), ['wpcarve-katex'], WPCARVE_VERSION, true);
            wp_add_inline_script(
                'wpcarve-katex-auto',
                'document.addEventListener("DOMContentLoaded",function(){if(window.renderMathInElement){document.querySelectorAll(".wpcarve").forEach(function(e){renderMathInElement(e,{throwOnError:false});});}});',
            );
        }
    }
}
