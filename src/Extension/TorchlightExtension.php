<?php

declare(strict_types=1);

namespace WpCarve\Extension;

if (!defined('ABSPATH')) {
    exit;
}

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Extension\ExtensionInterface;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use Phiki\Theme\Theme;
use Throwable;
use Torchlight\Engine\Engine;
use Torchlight\Engine\Options;

/**
 * Server-side syntax highlighting via Torchlight Engine (parity with wp-djot).
 *
 * Torchlight Engine highlights with TextMate grammars locally -- no API token,
 * no network. Hooks carve-php's `render.code_block` event and replaces each
 * fenced block's `<pre><code>` with themed, highlighted HTML. Opt-in; requires
 * the suggested `torchlight/engine` package (it no-ops if absent).
 */
class TorchlightExtension implements ExtensionInterface
{
    private ?Engine $engine = null;

    /**
     * Cache of base-theme-name => resolved theme name to render with (either a
     * registered carve-tuned variant, or the base name when tuning is skipped).
     *
     * @var array<string, string>
     */
    private array $carveThemes = [];

    public function __construct(
        private string $theme = 'github-light',
        private bool $showLineNumbers = false,
        private string $themeDark = '',
    ) {
        if (class_exists(Engine::class)) {
            $this->engine = new Engine();
            // Register the Carve TextMate grammar so ```carve fences highlight
            // instead of falling back to plaintext. Ships in the
            // markup-carve/carve-grammars Composer package (parity with how
            // wp-djot registers php-collective/djot-grammars).
            $grammarPath = dirname(__DIR__, 2) . '/vendor/markup-carve/carve-grammars/textmate/carve.tmLanguage.json';
            if (file_exists($grammarPath)) {
                $this->engine->getEnvironment()->grammars->register('carve', $grammarPath);
            }
        }
    }

    public function register(CarveConverter $converter): void
    {
        if ($this->engine === null) {
            return;
        }

        $converter->on('render.code_block', function (RenderEvent $event): void {
            $block = $event->getNode();
            if (!$block instanceof CodeBlock || $this->engine === null) {
                return;
            }
            $language = (string)($block->getLanguage() ?: 'text');
            $code = str_replace("\t", '    ', $block->getContent());
            $attrs = $block->getAttributes();
            $gutter = $block->hasClass('line-numbers') || $this->showLineNumbers;
            $start = isset($attrs['data-line-start']) ? (int)$attrs['data-line-start'] : 1;
            $overrides = ['withGutter' => $gutter];
            if ($start !== 1) {
                $overrides['lineNumbersStart'] = $start;
            }

            // Per-block theme override: `{theme=dracula}` on the fence wins over
            // the global setting. An unknown theme throws and falls back below.
            $blockTheme = isset($attrs['theme']) && is_string($attrs['theme']) && $attrs['theme'] !== ''
                ? $attrs['theme']
                : null;
            $light = $blockTheme ?? $this->theme;
            // A configured dark theme renders a second palette in the same
            // markup (phiki emits --phiki-dark-* custom properties); the
            // stylesheet switches on prefers-color-scheme. A per-block
            // {theme=...} override forces that single theme.
            $dark = $blockTheme === null && $this->themeDark !== '' && $this->themeDark !== $light
                ? $this->themeDark
                : null;
            // For carve fences, use a carve-tuned variant of the theme so the
            // inline-markup scopes (bold/italic/highlight/...) render distinctly.
            if ($language === 'carve') {
                $light = $this->carveTheme($light);
                $dark = $dark === null ? null : $this->carveTheme($dark);
            }
            $theme = $dark === null ? $light : ['light' => $light, 'dark' => $dark];

            try {
                $this->engine->setTorchlightOptions(Options::default()->mergeWith($overrides));
                $html = $this->engine->codeToHtml($code, $language, $theme);
                // The engine glues the class attribute onto the preceding quoted
                // style value on highlight/diff/focus lines (`style="..."class='...'`).
                // wp_kses drops such mangled attribute runs entirely, so the line
                // loses its `line line-highlight` classes on render. Re-insert the
                // missing space; code content itself is entity-escaped, so the
                // quote-followed-by-class pattern only occurs at this junction.
                $html = preg_replace('/(["\'])class=/', '$1 class=', $html) ?? $html;
                // The dual-theme style attribute glues variable pairs together
                // (`#e1e4e8--phiki-...`) and doubles semicolons; normalize
                // INSIDE style attributes only (code text may legitimately
                // contain `;;`) so wp_kses keeps the attribute and the
                // browser reads every declaration.
                $html = preg_replace_callback(
                    '/style=([\'"])(.*?)\1/s',
                    static function (array $m): string {
                        $value = preg_replace('/(#[0-9a-fA-F]{3,8})(--)/', '$1;$2', $m[2]) ?? $m[2];
                        $value = preg_replace('/;{2,}/', ';', $value) ?? $value;

                        return 'style=' . $m[1] . $value . $m[1];
                    },
                    $html,
                ) ?? $html;
                $event->setHtml($this->reapplyPreAttributes($html, $block));
            } catch (Throwable) {
                // Unknown grammar / theme: leave carve-php's plain output in place.
            }
        });
    }

    /**
     * Resolve (and lazily register) a carve-tuned variant of a base theme.
     *
     * phiki matches theme rules by exact scope, so a base theme's generic
     * `markup.bold` rule never reaches carve's `markup.bold.carve` tokens - the
     * inline-markup constructs render with no distinct style. This overlays
     * exact-scope rules for the carve markup scopes onto the base theme so a
     * ```carve fence shows strong/emphasis/highlight/etc the way the rendered
     * output does. Degrades to the base theme name if it cannot be loaded/parsed.
     */
    private function carveTheme(string $base): string
    {
        if (isset($this->carveThemes[$base])) {
            return $this->carveThemes[$base];
        }

        $fallback = $this->carveThemes[$base] = $base;
        if ($this->engine === null || !class_exists(Theme::class)) {
            return $fallback;
        }

        $path = dirname(__DIR__, 2) . '/vendor/torchlight/engine/resources/themes/normalized/' . $base . '.json';
        if (!is_file($path)) {
            return $fallback;
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            return $fallback;
        }

        // Give each inline-markup scope a distinct foreground so it stands out
        // in the fence. Torchlight/phiki emits `color` reliably but drops
        // `fontStyle`, so bold/italic/etc are distinguished by hue (plus a
        // background for highlight, mirroring <mark>), not by weight/slant.
        // Two palettes: the overlay must match the BASE THEME'S brightness -
        // GitHub-light hues on github-dark's #24292e background are unreadable
        // (that exact mix shipped once). The normalized theme json's `type`
        // field is unreliable (torchlight normalizes every theme to "dark"),
        // so brightness is derived from the editor background's luminance.
        $dark = $this->isDarkBackground((string)($data['colors']['editor.background'] ?? ''), $base);
        $overlay = $dark ? [
            ['scope' => ['markup.bold.carve', 'punctuation.definition.bold.carve'], 'settings' => ['foreground' => '#ffab70']],
            ['scope' => ['markup.italic.carve', 'punctuation.definition.italic.carve'], 'settings' => ['foreground' => '#79b8ff']],
            ['scope' => ['markup.underline.text.carve', 'punctuation.definition.underline.carve'], 'settings' => ['foreground' => '#56d4dd']],
            ['scope' => ['markup.strikethrough.carve', 'punctuation.definition.strike.carve'], 'settings' => ['foreground' => '#959da5']],
            ['scope' => ['markup.highlight.carve', 'punctuation.definition.highlight.carve'], 'settings' => ['foreground' => '#ffd33d', 'background' => '#3a2d00']],
            ['scope' => ['markup.superscript.carve', 'markup.subscript.carve'], 'settings' => ['foreground' => '#b392f0']],
            ['scope' => ['markup.raw.inline.carve', 'punctuation.definition.raw.carve'], 'settings' => ['foreground' => '#79b8ff', 'background' => '#2f363d']],
            ['scope' => ['string.other.link.title.carve', 'markup.underline.link.carve', 'punctuation.definition.link.carve'], 'settings' => ['foreground' => '#79b8ff']],
            ['scope' => ['punctuation.definition.list.unnumbered.carve', 'punctuation.definition.list.numbered.carve', 'punctuation.definition.list.carve', 'punctuation.definition.checkbox.carve', 'constant.language.checkbox.carve'], 'settings' => ['foreground' => '#85e89d']],
            ['scope' => ['punctuation.definition.list.continuation.carve', 'keyword.operator.table.continuation.carve'], 'settings' => ['foreground' => '#79b8ff']],
            ['scope' => ['punctuation.separator.table.carve'], 'settings' => ['foreground' => '#6a737d']],
            ['scope' => ['keyword.operator.table.header.carve', 'keyword.operator.table.alignment.carve', 'keyword.operator.table.rowspan.carve', 'keyword.operator.table.colspan.carve'], 'settings' => ['foreground' => '#f97583']],
            ['scope' => ['markup.bold.italic.carve', 'punctuation.definition.bold-italic.carve'], 'settings' => ['foreground' => '#ffab70']],
            ['scope' => ['punctuation.definition.superscript.carve', 'punctuation.definition.subscript.carve'], 'settings' => ['foreground' => '#6a737d']],
            ['scope' => ['markup.math.carve', 'punctuation.definition.math.carve'], 'settings' => ['foreground' => '#b392f0']],
            ['scope' => ['variable.other.mention.carve', 'punctuation.definition.mention.carve'], 'settings' => ['foreground' => '#f97583']],
            ['scope' => ['variable.other.tag.carve', 'punctuation.definition.tag.carve'], 'settings' => ['foreground' => '#85e89d']],
            ['scope' => ['meta.attributes.carve', 'punctuation.definition.attributes.carve'], 'settings' => ['foreground' => '#ffab70']],
            ['scope' => ['markup.caption.carve'], 'settings' => ['foreground' => '#959da5']],
            ['scope' => ['punctuation.definition.caption.carve'], 'settings' => ['foreground' => '#ffab70']],
            ['scope' => ['fenced_code.block.language.carve'], 'settings' => ['foreground' => '#85e89d']],
            ['scope' => ['punctuation.definition.fenced.carve', 'punctuation.definition.admonition.carve'], 'settings' => ['foreground' => '#f97583']],
            ['scope' => ['markup.heading.carve', 'punctuation.definition.heading.carve'], 'settings' => ['foreground' => '#79b8ff']],
            ['scope' => ['constant.other.footnote.carve', 'punctuation.definition.footnote.carve'], 'settings' => ['foreground' => '#79b8ff']],
            ['scope' => ['punctuation.definition.image.carve'], 'settings' => ['foreground' => '#f97583']],
            ['scope' => ['markup.underline.link.image.carve'], 'settings' => ['foreground' => '#9ecbff']],
            ['scope' => ['entity.name.section.carve'], 'settings' => ['foreground' => '#79b8ff']],
        ] : [
            ['scope' => ['markup.bold.carve', 'punctuation.definition.bold.carve'], 'settings' => ['foreground' => '#953800']],
            ['scope' => ['markup.italic.carve', 'punctuation.definition.italic.carve'], 'settings' => ['foreground' => '#0550ae']],
            ['scope' => ['markup.underline.text.carve', 'punctuation.definition.underline.carve'], 'settings' => ['foreground' => '#0a6c74']],
            ['scope' => ['markup.strikethrough.carve', 'punctuation.definition.strike.carve'], 'settings' => ['foreground' => '#6a737d']],
            ['scope' => ['markup.highlight.carve', 'punctuation.definition.highlight.carve'], 'settings' => ['foreground' => '#9a6700', 'background' => '#fff8c5']],
            ['scope' => ['markup.superscript.carve', 'markup.subscript.carve'], 'settings' => ['foreground' => '#6f42c1']],
            // Inline code chip + links, so they read as distinctly as the marks.
            ['scope' => ['markup.raw.inline.carve', 'punctuation.definition.raw.carve'], 'settings' => ['foreground' => '#0a3069', 'background' => '#eff1f3']],
            ['scope' => ['string.other.link.title.carve', 'markup.underline.link.carve', 'punctuation.definition.link.carve'], 'settings' => ['foreground' => '#0969da']],
            // List / task markers (green).
            ['scope' => ['punctuation.definition.list.unnumbered.carve', 'punctuation.definition.list.numbered.carve', 'punctuation.definition.list.carve', 'punctuation.definition.checkbox.carve', 'constant.language.checkbox.carve'], 'settings' => ['foreground' => '#116329']],
            // Continuation marker (+), lone or with text, in bright blue.
            ['scope' => ['punctuation.definition.list.continuation.carve', 'keyword.operator.table.continuation.carve'], 'settings' => ['foreground' => '#0969da']],
            // Tables: pipes in muted gray, structural operators (header |=,
            // alignment, rowspan ^, colspan <) in a keyword red.
            ['scope' => ['punctuation.separator.table.carve'], 'settings' => ['foreground' => '#6a737d']],
            ['scope' => ['keyword.operator.table.header.carve', 'keyword.operator.table.alignment.carve', 'keyword.operator.table.rowspan.carve', 'keyword.operator.table.colspan.carve'], 'settings' => ['foreground' => '#cf222e']],
            ['scope' => ['markup.bold.italic.carve', 'punctuation.definition.bold-italic.carve'], 'settings' => ['foreground' => '#953800']],
            ['scope' => ['punctuation.definition.superscript.carve', 'punctuation.definition.subscript.carve'], 'settings' => ['foreground' => '#959da5']],
            ['scope' => ['markup.math.carve', 'punctuation.definition.math.carve'], 'settings' => ['foreground' => '#6f42c1']],
            ['scope' => ['variable.other.mention.carve', 'punctuation.definition.mention.carve'], 'settings' => ['foreground' => '#d73a49']],
            ['scope' => ['variable.other.tag.carve', 'punctuation.definition.tag.carve'], 'settings' => ['foreground' => '#22863a']],
            ['scope' => ['meta.attributes.carve', 'punctuation.definition.attributes.carve'], 'settings' => ['foreground' => '#e36209']],
            ['scope' => ['markup.caption.carve'], 'settings' => ['foreground' => '#6a737d']],
            ['scope' => ['punctuation.definition.caption.carve'], 'settings' => ['foreground' => '#e36209']],
            ['scope' => ['fenced_code.block.language.carve'], 'settings' => ['foreground' => '#22863a']],
            ['scope' => ['punctuation.definition.fenced.carve', 'punctuation.definition.admonition.carve'], 'settings' => ['foreground' => '#d73a49']],
            ['scope' => ['markup.heading.carve', 'punctuation.definition.heading.carve'], 'settings' => ['foreground' => '#005cc5']],
            ['scope' => ['constant.other.footnote.carve', 'punctuation.definition.footnote.carve'], 'settings' => ['foreground' => '#005cc5']],
            ['scope' => ['punctuation.definition.image.carve'], 'settings' => ['foreground' => '#d73a49']],
            ['scope' => ['markup.underline.link.image.carve'], 'settings' => ['foreground' => '#032f62']],
            ['scope' => ['entity.name.section.carve'], 'settings' => ['foreground' => '#005cc5']],
        ];
        $data['tokenColors'] = array_merge($data['tokenColors'] ?? [], $overlay);
        $name = $base . '-carve';

        try {
            $this->engine->getEnvironment()->themes->register($name, Theme::parse($data));
        } catch (Throwable) {
            return $fallback;
        }

        return $this->carveThemes[$base] = $name;
    }

    /**
     * A theme counts as dark when its editor background is dark. Relative
     * luminance over the hex background (3- or 6-digit); when the theme
     * carries no usable background, the theme NAME is the fallback signal.
     */
    private function isDarkBackground(string $hex, string $base): bool
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return str_contains($base, 'dark') || str_contains($base, 'night') || str_contains($base, 'black');
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255 < 0.5;
    }

    private function reapplyPreAttributes(string $html, CodeBlock $block): string
    {
        $attrs = $block->getAttributes();
        $extraClasses = array_values(array_filter(
            $block->getClassList(),
            static fn (string $class): bool => $class !== 'line-numbers',
        ));
        $extraAttrs = [];

        if (isset($attrs['title']) && is_scalar($attrs['title'])) {
            $extraAttrs['data-title'] = (string)$attrs['title'];
        }

        // Expose the language on the <pre> (the engine only sets it on the
        // scrolling <code>) so the stylesheet can pin a language pill.
        $language = (string)($block->getLanguage() ?? '');
        if ($language !== '') {
            $extraAttrs['data-lang'] = $language;
        }

        foreach ($attrs as $name => $value) {
            if (
                is_string($name)
                && preg_match('/^data-[a-zA-Z0-9_.:-]+$/', $name) === 1
                && is_scalar($value)
            ) {
                $extraAttrs[$name] = (string)$value;
            }
        }

        $replaced = preg_replace_callback('/^<pre\b([^>]*)>/', function (array $matches) use ($extraClasses, $extraAttrs): string {
            $preAttrs = (string)$matches[1];
            if ($extraClasses !== []) {
                $preAttrs = $this->mergeClassAttribute($preAttrs, $extraClasses);
            }

            foreach ($extraAttrs as $name => $value) {
                $preAttrs .= sprintf(' %s="%s"', $name, $this->escapeAttribute($value));
            }

            return '<pre' . $preAttrs . '>';
        }, $html, 1);

        return is_string($replaced) ? $replaced : $html;
    }

    /**
     * @param string $attrs
     * @param list<string> $extraClasses
     */
    private function mergeClassAttribute(string $attrs, array $extraClasses): string
    {
        if (preg_match('/\sclass=(["\'])(.*?)\1/', $attrs, $matches) !== 1) {
            return $attrs . ' class="' . $this->escapeAttribute(implode(' ', $extraClasses)) . '"';
        }

        $classes = preg_split('/\s+/', trim((string)$matches[2])) ?: [];
        $classes = array_values(array_unique(array_filter(array_merge($classes, $extraClasses))));
        $classAttr = ' class=' . $matches[1] . $this->escapeAttribute(implode(' ', $classes)) . $matches[1];

        return preg_replace('/\sclass=(["\'])(.*?)\1/', $classAttr, $attrs, 1) ?? $attrs;
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
