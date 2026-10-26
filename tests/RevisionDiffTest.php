<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use WpCarve\Admin\RevisionDiff;

class RevisionDiffTest extends TestCase
{
    public function testCarveSourceIsDecodedWhileOtherBlocksStayUntouched(): void
    {
        $source = "## Status\n\n- [/] Review\n- [>] Next\n\n::: tip \"Keep the source\"\nText\n:::";
        $paragraph = '<!-- wp:paragraph --><p>Ordinary content</p><!-- /wp:paragraph -->';
        $carve = '<!-- wp:carve/markup ' . wp_json_encode(['carve' => $source], JSON_HEX_QUOT | JSON_HEX_TAG) . ' /-->';

        $prepared = (new RevisionDiff())->prepareContent($paragraph . "\n" . $carve);

        self::assertSame([$source], $prepared['sources']);
        self::assertStringStartsWith($paragraph . "\n", $prepared['content']);
        self::assertStringContainsString('[Carve source shown below]', $prepared['content']);
        self::assertStringNotContainsString('Review', $prepared['content']);
    }

    public function testMultipleCarveBlocksRemainInDocumentOrder(): void
    {
        $content = '<!-- wp:carve/markup {"carve":"First"} /-->'
            . '<!-- wp:paragraph --><p>Between</p><!-- /wp:paragraph -->'
            . '<!-- wp:carve/slides {"carve":"Second"} /-->';

        $prepared = (new RevisionDiff())->prepareContent($content);

        self::assertSame(['First', 'Second'], $prepared['sources']);
        self::assertStringContainsString('<p>Between</p>', $prepared['content']);
        self::assertSame(2, substr_count($prepared['content'], '[Carve source shown below]'));
    }

    public function testOrdinaryPostContentIsUntouched(): void
    {
        $content = '<!-- wp:paragraph {"dropCap":false} --><p>Hello</p><!-- /wp:paragraph -->';

        self::assertSame(
            ['content' => $content, 'sources' => []],
            (new RevisionDiff())->prepareContent($content),
        );
    }

    public function testInsertedBlockDoesNotShiftLaterComparison(): void
    {
        self::assertSame(
            [['First', 'First'], ['', 'Inserted'], ['Last', 'Last']],
            (new RevisionDiff())->sourcePairs(['First', 'Last'], ['First', 'Inserted', 'Last']),
        );
    }

    public function testChangedBlockKeepsOneLineDiff(): void
    {
        self::assertSame(
            [['Before', 'After']],
            (new RevisionDiff())->sourcePairs(['Before'], ['After']),
        );
    }

    public function testOtherAttributesRemainReadable(): void
    {
        $content = '<!-- wp:carve/markup {"carve":"Text","label":"Größe a/b"} /-->';
        $prepared = (new RevisionDiff())->prepareContent($content);

        self::assertStringContainsString('Größe a/b', $prepared['content']);
    }
}
