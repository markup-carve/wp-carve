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

    public function testDefaultThemesUseSharedCarveMarkerColors(): void
    {
        $html = $this->render("````carve\n:: Term\n: Description\n\n- [x] Done\n\n```php\n<?php echo 1;\n```\n````", 'github-light', 'github-dark');

        $this->assertStringContainsString('#735c0f', $html);
        $this->assertStringContainsString('--phiki-dark-color: #f2cc60', $html);
        $this->assertStringContainsString('style="color: #6f42c1;--phiki-dark-color: #d2a8ff;">::</span>', $html);
    }

    public function testSingleThemeStaysSingle(): void
    {
        $html = $this->render("```carve\n*bold*\n```", 'github-light');

        $this->assertStringNotContainsString('phiki-themes', $html);
        $this->assertStringNotContainsString('--phiki-dark-color', $html);
    }

    public function testCsvFenceScopesTheSeparator(): void
    {
        $html = $this->render("```csv\nname,qty\n\"a, b\",2\n```", 'github-light');

        $this->assertSame(2, substr_count($html, '<span class="token" style="color: #d73a49;">,</span>'), 'each separator is its own operator-colored token');
        $this->assertStringContainsString('<span class="token" style="color: #032f62;">&quot;a, b&quot;</span>', $html, 'a quoted field keeps its comma');
    }

    public function testTsvFenceScopesTheSeparator(): void
    {
        $html = $this->render("```tsv\nname\tqty\n```", 'github-light');

        $this->assertStringContainsString("<span class=\"token\" style=\"color: #d73a49;\">\t</span>", $html, 'the tab separator is its own operator-colored token');
    }

    public function testCsvFenceInsideCarveFenceIsHighlighted(): void
    {
        $html = $this->render("````carve\n```csv\nname,qty\n```\n````", 'github-light');

        $this->assertStringContainsString('<span class="token" style="color: #d73a49;">,</span>', $html);
    }

    public function testAdjacentFencesInASectionKeepTheirCodeLinesUnindented(): void
    {
        $html = $this->render("## Usage\n\n```php\n\$x = 1;\n```\n\n```php\n\$y = 2;\nif (\$a) {}\n```\n", 'github-light');

        $this->assertStringNotContainsString('</pre><pre', $html, 'each highlighted block starts on its own line');
        $this->assertDoesNotMatchRegularExpression('/^ +<\/span><\/div>/m', $html, 'no code line carries the section indentation');
    }
}
