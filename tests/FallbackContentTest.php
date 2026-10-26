<?php

declare(strict_types=1);

// phpcs:disable -- WordPress registry stub uses core method names and a global namespace.

namespace {
    if (!function_exists('serialize_blocks')) {
        function serialize_blocks(array $blocks): string
        {
            $block = $blocks[0];

            return '<!-- wp:carve/markup -->' . $block['innerHTML'] . '<!-- /wp:carve/markup -->';
        }
    }

    if (!function_exists('wp_is_post_autosave')) {
        function wp_is_post_autosave(int $postId): bool
        {
            return false;
        }
    }

    if (!class_exists('WP_Block_Type_Registry')) {
        class WP_Block_Type_Registry
        {
            public static function get_instance(): self
            {
                return new self();
            }

            public function get_registered(string $name): ?object
            {
                if ($name !== 'carve/markup') {
                    return null;
                }
                return (object) ['render_callback' => static fn (array $attrs): string => '<div><h1>' . htmlspecialchars($attrs['carve']) . '</h1></div>'];
            }
        }
    }
}

namespace WpCarve\Test {
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use WpCarve\Blocks\FallbackContent;

    final class FallbackContentTest extends TestCase
    {
        public function testRefreshPostWritesRenderedFallback(): void
        {
            \wpcarve_test_set_post(9941, ['post_content' => '<!-- wp:carve/markup {"carve":"Saved"} /-->']);
            $fallback = new FallbackContent();
            self::assertTrue($fallback->refreshPost(9941));
            self::assertStringContainsString('<h1>Saved</h1>', \get_post(9941)->post_content);
        }

        public function testRefreshesSavedHtmlAndPreservesSource(): void
        {
            $method = new ReflectionMethod(FallbackContent::class, 'refreshBlock');
            $block = [
                'blockName' => 'carve/markup',
                'attrs' => ['carve' => 'New source'],
                'innerBlocks' => [],
                'innerHTML' => '<div>Old render</div>',
                'innerContent' => ['<div>Old render</div>'],
            ];
            $fallback = new FallbackContent();
            self::assertTrue($method->invokeArgs($fallback, [&$block]));
            self::assertSame('<div><h1>New source</h1></div>', $block['innerHTML']);
            self::assertSame([$block['innerHTML']], $block['innerContent']);
            self::assertSame('New source', $block['attrs']['carve']);
            self::assertFalse($method->invokeArgs($fallback, [&$block]));
        }
    }
}
