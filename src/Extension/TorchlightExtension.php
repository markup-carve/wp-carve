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
            $theme = isset($attrs['theme']) && is_string($attrs['theme']) && $attrs['theme'] !== ''
                ? $attrs['theme']
                : $this->theme;
            // For carve fences, use a carve-tuned variant of the theme so the
            // inline-markup scopes (bold/italic/highlight/...) render distinctly.
            if ($language === 'carve') {
                $theme = $this->carveTheme($theme);
            }

            try {
                $this->engine->setTorchlightOptions(Options::default()->mergeWith($overrides));
                $html = $this->engine->codeToHtml($code, $language, $theme);
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
        $overlay = [
            ['scope' => ['markup.bold.carve', 'punctuation.definition.bold.carve'], 'settings' => ['foreground' => '#953800']],
            ['scope' => ['markup.italic.carve', 'punctuation.definition.italic.carve'], 'settings' => ['foreground' => '#0550ae']],
            ['scope' => ['markup.underline.carve', 'punctuation.definition.underline.carve'], 'settings' => ['foreground' => '#0a6c74']],
            ['scope' => ['markup.strikethrough.carve', 'punctuation.definition.strike.carve'], 'settings' => ['foreground' => '#6a737d']],
            ['scope' => ['markup.highlight.carve', 'punctuation.definition.highlight.carve'], 'settings' => ['foreground' => '#24292e', 'background' => '#fff8c5']],
            ['scope' => ['markup.superscript.carve', 'markup.subscript.carve'], 'settings' => ['foreground' => '#6f42c1']],
            // Inline code chip + links, so they read as distinctly as the marks.
            ['scope' => ['markup.raw.inline.carve', 'punctuation.definition.raw.carve'], 'settings' => ['foreground' => '#0a3069', 'background' => '#eff1f3']],
            ['scope' => ['string.other.link.title.carve', 'markup.underline.link.carve', 'punctuation.definition.link.carve'], 'settings' => ['foreground' => '#0969da']],
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
