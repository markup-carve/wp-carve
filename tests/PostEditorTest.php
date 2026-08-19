<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use WpCarve\Admin\PostEditor;
use WpCarve\Converter;

class PostEditorTest extends TestCase
{
    private PostEditor $editor;

    protected function setUp(): void
    {
        $this->editor = new PostEditor(new Converter([]));
    }

    public function testSingleCarveBlockCanReturnToDocumentLosslessly(): void
    {
        $source = "# Exact\n\nA \\ backslash and --> terminator.";
        $serialized = '<!-- wp:carve/markup ' . wp_json_encode(['carve' => $source]) . ' /-->';
        wpcarve_test_set_post(81, ['post_content' => $serialized]);

        self::assertSame($source, $this->editor->singleCarveBlockSource(get_post(81)));
    }

    public function testMultipleBlocksRefuseDocumentConversion(): void
    {
        $serialized = '<!-- wp:carve/markup {"carve":"one"} /-->'
            . '<!-- wp:carve/markup {"carve":"two"} /-->';
        wpcarve_test_set_post(82, ['post_content' => $serialized]);

        self::assertNull($this->editor->singleCarveBlockSource(get_post(82)));
    }

    public function testNonCarveBlockRefusesDocumentConversion(): void
    {
        wpcarve_test_set_post(83, ['post_content' => '<!-- wp:paragraph {"dropCap":false} /-->']);

        self::assertNull($this->editor->singleCarveBlockSource(get_post(83)));
    }
}
