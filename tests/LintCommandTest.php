<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WpCarve\CLI\LintCommand;
use WpCarve\Converter;

#[UsesClass(LintCommand::class)]
#[UsesClass(Converter::class)]
class LintCommandTest extends TestCase
{
    public function testCleanSourceHasNoEngineFindings(): void
    {
        $this->assertSame([], LintCommand::engineFindings(new Converter([]), ["# Title\n\nA paragraph with *strong* text.\n"])['findings']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function engineRuleProvider(): array
    {
        return [
            'description separator below its column' => [
                "- item\n\n  :: Term\n  :  Def\n :  under\n",
                '5:2 description-separator-below-content-column ',
            ],
            'quote marker without a space' => [
                "- item\n>+\n> quoted\n",
                '2:1 blockquote-marker-without-space ',
            ],
            'Markdown double-star strong' => [
                "This is **bold** text.\n",
                '1:9 markdown-strong-double-star ',
            ],
            'unresolved reference link' => [
                "See [the docs][missing].\n",
                '1:5 unresolved-reference-link ',
            ],
        ];
    }

    #[DataProvider('engineRuleProvider')]
    public function testReportsTheEngineRuleAtItsPosition(string $source, string $prefix): void
    {
        $findings = LintCommand::engineFindings(new Converter([]), [$source])['findings'];

        $this->assertCount(1, $findings);
        $this->assertStringStartsWith($prefix, $findings[0]);
    }

    public function testLabelsEachSourceWhenAPostHoldsSeveral(): void
    {
        $findings = LintCommand::engineFindings(new Converter([]), [
            "Clean text.\n",
            "This is **bold** text.\n",
        ])['findings'];

        $this->assertCount(1, $findings);
        $this->assertStringStartsWith('source 2 1:9 markdown-strong-double-star ', $findings[0]);
    }

    public function testCitationsWithAnInDocumentDefinitionStayClean(): void
    {
        $converter = new Converter([]);

        $this->assertSame([], LintCommand::engineFindings($converter, ["As shown [@doe2020].\n\n[@doe2020]: Doe, 2020.\n"])['findings']);
    }

    public function testALinterThatThrowsIsAFailureNotAFinding(): void
    {
        $converter = new class ([]) extends Converter {
            public function lint(string $carve, string $context = 'post'): array
            {
                throw new RuntimeException('boom');
            }
        };

        $this->assertSame(
            ['findings' => [], 'failures' => ['lint threw: boom']],
            LintCommand::engineFindings($converter, ["Text.\n"]),
        );
    }

    public function testMediaEmbedDirectivesAreLintedOnlyWhenTheExtensionRenders(): void
    {
        $source = "Watch :media[]\n";

        $this->assertSame([], LintCommand::engineFindings(new Converter([]), [$source])['findings']);

        $findings = LintCommand::engineFindings(new Converter(['media_embed_enabled' => true]), [$source])['findings'];
        $this->assertCount(1, $findings);
        $this->assertStringStartsWith('1:7 media-embed-unresolved ', $findings[0]);
    }

    public function testEmptySourceIsNotLinted(): void
    {
        $this->assertSame([], (new Converter([]))->lint("  \n"));
    }
}
