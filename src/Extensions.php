<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

use MarkupCarve\Carve\Extension\CodeGroup;
use MarkupCarve\Carve\Extension\Details;
use MarkupCarve\Carve\Extension\ImgFence;
use MarkupCarve\Carve\Extension\ListTable;
use MarkupCarve\Carve\Extension\SemanticSpan;
use MarkupCarve\Carve\Extension\Spoiler;
use MarkupCarve\Carve\Extension\Tabs;

/**
 * The Carve content extensions the plugin registers, by name.
 *
 * The server render and the in-browser preview both read the enabled names
 * from here: engine.js maps each name to the carve-js bundle of the same name.
 */
final class Extensions
{
    /**
     * Name => [label, fence or directive shown in the settings card].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const CATALOG = [
        'tabs' => ['Tabs', '::: tabs'],
        'details' => ['Details', '::: details'],
        'spoiler' => ['Spoiler', '::: spoiler'],
        'codeGroup' => ['Code group', '::: code-group'],
        'listTable' => ['List table', '::: list-table'],
        'imgFence' => ['SVG image fence', '```img'],
        'semanticSpan' => ['Semantic spans', '[Ctrl]{kbd} [HTML]{abbr="..."}'],
    ];

    /**
     * Inline extensions that also apply in the editor and plain-text contexts.
     *
     * @var array<int, string>
     */
    public const INLINE = ['semanticSpan'];

    public static function settingKey(string $name): string
    {
        return 'extension_' . strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    /**
     * The enabled extension names.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<int, string>
     */
    public static function enabled(array $settings): array
    {
        return array_values(array_filter(
            array_keys(self::CATALOG),
            static fn (string $name): bool => !empty($settings[self::settingKey($name)] ?? true),
        ));
    }

    /**
     * The carve-php `extensions` items and the renderer modes of one name.
     *
     * @return array{0: array<mixed>, 1: array<string, string>}
     */
    public static function registration(string $name): array
    {
        return match ($name) {
            // Tabs render as the standard radio widget the 0.1 TabsExtension wrote.
            'tabs' => [Tabs::extensions(true), []],
            'details' => [[Details::bundle()], []],
            'spoiler' => [[Spoiler::bundle()], []],
            'codeGroup' => [[CodeGroup::bundle()], []],
            'listTable' => [[ListTable::bundle()], []],
            // The sanitized SVG becomes a data:image/svg+xml <img>.
            'imgFence' => [[ImgFence::definition('img')], ['carve.imgFence.img' => 'standard']],
            'semanticSpan' => [[SemanticSpan::bundle()], []],
            default => [[], []],
        };
    }
}
