<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use WpCarve\Blocks\DeactivationConverter;

final class DeactivationConverterTest extends TestCase
{
    public function testConvertsNestedCarveBlocksAndCollectsEachSource(): void
    {
        $blocks = [
            [
                'blockName' => 'core/group',
                'attrs' => [],
                'innerBlocks' => [
                    ['blockName' => 'carve/markup', 'attrs' => ['carve' => '# One'], 'innerBlocks' => []],
                    ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<p>Keep</p>'],
                    ['blockName' => 'carve/slides', 'attrs' => ['carve' => '# Two'], 'innerBlocks' => []],
                ],
                'innerHTML' => '',
                'innerContent' => [null, null, null],
            ],
        ];
        $sources = [];
        $converted = (new DeactivationConverter())->convertBlocks(
            $blocks,
            static fn (array $block): string => '<p>' . $block['attrs']['carve'] . '</p>',
            $sources,
        );
        self::assertSame(['# One', '# Two'], $sources);
        self::assertSame('core/html', $converted[0]['innerBlocks'][0]['blockName']);
        self::assertSame(['<p># One</p>'], $converted[0]['innerBlocks'][0]['innerContent']);
        self::assertSame('core/paragraph', $converted[0]['innerBlocks'][1]['blockName']);
        self::assertSame('core/html', $converted[0]['innerBlocks'][2]['blockName']);
    }
}
