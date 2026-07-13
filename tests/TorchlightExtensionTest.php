<?php

declare(strict_types=1);

namespace WpCarve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;
use WpCarve\Extension\TorchlightExtension;

class TorchlightExtensionTest extends TestCase
{
    private function render(string $carve, string $theme, string $dark = ''): string
    {
        $converter = new CarveConverter();
        $converter->addExtension(new TorchlightExtension(theme: $theme, showLineNumbers: false, themeDark: $dark));

        return $converter->convert($carve);
    }

    public function testCarveOverlayUsesDarkPaletteOnDarkBaseTheme(): void
    {
        $html = $this->render("```carve\n*bold*\n```", 'github-dark');

        // GitHub-dark accents, not the light palette (which is unreadable on
        // the #24292e background - the exact mix that shipped once).
        $this->assertStringContainsString('#ffab70', $html);
        $this->assertStringNotContainsString('#953800', $html);
    }

    public function testCarveOverlayUsesLightPaletteOnLightBaseTheme(): void
    {
        $html = $this->render("```carve\n*bold*\n```", 'github-light');

        $this->assertStringContainsString('#953800', $html);
        $this->assertStringNotContainsString('#ffab70', $html);
    }

    public function testClosingDelimiterKeepsItsColor(): void
    {
        // phiki recovers capture offsets with a first-occurrence search, so a
        // closing delimiter identical to the opening one lost its scope (and
        // color) - scripts/patch-phiki-offsets.php fixes the vendored copy.
        $html = $this->render("```carve\n*bold*\n```", 'github-light');

        $spans = substr_count($html, '>*</span>');
        $this->assertSame(2, $spans, 'both asterisks must be their own styled token');
        $this->assertSame(3, substr_count($html, '#953800'), 'opening delimiter, content, and closing delimiter each carry the bold hue');
    }

    public function testUnderlineContentIsStyled(): void
    {
        // The overlay previously styled markup.underline.carve while the
        // grammar emits markup.underline.text.carve - content lost its color.
        $html = $this->render("```carve\n_under_\n```", 'github-light');

        $this->assertStringContainsString('#0a6c74', $html);
    }

    public function testDualThemeEmitsDarkVariables(): void
    {
        $html = $this->render("```carve\n*bold*\n```", 'github-light', 'github-dark');

        $this->assertStringContainsString('phiki-themes', $html);
        $this->assertStringContainsString('--phiki-dark-color: #ffab70', $html);
        $this->assertStringContainsString('#953800', $html);
        // Well-formed style attributes: no glued variable pairs, no doubled
        // semicolons (wp_kses drops mangled attribute runs wholesale).
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}--/', $html);
        $this->assertStringNotContainsString(';;', $html);
    }

    public function testSingleThemeStaysSingle(): void
    {
        $html = $this->render("```carve\n*bold*\n```", 'github-light');

        $this->assertStringNotContainsString('phiki-themes', $html);
        $this->assertStringNotContainsString('--phiki-dark-color', $html);
    }
}
