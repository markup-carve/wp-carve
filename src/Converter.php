<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

use ArrayObject;
use Closure;
use LengthException;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Extension\Citations;
use MarkupCarve\Carve\Extension\DocumentTransform;
use MarkupCarve\Carve\Extension\Navigation;
use MarkupCarve\Carve\Extension\SmartQuotes;
use MarkupCarve\Carve\Extension\Standard;
use MarkupCarve\Carve\Extension\Transforms;
use MarkupCarve\Carve\Include\FileResolver;
use MarkupCarve\Carve\InputError;
use MarkupCarve\Carve\Node;
use Throwable;
use WpCarve\Extension\DiagramFences;
use WpCarve\Extension\TorchlightExtension;
use WpCarve\Includes\IncludeReport;
use WpCarve\Includes\RecordingResolver;

/**
 * WordPress-facing wrapper around the carve-php 0.2 engine (`Carve::render()`).
 *
 * Builds the option array per context (post vs comment) from the configured
 * content profile and features, and renders Carve to HTML or plain text.
 */
class Converter
{
    /**
     * @var int
     */
    private const INCLUDE_BYTE_BUDGET = 4194304;

    /**
     * Block containers counted against the untrusted nesting budget.
     *
     * @var array<string, true>
     */
    private const NESTING_TYPES = [
        'block_quote' => true,
        'list' => true,
        'div' => true,
        'admonition' => true,
        'definition_list' => true,
        'block_extension' => true,
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    /**
     * End offsets of captioned `{.diff}` code blocks in the render in progress.
     * The engine hands `codeBlockRenderer` a captioned block without its
     * attributes, which stay on the figure.
     *
     * @var \ArrayObject<string, true>
     */
    private ArrayObject $diffBlocks;

    /**
     * @param array<string, mixed> $settings Resolved plugin settings.
     */
    public function __construct(private array $settings)
    {
        $this->diffBlocks = new ArrayObject();
    }

    public static function fromSettings(): self
    {
        return new self(Settings::all());
    }

    /**
     * Render Carve source to HTML for a given context ('post', 'comment',
     * 'feed', or 'editor' - the visual-editor seed, which omits
     * non-round-trippable markup).
     *
     * $profileOverride forces a specific content profile (full / article /
     * comment / minimal / none) regardless of the context default - used by the
     * Carve block's per-block profile attribute.
     *
     * @param string $carve
     * @param string $context
     * @param string|null $profileOverride
     * @param bool|null $safe Kept for callers; 0.2 sanitization does not depend on it.
     * @param array<int, mixed>|null $bibliography External CSL-JSON entries for citations.
     * @param string $citationMode
     * @param \WpCarve\Includes\IncludeReport|null $includes Expands include directives
     *   against the configured root and records the outcome. Pass one only once
     *   IncludePolicy has allowed it.
     * @param int|null $maxNesting Maximum block container nesting for this render.
     *
     * @throws \LengthException When the nesting or length budget is exceeded.
     */
    public function toHtml(
        string $carve,
        string $context = 'post',
        ?string $profileOverride = null,
        ?bool $safe = null,
        ?array $bibliography = null,
        string $citationMode = 'numbered',
        ?IncludeReport $includes = null,
        ?int $maxNesting = null,
    ): string {
        if (trim($carve) === '') {
            return '';
        }

        /**
         * Filter the raw Carve source before it is converted.
         *
         * @param string $carve The Carve source.
         * @param string $context 'post', 'comment', 'editor', or 'feed'.
         */
        $carve = (string)apply_filters('wpcarve_source', $carve, $context);

        // Site-wide abbreviation defs are prepended for rendering, but the visual
        // editor seed must not carry them: they render <abbr> spans that serialize
        // back into per-post source (freezing a global setting into the post). So
        // the editor context renders the source alone.
        $abbrevDefs = $context === 'editor' ? '' : $this->abbreviationDefs();
        $options = $this->optionsFor($context, $profileOverride);
        $citationTransforms = [];
        if ($bibliography !== null && $context !== 'editor' && $context !== 'comment') {
            $citations = Citations::bundle($citationMode === '' ? 'numbered' : $citationMode, $bibliography);
            $options['extensions'][] = $citations;
            $citationTransforms = $citations->transforms();
        }
        $source = $abbrevDefs . $carve;

        try {
            $html = $includes !== null && $context !== 'editor' && $context !== 'comment'
                ? $this->renderWithIncludes($source, $options, $includes, $context, $maxNesting, $citationTransforms)
                : $this->renderSource($source, $options, $context, $maxNesting, $citationTransforms);
        } catch (InputError) {
            throw new LengthException('Input length exceeds the profile limit');
        }

        // JSON diagram configs (chart, vega-lite) that an add-on still emits in a
        // script tag are moved into a data attribute: wp_kses strips every script
        // tag, and wptexturize then curls the quotes of the leftover text. The
        // built-in diagram fences already write the attribute (DiagramFences).
        $html = (string)preg_replace_callback(
            '/<div class="([^"]*)"([^>]*)>\s*<script type="application\/json">(.*?)<\/script>\s*<\/div>/s',
            static function (array $m): string {
                $out = '<div class="' . $m[1] . '"' . $m[2] . ' data-carve-json="' . esc_attr($m[3]) . '"></div>';
                if (preg_match('/(^| )chart( |$)/', $m[1]) === 1) {
                    $out .= self::chartDataTable($m[3]);
                }

                return $out;
            },
            $html,
        );

        // A {.diff} language fence keeps its language while its leading +/-/space
        // become diff markers. The engine leaves them as text; present them here,
        // before sanitizing (the added spans are allowlisted).
        $html = self::presentLanguageDiff($html);
        $html = $this->presentHostFeatures($html, $context);

        // Rendering is always sanitized: the engine escapes raw HTML unless the
        // profile and `rawHtml` both allow it, and the generated markup
        // additionally passes through wp_kses so only allowlisted
        // tags/attributes ever reach output.
        $html = self::sanitizeHtml($html);

        /**
         * Filter the rendered HTML before it is returned to WordPress.
         *
         * @param string $html The rendered HTML.
         * @param string $carve The original Carve source.
         * @param string $context 'post', 'comment', or 'editor'.
         */
        return (string)apply_filters('wpcarve_rendered_html', $html, $carve, $context);
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     * @param string $context
     * @param int|null $maxNesting
     * @param array<\MarkupCarve\Carve\Extension\DocumentTransform> $extraTransforms
     *
     * @throws \LengthException
     */
    private function renderSource(string $source, array $options, string $context, ?int $maxNesting, array $extraTransforms = []): string
    {
        $transforms = [...$this->documentTransforms($context), ...$extraTransforms];
        if ($maxNesting === null && $transforms === []) {
            return Carve::render($source, $options);
        }

        $parseOptions = array_intersect_key($options, ['extensions' => true, 'includes' => true, 'headingIds' => true]);
        $tree = Carve::parse($source, $parseOptions);
        if ($maxNesting !== null && self::nestingDepth($tree) > $maxNesting) {
            throw new LengthException('Nesting budget exceeded');
        }
        if ($transforms === []) {
            return Carve::render($tree, $options);
        }

        return Carve::renderWithDocumentTransforms($tree, $transforms, $options)->html;
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     * @param \WpCarve\Includes\IncludeReport $report
     * @param string $context
     * @param int|null $maxNesting
     * @param array<\MarkupCarve\Carve\Extension\DocumentTransform> $extraTransforms
     */
    private function renderWithIncludes(
        string $source,
        array $options,
        IncludeReport $report,
        string $context,
        ?int $maxNesting,
        array $extraTransforms = [],
    ): string {
        if (!str_contains($source, '{{')) {
            return $this->renderSource($source, $options, $context, $maxNesting, $extraTransforms);
        }

        try {
            $resolver = new RecordingResolver(FileResolver::forRoot($report->root()));
        } catch (Throwable) {
            $report->refuseRoot();

            return $this->renderSource($source, $options, $context, $maxNesting, $extraTransforms);
        }

        $includes = ['resolver' => $resolver, 'bytes' => self::INCLUDE_BYTE_BUDGET];
        $parseOptions = array_intersect_key($options, ['extensions' => true, 'headingIds' => true]) + ['includes' => $includes];
        $tree = Carve::parse($source, $parseOptions);
        if ($maxNesting !== null && self::nestingDepth($tree) > $maxNesting) {
            throw new LengthException('Nesting budget exceeded');
        }
        $transforms = [...$this->documentTransforms($context), ...$extraTransforms];
        $result = $transforms === []
            ? Carve::renderWithDiagnostics($tree, $options)
            : Carve::renderWithDocumentTransforms($tree, $transforms, $options);
        // The render-loss entries carry no resolver detail (no reason a path
        // was refused), unlike the parse entries, so only they are recorded.
        $report->record(
            Carve::includeDependencies($tree),
            $resolver->lookups(),
            array_values(array_filter(
                $result->diagnostics,
                static fn (Diagnostic $d): bool => $d->channel === 'render-loss' && str_starts_with($d->code, 'include-'),
            )),
        );

        return $result->html;
    }

    /**
     * Deepest nesting of block containers in a tree.
     */
    private static function nestingDepth(Node $node): int
    {
        $max = 0;
        foreach ($node->children ?? [] as $child) {
            if ($child instanceof Node) {
                $max = max($max, self::nestingDepth($child));
            }
        }

        return $max + (isset(self::NESTING_TYPES[$node->type]) ? 1 : 0);
    }

    /**
     * Host document transforms for a context: the heading level shift and
     * wiki link resolution, which 0.1 shipped as extensions.
     *
     * @return array<\MarkupCarve\Carve\Extension\DocumentTransform>
     */
    private function documentTransforms(string $context): array
    {
        $forText = str_starts_with($context, 'text:');
        $base = $forText ? substr($context, 5) : $context;
        $transforms = [];
        $shift = (int)($this->settings['heading_shift'] ?? 0);
        if ($shift > 0 && !$forText && $base !== 'editor' && $base !== 'comment') {
            $transforms[] = new DocumentTransform('wpcarve.headingShift', static function (Node $doc) use ($shift): Node {
                self::walk($doc, static function (Node $node) use ($shift): void {
                    if ($node->type === 'heading') {
                        $node->f['level'] = min(6, (int)($node->f['level'] ?? 1) + $shift);
                    }
                });

                return $doc;
            });
        }
        if (!empty($this->settings['torchlight_enabled']) && !$forText && $base !== 'editor') {
            $diffBlocks = $this->diffBlocks;
            $transforms[] = new DocumentTransform('wpcarve.diffFigures', static function (Node $doc) use ($diffBlocks): Node {
                $diffBlocks->exchangeArray([]);
                self::walk($doc, static function (Node $node) use ($diffBlocks): void {
                    $attrs = $node->f['attrs'] ?? null;
                    if ($node->type === 'code_block' && isset($node->f['caption']) && $attrs instanceof Attrs && in_array('diff', $attrs->classes, true)) {
                        $diffBlocks[$node->file . ':' . $node->end] = true;
                    }
                });

                return $doc;
            });
        }
        // The editor seed keeps the author's tabs so the source round-trips.
        if (!empty($this->settings['normalize_tabs']) && $base !== 'editor') {
            array_push($transforms, ...Transforms::tabNormalize(max(0, min(16, (int)($this->settings['tab_width'] ?? 2))))->transforms());
        }
        if ($base !== 'comment' && !empty($this->settings['wikilinks_enabled'])) {
            // Plain text discards the href, so the text path never looks a page up.
            $transforms[] = new DocumentTransform('wpcarve.wikilinks', static function (Node $doc) use ($forText): Node {
                self::walk($doc, static function (Node $node) use ($forText): void {
                    foreach ($node->children ?? [] as $i => $child) {
                        if (!$child instanceof Node || $child->type !== 'inline_extension' || ($child->f['name'] ?? '') !== 'carve.wiki') {
                            continue;
                        }
                        $label = array_values(array_filter($child->f['fallback'] ?? [], static fn ($c): bool => $c instanceof Node));
                        $page = trim(implode('', array_map(static fn (Node $c): string => (string)($c->f['value'] ?? ''), $label)));
                        // Built through the public parser so the attributes take the
                        // engine's own shape.
                        $link = Carve::parse('[x](#){.wikilink data-wikilink="p"}')->children[0]->children[0] ?? null;
                        $attrs = $link instanceof Node ? ($link->f['attrs'] ?? null) : null;
                        if (!$link instanceof Node || !is_object($attrs) || !isset($attrs->pairs[0][1])) {
                            continue;
                        }
                        $attrs->pairs[0][1] = $page;
                        $link->f['href'] = $forText ? '#' : self::wikilinkUrl($page);
                        $link->children = $label;
                        $node->children[$i] = $link;
                    }
                });

                return $doc;
            });
        }

        return $transforms;
    }

    /**
     * Visit a node and every descendant, parents first.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \Closure(\MarkupCarve\Carve\Node): void $visit
     */
    private static function walk(Node $node, Closure $visit): void
    {
        $visit($node);
        foreach ($node->children ?? [] as $child) {
            if ($child instanceof Node) {
                self::walk($child, $visit);
            }
        }
    }

    /**
     * Resolve `:wiki[Page Title]` against real content.
     *
     * An unresolved link becomes `#` rather than disappearing: the anchor keeps
     * its `wikilink` class and `data-wikilink` title, so a theme can style
     * `a.wikilink[href="#"]` as a broken link the way a wiki does.
     */
    private static function wikilinkUrl(string $page): string
    {
        /** @var string|null $resolved */
        $resolved = apply_filters('wpcarve_wikilink_url', null, $page);
        if (is_string($resolved) && $resolved !== '') {
            return $resolved;
        }

        if (!function_exists('get_page_by_path') || !function_exists('sanitize_title')) {
            return '#';
        }

        $post = get_page_by_path(sanitize_title($page), OBJECT, ['post', 'page']);
        if ($post === null || !function_exists('get_permalink')) {
            return '#';
        }

        return (string)get_permalink($post);
    }

    /**
     * Present a `{.diff}` language fence: `<pre class="diff"><code class="language-x">`
     * with the leading `+`/`-`/space left as text. Each line is wrapped as
     * `<span class="line diff add|remove">` with a `<span class="diff-marker">`,
     * matching the carve-grammars class contract. A captioned fence carries the
     * class on its `<figure>` instead (`<figure class="diff"><pre><code ...>`).
     * A block whose code was already highlighted into spans (Torchlight) is
     * left untouched.
     */
    private static function presentLanguageDiff(string $html): string
    {
        return (string)preg_replace_callback(
            '/(<figure\b[^>]*>\s*)?<pre(?: class="([^"]*)")?><code class="(language-[^"]*)">(.*?)<\/code><\/pre>/s',
            static function (array $m): string {
                $figure = $m[1];
                $preClasses = $m[2];
                $figureClasses = preg_match('/\sclass="([^"]*)"/', $figure, $fm) === 1 ? $fm[1] : '';
                // Present only a fence carrying the exact `diff` class token (not
                // `diff-example` etc.) whose code is not already highlighted into
                // spans (Torchlight, which is left as-is - see the method docblock).
                $isDiff = in_array('diff', explode(' ', $preClasses), true)
                    || in_array('diff', explode(' ', $figureClasses), true);
                if (!$isDiff || str_contains($m[4], '<span')) {
                    return $m[0];
                }
                // Drop only the renderer's single structural terminal newline, so
                // intentional trailing blank lines survive like an ordinary fence.
                $code = substr($m[4], -1) === "\n" ? substr($m[4], 0, -1) : $m[4];
                $out = '';
                foreach (explode("\n", $code) as $line) {
                    $marker = $line !== '' && in_array($line[0], ['+', '-', ' '], true) ? $line[0] : '';
                    $body = $marker !== '' ? substr($line, 1) : $line;
                    $lineClass = $marker === '+' ? 'line diff add' : ($marker === '-' ? 'line diff remove' : 'line');
                    $markerSpan = $marker !== '' ? '<span class="diff-marker">' . esc_html($marker) . '</span>' : '';
                    $out .= '<span class="' . $lineClass . '">' . $markerSpan . $body . '</span>' . "\n";
                }

                return $figure . '<pre class="' . trim($preClasses . ' has-diff') . '"><code class="' . $m[3] . '">' . rtrim($out, "\n") . '</code></pre>';
            },
            $html,
        ) ?? $html;
    }

    /**
     * Run the carve-php 0.2 linter over the authored source, with the
     * extensions this context renders with so their references resolve.
     *
     * @return array<int, \WpCarve\LintFinding>
     */
    public function lint(string $carve, string $context = 'post'): array
    {
        if (trim($carve) === '') {
            return [];
        }

        $options = $this->optionsFor($context);
        $findings = array_map(
            static fn (Diagnostic $d): LintFinding => LintFinding::fromDiagnostic($d, $carve),
            Carve::lint($carve, ['extensions' => $options['extensions'] ?? []]),
        );
        usort($findings, static fn (LintFinding $a, LintFinding $b): int => [$a->line, $a->column] <=> [$b->line, $b->column]);

        return $findings;
    }

    /**
     * Render Carve source as plain text for a given context.
     *
     * Goes through the engine's `plain` writer; the HTML-derived text below is
     * the fallback for an engine without it.
     */
    public function toText(string $carve, string $context = 'post', ?string $profileOverride = null): string
    {
        if (trim($carve) === '') {
            return '';
        }

        /**
         * Filter the raw Carve source before it is converted.
         *
         * @param string $carve The Carve source.
         * @param string $context 'post', 'comment', or 'editor'.
         */
        $carve = (string)apply_filters('wpcarve_source', $carve, $context);

        $abbrevDefs = $context === 'editor' ? '' : $this->abbreviationDefs();
        $source = $abbrevDefs . $carve;
        $options = $this->optionsFor($context === 'editor' ? 'editor' : 'text:' . $context, $profileOverride);
        if (self::hasWriter('plain')) {
            return trim(Carve::writeTo('plain', $this->textTree($source, $options, $context), ['smartTypography' => $options['smartTypography']]));
        }

        $html = $this->renderSource($source, $options, $context === 'editor' ? 'editor' : 'text:' . $context, null);
        $html = (string)preg_replace('/<section role="doc-endnotes".*?<\/section>/s', '', $html);
        $html = (string)preg_replace('/<a [^>]*role="doc-noteref"[^>]*>.*?<\/a>/s', '', $html);
        $html = (string)preg_replace('/<\/(p|h[1-6]|li|blockquote|pre|tr|dt|dd|div|aside|section|figure)>/', "$0\n", $html);
        $html = (string)preg_replace('/<\/t[dh]>/', '$0 ', $html);
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string)preg_replace("/\n{2,}/", "\n", $text));
    }

    /**
     * The parsed tree of the plain-text path with the context's document transforms applied.
     *
     * @param string $source
     * @param array<string, mixed> $options
     * @param string $context
     */
    private function textTree(string $source, array $options, string $context): Node
    {
        $parseOptions = array_intersect_key($options, ['extensions' => true, 'headingIds' => true]);
        $tree = Carve::parse($source, $parseOptions);
        foreach ($this->documentTransforms($context === 'editor' ? 'editor' : 'text:' . $context) as $transform) {
            $tree = ($transform->run)($tree);
        }
        // Notes stay out of excerpts and feeds, as the 0.1 text target did.
        self::walk($tree, static function (Node $node): void {
            if ($node->children !== null) {
                $node->children = array_values(array_filter(
                    $node->children,
                    static fn (Node $child): bool => !in_array($child->type, ['footnote', 'footnote_ref', 'inline_footnote'], true),
                ));
            }
        });

        return $tree;
    }

    /**
     * Whether the engine's writer registry has a target yet.
     */
    public static function hasWriter(string $target): bool
    {
        try {
            return Carve::writers()->has($target);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A collapsed data table for a Chart.js config: rows per label, one column
     * per dataset. The chart itself needs JS; this fallback is always in the
     * DOM, so the numbers stay accessible, indexable and copyable. Returns ''
     * for configs without a labels/datasets shape (Vega specs etc.).
     */
    public static function chartDataTable(string $json): string
    {
        $config = json_decode($json, true);
        if (!is_array($config)) {
            return '';
        }
        $labels = $config['data']['labels'] ?? null;
        $datasets = $config['data']['datasets'] ?? null;
        if (!is_array($labels) || $labels === [] || !is_array($datasets) || $datasets === []) {
            return '';
        }

        $head = '<th scope="col"></th>';
        foreach ($datasets as $i => $set) {
            $name = is_array($set) && isset($set['label']) && is_scalar($set['label'])
                ? (string)$set['label']
                /* translators: %d: 1-based index of an unnamed chart data series. */
                : sprintf(__('Series %d', 'carve-markup'), $i + 1);
            $head .= '<th scope="col">' . esc_html($name) . '</th>';
        }

        $rows = '';
        foreach (array_values($labels) as $r => $label) {
            $rows .= '<tr><th scope="row">' . esc_html(is_scalar($label) ? (string)$label : '') . '</th>';
            foreach ($datasets as $set) {
                $value = is_array($set) && isset($set['data'][$r]) && is_scalar($set['data'][$r])
                    ? (string)$set['data'][$r]
                    : '';
                $rows .= '<td>' . esc_html($value) . '</td>';
            }
            $rows .= '</tr>';
        }

        return '<details class="wpcarve-chart-data"><summary>' . esc_html__('Chart data', 'carve-markup') . '</summary>'
            . '<table><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></details>';
    }

    /**
     * Site-wide abbreviation definitions, from a `KEY: expansion` per line
     * setting, emitted as Carve `*[KEY]: expansion` definition lines that render
     * nothing themselves but turn matching words into <abbr> across all content.
     */
    private function abbreviationDefs(): string
    {
        $raw = (string)($this->settings['abbreviations'] ?? '');
        if (trim($raw) === '') {
            return '';
        }
        $defs = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            if ($key === '' || $value === '') {
                continue;
            }
            $defs[] = '*[' . $key . ']: ' . $value;
        }

        return $defs !== [] ? implode("\n", $defs) . "\n\n" : '';
    }

    /**
     * Run rendered HTML through wp_kses with the Carve allowlist. This is the
     * authoritative sanitization gate: it strips <script>/<style>, drops event
     * handlers, and sanitizes inline styles and URL schemes. No-op only outside
     * WordPress (the unit suite has no wp_kses); the WP-integration checks
     * exercise the real filter.
     */
    public static function sanitizeHtml(string $html): string
    {
        if ($html === '' || !function_exists('wp_kses')) {
            return $html;
        }

        [$html, $svgUris] = self::maskImgFenceSvgUris($html);
        $html = wp_kses($html, self::allowedHtml());

        return $svgUris === [] ? $html : strtr($html, $svgUris);
    }

    /**
     * The sanitized `data:image/svg+xml` URI an `img` fence emits, as the engine
     * spells it: `rawurlencode` output with `!*'()` restored, so the value can
     * only hold unreserved characters and percent escapes.
     *
     * @var string
     */
    private const SVG_DATA_URI_PATTERN = '/src="(data:image\/svg\+xml,[A-Za-z0-9%\-_.~!*\'()]*)"/';

    /**
     * An `img` tag, bounded by the fact that the engine escapes `<` and `>`
     * inside every attribute value it writes.
     *
     * @var string
     */
    private const IMG_TAG_PATTERN = '/<img\s[^<>]*>/i';

    /**
     * Hide the `img` fence's data URI from wp_kses behind an opaque token, so it
     * survives sanitization, then restore it afterwards.
     *
     * `data:` is not in wp_allowed_protocols(), so kses rewrites the URI to the
     * relative `image/svg+xml,%3Csvg...` and every img fence renders a 404. The
     * alternative - adding `data:` to the protocol allowlist - would admit every
     * data URI on every surface, comments included, which is a far larger change
     * than the defect.
     *
     * What makes the restored URI safe is the `<img>` boundary, not the token: a
     * browser renders an SVG referenced by `<img src>` in a restricted mode with
     * no script execution, no external fetches and no access to the embedding
     * document, whatever the SVG contains. The engine's SvgSanitizer is a second,
     * independent layer on top (see the class note in carve-php: it is a
     * tokenizer with a presentational allowlist, and it is explicitly NOT
     * browser-grade, which is why the engine's inline-SVG mode stays off here).
     *
     * So the match is anchored to `<img>` tags and to the engine's own URI
     * spelling. An `<iframe src="data:image/svg+xml,...">` - which WOULD run
     * script, and which author raw HTML could spell on a profile that allows raw
     * nodes - is deliberately not matched and keeps being stripped.
     *
     * @param string $html
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function maskImgFenceSvgUris(string $html): array
    {
        if (!str_contains($html, 'data:image/svg+xml,')) {
            return [$html, []];
        }

        $uris = [];
        $masked = preg_replace_callback(
            self::IMG_TAG_PATTERN,
            static function (array $tag) use (&$uris): string {
                return (string)preg_replace_callback(
                    self::SVG_DATA_URI_PATTERN,
                    static function (array $match) use (&$uris): string {
                        // Minted per call from 128 random bits, so nothing in the
                        // author's own text can spell a token and have a URI
                        // substituted into it.
                        $token = 'wpcarve-svg-' . bin2hex(random_bytes(16));
                        $uris[$token] = $match[1];

                        return 'src="' . $token . '"';
                    },
                    $tag[0],
                );
            },
            $html,
        );

        return $masked === null ? [$html, []] : [$masked, $uris];
    }

    /**
     * Allowed tags/attributes for sanitizing rendered Carve output: the core
     * `post` allowlist (which already permits class, id, role, and any aria- or
     * data- attribute globally) plus the elements Carve generates beyond it - task-list
     * checkboxes and media-embed iframes. URL attributes stay restricted to
     * core's allowed protocols, so javascript: URIs never survive.
     *
     * @return array<string, array<string, bool>>
     */
    public static function allowedHtml(): array
    {
        $allowed = function_exists('wp_kses_allowed_html')
            ? wp_kses_allowed_html('post')
            : [];

        $allowed['input'] = [
            'type' => true,
            'checked' => true,
            'disabled' => true,
            'aria-label' => true,
            'class' => true,
            'id' => true,
            // Radio groups for the CSS-only tabs / code-group widgets share a
            // `name` so exactly one panel shows at a time. Core's `post`
            // allowlist has no global `name`, so without this wp_kses strips it,
            // ungrouping the radios and breaking panel switching.
            'name' => true,
        ];
        $allowed['label'] = [
            'for' => true,
            'class' => true,
        ];
        $allowed['iframe'] = [
            'src' => true,
            'width' => true,
            'height' => true,
            'title' => true,
            'class' => true,
            'allow' => true,
            'allowfullscreen' => true,
            'frameborder' => true,
            'loading' => true,
            'referrerpolicy' => true,
        ];

        /**
         * Filter the allowed HTML tags/attributes for sanitizing rendered Carve.
         *
         * @param array<string, array<string, bool>> $allowed wp_kses-style allowlist.
         */
        if (function_exists('apply_filters')) {
            /** @var array<string, array<string, bool>> */
            return apply_filters('wpcarve_allowed_html', $allowed);
        }

        return $allowed;
    }

    /**
     * The engine options for a context. `text:<context>` builds the options of
     * the plain-text derivation: no render-only widgets.
     *
     * @return array<string, mixed>
     */
    private function optionsFor(string $context, ?string $profileOverride = null): array
    {
        $forText = str_starts_with($context, 'text:');
        $base = $forText ? substr($context, 5) : $context;
        $isComment = $base === 'comment';
        // The visual editor seeds itself from rendered HTML and serializes it
        // back to Carve source, so generated, non-round-trippable markup stays
        // out of the 'editor' context (and the abbreviation defs, see toHtml()).
        $isEditor = $base === 'editor';
        // A feed renders like a post and then degrades: nothing in a feed reader
        // runs the plugin's JavaScript, so static mode writes the extension
        // fallbacks (a diagram becomes its readable source block).
        $isFeed = $base === 'feed';
        $cacheKey = $context . ':' . ($profileOverride ?? '');
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $profileName = $profileOverride !== null && $profileOverride !== ''
            ? $profileOverride
            : (string)($this->settings[$isComment ? 'comment_profile' : 'post_profile'] ?? ($isComment ? 'comment' : 'article'));

        $options = [
            // 0.2 has no profile-free mode; 'none' was full without denials.
            'profile' => in_array($profileName, ['full', 'article', 'comment', 'minimal'], true) ? $profileName : ($profileName === 'none' ? 'full' : 'article'),
            // Authored surfaces may emit raw HTML where the profile allows raw
            // nodes (Full only); wp_kses stays the authoritative gate. Comments
            // never do (upgrade row U-076: rawHtml defaults to false).
            'rawHtml' => !$isComment,
            // Headings keep their ids on the heading element, as in 0.1, so TOC
            // and permalink anchors resolve.
            'sections' => false,
            // 0.2 makes smart typography core and on by default; the plugin
            // setting keeps its 0.1 default (off).
            'smartTypography' => !empty($this->settings['smart_quotes']) && !$isComment,
        ];
        $softBreak = (string)($this->settings[$isComment ? 'comment_soft_break' : 'post_soft_break'] ?? 'newline');
        if (in_array($softBreak, ['space', 'br'], true)) {
            $options['softBreak'] = $softBreak;
        }
        if ($isFeed) {
            $options['mode'] = 'static';
        }
        if (!$isComment && !empty($this->settings['mentions_enabled'])) {
            $options['social'] = [
                'mentionUrl' => self::archiveTemplate('wpcarve_mention_url', 'author'),
                'tagUrl' => self::archiveTemplate('wpcarve_tag_url', 'tag'),
            ];
        }

        $extensions = [];
        $renderers = [];
        if (!$isComment && !empty($this->settings['wikilinks_enabled'])) {
            $extensions = array_merge($extensions, Standard::definitions('carve.wiki') ?? []);
        }
        if ($options['smartTypography'] && is_string($this->settings['smart_quotes_locale'] ?? null) && $this->settings['smart_quotes_locale'] !== '') {
            $extensions[] = SmartQuotes::bundle($this->settings['smart_quotes_locale']);
        }
        if (!$isComment) {
            foreach (Extensions::enabled($this->settings) as $name) {
                if (($isEditor || $forText) && !in_array($name, Extensions::INLINE, true)) {
                    continue;
                }
                [$items, $modes] = Extensions::registration($name);
                $extensions = array_merge($extensions, $items);
                $renderers += $modes;
            }
        }
        if (!empty($this->settings['torchlight_enabled']) && !$isEditor && !$forText && class_exists(TorchlightExtension::class)) {
            $options['codeBlockRenderer'] = self::torchlightRenderer($this->diffBlocks, new TorchlightExtension(
                (string)($this->settings['torchlight_theme'] ?? 'github-light'),
                (bool)($this->settings['torchlight_line_numbers'] ?? false),
                (string)($this->settings['torchlight_theme_dark'] ?? ''),
            ));
        }
        if (!$isComment && !$isEditor && !$forText) {
            if (!empty($this->settings['heading_numbers'])) {
                $extensions[] = Navigation::headingNumbers();
            }
            $enabled = [];
            foreach (Diagrams::all() as $name => $diagram) {
                if (!empty($this->settings[Diagrams::settingKey($name)])) {
                    $enabled[$name] = $diagram;
                }
            }
            if ($enabled !== []) {
                $extensions[] = new DiagramFences($enabled);
            }
        }

        /**
         * Filter the carve-php 0.2 extensions for a context: a list of
         * ExtensionDefinition or ExtensionProvider instances (the `extensions`
         * option). Replaces the 0.1 `wpcarve_converter` action. See docs/hooks.md.
         *
         * @param array<mixed> $extensions
         * @param string $context 'post', 'comment', 'feed', 'editor' or 'text:<context>'.
         */
        $options['extensions'] = array_values((array)apply_filters('wpcarve_extensions', $extensions, $context));
        if ($renderers !== []) {
            $options['renderers'] = $renderers;
        }

        return $this->cache[$cacheKey] = $options;
    }

    /**
     * The engine's `codeBlockRenderer`: Torchlight HTML for a code block, or
     * null to keep the engine's block.
     *
     * @param \ArrayObject<string, true> $diffBlocks
     * @param \WpCarve\Extension\TorchlightExtension $torchlight
     */
    private static function torchlightRenderer(ArrayObject $diffBlocks, TorchlightExtension $torchlight): Closure
    {
        return static function (Node $node, string $html) use ($diffBlocks, $torchlight): ?string {
            $attrs = [];
            $own = $node->f['attrs'] ?? null;
            if ($own instanceof Attrs) {
                if ($own->classes !== []) {
                    $attrs['class'] = implode(' ', $own->classes);
                }
                foreach ($own->pairs as [$name, $value]) {
                    $attrs[$name] = $value;
                }
            }
            if (!isset($attrs['title']) && preg_match('/^<pre\b[^>]*\stitle="([^"]*)"/', $html, $m) === 1) {
                $attrs['title'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            return $torchlight->highlight(
                (string)($node->f['value'] ?? ''),
                (string)($node->f['syntax'] ?? ''),
                $attrs,
                isset($diffBlocks[$node->file . ':' . $node->end]),
            );
        };
    }

    /**
     * Features the 0.1 engine provided as extensions and 0.2 dropped, rebuilt
     * on the rendered HTML: external link attributes, heading permalinks, the
     * injected table of contents and turning mentions off.
     */
    private function presentHostFeatures(string $html, string $context): string
    {
        $s = $this->settings;
        $isComment = $context === 'comment';
        $isEditor = $context === 'editor';
        $isFeed = $context === 'feed';

        // @mention and #tag are core syntax in 0.2 and cannot be denied, so with
        // mentions off the span the engine writes goes back to its text.
        if (empty($s['mentions_enabled'])) {
            $html = (string)preg_replace('/<span class="(?:mention|tag)"><strong>([@#][^<]*)<\/strong><\/span>/', '$1', $html);
        }
        if ($isComment) {
            return $html;
        }
        if (!empty($s['external_links'])) {
            $html = self::markExternalLinks($html, !empty($s['external_links_new_tab']), !empty($s['external_links_nofollow']));
        }
        if ($isEditor) {
            return $html;
        }
        // `::: toc` places a contents list where the author wrote it (the 0.1
        // TocPlacementExtension); not gated on toc_enabled, which controls the
        // injected list below.
        $html = (string)preg_replace_callback(
            '/<div class="toc">\s*<\/div>/',
            static fn (): string => '<nav class="toc"><ul>' . self::tocItems($html, 2, 6) . '</ul></nav>',
            $html,
        );
        if (!empty($s['toc_enabled']) && !$isFeed) {
            $html = self::injectToc(
                $html,
                (int)($s['toc_min_level'] ?? 2),
                (int)($s['toc_max_level'] ?? 4),
                (string)($s['toc_list_type'] ?? 'ul') === 'ol' ? 'ol' : 'ul',
                (string)($s['toc_position'] ?? 'top'),
            );
        }
        // A feed carries no CSS, so a hover-only anchor would show as a bare pilcrow.
        if (!empty($s['permalinks_enabled']) && !$isFeed) {
            $html = (string)preg_replace(
                '/<h([1-6])( id="([^"]+)")([^>]*)>(.*?)<\/h\1>/s',
                '<h$1$2$4>$5<span class="permalink-wrapper"><a class="permalink" href="#$3" aria-label="' . esc_attr__('Permalink', 'carve-markup') . '">¶</a></span></h$1>',
                $html,
            );
        }

        return $html;
    }

    private static function markExternalLinks(string $html, bool $newTab, bool $nofollow): string
    {
        $internal = self::internalHosts();

        return (string)preg_replace_callback(
            '/<a href="(https?:\/\/[^"]+)"([^>]*)>/i',
            static function (array $m) use ($internal, $newTab, $nofollow): string {
                $host = wp_parse_url(html_entity_decode($m[1]), PHP_URL_HOST);
                if (!is_string($host) || in_array(strtolower($host), array_map('strtolower', $internal), true)) {
                    return $m[0];
                }
                $attrs = $m[2];
                $authored = preg_match('/ rel="([^"]*)"/', $attrs, $rm) === 1 ? preg_split('/\s+/', trim($rm[1])) ?: [] : [];
                $rel = array_values(array_unique(array_filter(array_merge($authored, ['noopener', 'noreferrer', $nofollow ? 'nofollow' : '']))));
                $attrs = (string)preg_replace('/ rel="[^"]*"/', '', $attrs) . ' rel="' . implode(' ', $rel) . '"';
                if ($newTab && !str_contains($attrs, ' target=')) {
                    $attrs .= ' target="_blank"';
                }

                return '<a href="' . $m[1] . '"' . $attrs . '>';
            },
            $html,
        );
    }

    /**
     * A collapsible contents list of the headings in a level range, placed at
     * the top or bottom of the content (`none` places nothing).
     */
    private static function tocItems(string $html, int $min, int $max): string
    {
        preg_match_all('/<h([1-6]) id="([^"]+)"[^>]*>(.*?)<\/h\1>/s', $html, $matches, PREG_SET_ORDER);
        $items = '';
        foreach ($matches as $m) {
            $level = (int)$m[1];
            if ($level >= $min && $level <= $max) {
                $items .= '<li class="toc-level-' . $level . '"><a href="#' . $m[2] . '">' . wp_strip_all_tags($m[3]) . '</a></li>';
            }
        }

        return $items;
    }

    private static function injectToc(string $html, int $min, int $max, string $listType, string $position): string
    {
        $items = $position === 'none' ? '' : self::tocItems($html, $min, $max);
        if ($items === '') {
            return $html;
        }
        $toc = '<details class="toc"><summary>' . esc_html__('Table of Contents', 'carve-markup') . '</summary><'
            . $listType . '>' . $items . '</' . $listType . '></details>';

        return $position === 'bottom' ? $html . "\n" . $toc : $toc . "\n" . $html;
    }

    /**
     * The hosts that count as this site, so everything else is "external".
     *
     * A site can be reachable on more than one host - a staging domain, a
     * multisite mapping, a CDN - and only the site knows which. The filter is
     * the answer to that; the default is the one host WordPress is sure of.
     *
     * @return array<int, string>
     */
    private static function internalHosts(): array
    {
        $hosts = [];
        if (function_exists('home_url') && function_exists('wp_parse_url')) {
            $host = wp_parse_url((string)home_url(), PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
        }

        /** @var array<int, string> $filtered */
        $filtered = (array)apply_filters('wpcarve_internal_hosts', $hosts);

        return array_values(array_filter(array_map('strval', $filtered), static fn (string $h): bool => $h !== ''));
    }

    /**
     * A `{name}` URL template for an author or tag archive.
     *
     * Built from `home_url()` rather than `get_author_posts_url()` because the
     * extension needs a TEMPLATE and those functions need a resolved term - and
     * because a mention may name someone who is not a user here. Sites that
     * have moved their author base, or that route mentions somewhere else
     * entirely, replace the whole template through the filter.
     */
    private static function archiveTemplate(string $filter, string $base): string
    {
        $template = '/' . $base . '/{name}/';
        if (function_exists('home_url')) {
            $template = (string)home_url('/' . $base . '/{name}/');
        }

        return (string)apply_filters($filter, $template, $base);
    }
}
