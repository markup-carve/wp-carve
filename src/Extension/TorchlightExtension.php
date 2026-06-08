<?php

declare(strict_types=1);

namespace WpCarve\Extension;

if (!defined('ABSPATH')) {
    exit;
}

use Carve\CarveConverter;
use Carve\Event\RenderEvent;
use Carve\Extension\ExtensionInterface;
use Carve\Node\Block\CodeBlock;
use Throwable;
use Torchlight\Engine\Engine;

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

    public function __construct(private string $theme = 'github-light')
    {
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

            try {
                $event->setHtml($this->engine->codeToHtml($code, $language, $this->theme));
            } catch (Throwable) {
                // Unknown grammar / theme: leave carve-php's plain output in place.
            }
        });
    }
}
