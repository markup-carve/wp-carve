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

    public function __construct(
        private string $theme = 'github-light',
        private bool $showLineNumbers = false,
    ) {
        if (class_exists(Engine::class)) {
            $this->engine = new Engine();
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

            try {
                $this->engine->setTorchlightOptions(Options::default()->mergeWith($overrides));
                $html = $this->engine->codeToHtml($code, $language, $this->theme);
                $event->setHtml($this->reapplyPreAttributes($html, $block));
            } catch (Throwable) {
                // Unknown grammar / theme: leave carve-php's plain output in place.
            }
        });
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
